package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"net"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"syscall"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

// XCSettings holds the relevant XC_VM settings read from the DB.
type XCSettings struct {
	ClientPrebuffer     int
	RestreamerPrebuffer int
	SegTime             int
	CreateExpiration    int
	Disallow2ndIPCon    int
	Disallow2ndIPMax    int
	RestrictSameIP      int
	IPSubnetMatch       int
	OnDemandInstantOff  int
	OnDemandWaitTime    int
	UseBuffer           int
	SegmentWaitTime     int
	ReadBufferSize      int
	RedisHandler        int // 0=MySQL only, 1=Redis+MySQL
	RedisPassword       string
	LiveStreamingPass   string // token encryption password
}

// LoadSettings reads the XC_VM settings from the DB.
func LoadSettings(db *sql.DB) (*XCSettings, error) {
	s := &XCSettings{}
	err := db.QueryRow(
		"SELECT `client_prebuffer`, `restreamer_prebuffer`, `seg_time`, `create_expiration`, "+
			"IFNULL(`disallow_2nd_ip_con`, 0), IFNULL(`disallow_2nd_ip_max`, 0), "+
			"IFNULL(`restrict_same_ip`, 0), IFNULL(`ip_subnet_match`, 0), "+
			"IFNULL(`on_demand_instant_off`, 0), IFNULL(`on_demand_wait_time`, 60), "+
			"IFNULL(`use_buffer`, 1), "+
			"IFNULL(`segment_wait_time`, 20), IFNULL(`read_buffer_size`, 8192), "+
			"IFNULL(`redis_handler`, 0), IFNULL(`redis_password`, ''), "+
			"IFNULL(`live_streaming_pass`, '') "+
			"FROM `settings` LIMIT 1",
	).Scan(&s.ClientPrebuffer, &s.RestreamerPrebuffer, &s.SegTime, &s.CreateExpiration,
		&s.Disallow2ndIPCon, &s.Disallow2ndIPMax, &s.RestrictSameIP,
		&s.IPSubnetMatch, &s.OnDemandInstantOff, &s.OnDemandWaitTime, &s.UseBuffer,
		&s.SegmentWaitTime, &s.ReadBufferSize,
		&s.RedisHandler, &s.RedisPassword, &s.LiveStreamingPass)
	if err != nil {
		return nil, err
	}
	return s, nil
}

// LiveConnection represents a row to insert into lines_live.
type LiveConnection struct {
	UserID           int
	StreamID         int
	ServerID         int
	ProxyID          int
	UserAgent        string
	UserIP           string
	Container        string
	PID              int
	DateStart        int64
	GeoIPCountryCode string
	ISP              string
	ExternalDevice   string
	HLSLastRead      int64
	UUID             string
	HMACID           *int
	HMACIdentifier   string
	OnDemand         int // 0 or 1, from ChannelInfo
}

// Tracker maintains viewer heartbeats in the XC_VM lines_live table.
type Tracker struct {
	db             *sql.DB
	redis          *RedisTracker // nil when redis_handler=0
	mu             sync.Mutex
	viewers        map[string]*TrackedViewer // uuid -> viewer
	timeOffset     int
	interval       time.Duration
	stopCh         chan struct{}
	ipSubnetMatch  int    // 0=exact IP, 1=subnet match for disallow_2nd_ip
	activityLogDir string // directory for offline activity logs (empty=disabled)
	consTmpPath    string // path to connection touch files
	serverID       int    // this server's XC_VM server_id (for enforcement signaling)
}

type TrackedViewer struct {
	UUID      string
	StreamID  int
	StartTime time.Time
	LastBeat  time.Time
	Stopped   bool
	StopCh    chan struct{} // closed when external disconnect detected
}

func NewTracker(dsn string, timeOffset int, interval time.Duration) (*Tracker, error) {
	// Ensure clientFoundRows=true so heartbeat distinguishes
	// "no row matched" from "row matched but no value changed".
	if !strings.Contains(dsn, "clientFoundRows") {
		if strings.Contains(dsn, "?") {
			dsn += "&clientFoundRows=true"
		} else {
			dsn += "?clientFoundRows=true"
		}
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(10)
	db.SetMaxIdleConns(3)
	db.SetConnMaxLifetime(5 * time.Minute)

	if err := db.Ping(); err != nil {
		db.Close()
		return nil, err
	}

	t := &Tracker{
		db:         db,
		viewers:    make(map[string]*TrackedViewer),
		timeOffset: timeOffset,
		interval:   interval,
		stopCh:     make(chan struct{}),
	}
	go t.heartbeatLoop()
	return t, nil
}

// AttachRedis connects a RedisTracker to this Tracker for dual-mode operation.
func (t *Tracker) AttachRedis(rt *RedisTracker) {
	t.redis = rt
	log.Println("tracker: Redis attached (dual-mode: Redis+MariaDB)")
}

// SetIPSubnetMatch enables/disables subnet matching for IP comparisons.
func (t *Tracker) SetIPSubnetMatch(v int) {
	t.ipSubnetMatch = v
}

// SetActivityLogDir sets the directory for offline activity logging.
func (t *Tracker) SetActivityLogDir(dir string) {
	t.activityLogDir = dir
}

// SetConsTmpPath sets the path for connection touch files.
func (t *Tracker) SetConsTmpPath(path string) {
	t.consTmpPath = path
}

// SetServerID sets this server's XC_VM server_id for enforcement signaling.
func (t *Tracker) SetServerID(id int) {
	t.serverID = id
}

// RedisEnabled returns true if Redis tracking is active.
func (t *Tracker) RedisEnabled() bool { return t.redis != nil }

// GetRedis returns the Redis tracker (may be nil).
func (t *Tracker) GetRedis() *RedisTracker { return t.redis }

// GetDB returns the underlying database connection.
func (t *Tracker) GetDB() *sql.DB { return t.db }

// ──────────────────────────────────────────────
//  Connection CRUD
// ──────────────────────────────────────────────

// CreateConnection inserts a new row into lines_live.
// When Redis is attached, also writes to all Redis sorted sets.
func (t *Tracker) CreateConnection(conn *LiveConnection) error {
	var err error
	if conn.HMACID != nil {
		_, err = t.db.Exec(
			"INSERT INTO `lines_live` (`hmac_id`, `hmac_identifier`, `stream_id`, `server_id`, `proxy_id`, `user_agent`, `user_ip`, `container`, `pid`, `uuid`, `date_start`, `geoip_country_code`, `isp`, `external_device`, `hls_last_read`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			*conn.HMACID, conn.HMACIdentifier, conn.StreamID, conn.ServerID, conn.ProxyID,
			conn.UserAgent, conn.UserIP, conn.Container, conn.PID, conn.UUID,
			conn.DateStart, conn.GeoIPCountryCode, conn.ISP, conn.ExternalDevice,
			conn.HLSLastRead,
		)
	} else {
		_, err = t.db.Exec(
			"INSERT INTO `lines_live` (`user_id`, `stream_id`, `server_id`, `proxy_id`, `user_agent`, `user_ip`, `container`, `pid`, `uuid`, `date_start`, `geoip_country_code`, `isp`, `external_device`, `hls_last_read`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			conn.UserID, conn.StreamID, conn.ServerID, conn.ProxyID,
			conn.UserAgent, conn.UserIP, conn.Container, conn.PID, conn.UUID,
			conn.DateStart, conn.GeoIPCountryCode, conn.ISP, conn.ExternalDevice,
			conn.HLSLastRead,
		)
	}
	if err != nil {
		return err
	}

	if t.redis != nil {
		data := t.connToRedisMap(conn)
		if redisErr := t.redis.CreateConnection(data); redisErr != nil {
			log.Printf("tracker: redis create failed (MySQL OK): %v", redisErr)
		}
	}
	return nil
}

// connToRedisMap converts a LiveConnection to the map format stored in Redis.
func (t *Tracker) connToRedisMap(conn *LiveConnection) map[string]interface{} {
	data := map[string]interface{}{
		"stream_id":          int64(conn.StreamID),
		"server_id":          int64(conn.ServerID),
		"proxy_id":           int64(conn.ProxyID),
		"user_agent":         conn.UserAgent,
		"user_ip":            conn.UserIP,
		"container":          conn.Container,
		"pid":                int64(conn.PID),
		"date_start":         conn.DateStart,
		"geoip_country_code": conn.GeoIPCountryCode,
		"isp":                conn.ISP,
		"external_device":    conn.ExternalDevice,
		"hls_end":            int64(0),
		"hls_last_read":      conn.HLSLastRead,
		"on_demand":          int64(conn.OnDemand),
		"uuid":               conn.UUID,
	}
	if conn.HMACID != nil {
		data["hmac_id"] = int64(*conn.HMACID)
		data["hmac_identifier"] = conn.HMACIdentifier
		data["identity"] = fmt.Sprintf("%d_%s", *conn.HMACID, conn.HMACIdentifier)
	} else {
		data["user_id"] = int64(conn.UserID)
		data["identity"] = int64(conn.UserID)
	}
	return data
}

// LookupConnection checks if an active connection exists for this UUID.
func (t *Tracker) LookupConnection(uuid string) (*LiveConnection, error) {
	var conn LiveConnection
	var userID, hmacID sql.NullInt64
	var hmacIdent sql.NullString
	err := t.db.QueryRow(
		"SELECT `user_id`, `stream_id`, `server_id`, `pid`, `user_ip`, `hls_end`, `hmac_id`, `hmac_identifier` FROM `lines_live` WHERE `uuid` = ? AND `hls_end` = 0",
		uuid,
	).Scan(&userID, &conn.StreamID, &conn.ServerID, &conn.PID, &conn.UserIP, new(int), &hmacID, &hmacIdent)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if userID.Valid {
		conn.UserID = int(userID.Int64)
	}
	if hmacID.Valid {
		id := int(hmacID.Int64)
		conn.HMACID = &id
	}
	if hmacIdent.Valid {
		conn.HMACIdentifier = hmacIdent.String
	}
	conn.UUID = uuid
	return &conn, nil
}

// UpdateConnectionReuse updates an existing connection.
// When Redis is attached, also re-opens in Redis via UpdateLive.
func (t *Tracker) UpdateConnectionReuse(uuid string, serverID, proxyID, pid int, hlsLastRead int64) error {
	_, err := t.db.Exec(
		"UPDATE `lines_live` SET `server_id` = ?, `proxy_id` = ?, `pid` = ?, `hls_last_read` = ?, `hls_end` = 0 WHERE `uuid` = ?",
		serverID, proxyID, pid, hlsLastRead, uuid,
	)
	if err != nil {
		return err
	}

	if t.redis != nil {
		changes := map[string]interface{}{
			"server_id":     int64(serverID),
			"proxy_id":      int64(proxyID),
			"pid":           int64(pid),
			"hls_last_read": hlsLastRead,
		}
		if redisErr := t.redis.UpdateLive(uuid, changes); redisErr != nil {
			log.Printf("tracker: redis update_live failed (MySQL OK) uuid=%s: %v", uuid, redisErr)
		}
	}
	return nil
}

// DeleteClosedByUUID removes closed connections with this UUID.
func (t *Tracker) DeleteClosedByUUID(uuid string) {
	_, err := t.db.Exec("DELETE FROM `lines_live` WHERE `uuid` = ? AND `hls_end` = 1", uuid)
	if err != nil {
		log.Printf("delete closed uuid=%s: %v", uuid, err)
	}
}

// ──────────────────────────────────────────────
//  Enforcement — PHP ConnectionLimiter parity
// ──────────────────────────────────────────────

// enforcedConn holds connection data for enforcement decisions.
// Includes container/server/pid so eviction mirrors PHP behavior exactly.
type enforcedConn struct {
	uuid         string
	dateStart    int64
	ip           string
	userAgent    string
	container    string // "ts", "hls", "m3u8", "rtmp"
	connServerID int    // server_id of this connection
	pid          int    // pid of the worker (0 = Go/daemon served)
}

// EnforceMaxConnections closes excess connections for a user_id, matching PHP
// ConnectionLimiter::closeConnections(). Sorted by date_start ASC (oldest first).
// 3-pass priority: (2) same IP+UA, (1) same IP, (0) any.
func (t *Tracker) EnforceMaxConnections(userID, maxConnections int, currentUUID, currentIP, currentUA string) {
	var connections []enforcedConn

	if t.redis != nil {
		identity := fmt.Sprintf("%d", userID)
		uuids, err := t.redis.GetUserConnections(identity)
		if err != nil {
			log.Printf("enforce redis user=%d: %v", userID, err)
			return
		}
		records, err := t.redis.GetRecords(uuids)
		if err != nil {
			log.Printf("enforce redis records user=%d: %v", userID, err)
			return
		}
		for i, data := range records {
			if data != nil && igGetInt(data, "hls_end", 0) == 0 {
				connections = append(connections, enforcedConn{
					uuid:         uuids[i],
					dateStart:    igGetInt(data, "date_start", 0),
					ip:           igGetStr(data, "user_ip", ""),
					userAgent:    igGetStr(data, "user_agent", ""),
					container:    igGetStr(data, "container", ""),
					connServerID: int(igGetInt(data, "server_id", 0)),
					pid:          int(igGetInt(data, "pid", 0)),
				})
			}
		}
	} else {
		rows, err := t.db.Query(
			"SELECT `uuid`, `date_start`, `user_ip`, `user_agent`, `container`, `server_id`, `pid` FROM `lines_live` WHERE `user_id` = ? AND `hls_end` = 0 ORDER BY `date_start` ASC",
			userID,
		)
		if err != nil {
			log.Printf("enforce user=%d: %v", userID, err)
			return
		}
		defer rows.Close()
		for rows.Next() {
			var c enforcedConn
			if err := rows.Scan(&c.uuid, &c.dateStart, &c.ip, &c.userAgent, &c.container, &c.connServerID, &c.pid); err == nil {
				connections = append(connections, c)
			}
		}
	}

	t.enforceClose(connections, maxConnections, currentUUID, currentIP, currentUA, fmt.Sprintf("user=%d", userID))
}

// EnforceMaxConnectionsHMAC closes excess HMAC connections.
func (t *Tracker) EnforceMaxConnectionsHMAC(hmacID int, identifier string, maxConnections int, currentUUID, currentIP, currentUA string) {
	var connections []enforcedConn

	if t.redis != nil {
		identity := fmt.Sprintf("%d_%s", hmacID, identifier)
		uuids, err := t.redis.GetUserConnections(identity)
		if err != nil {
			log.Printf("enforce redis hmac=%d: %v", hmacID, err)
			return
		}
		records, err := t.redis.GetRecords(uuids)
		if err != nil {
			log.Printf("enforce redis records hmac=%d: %v", hmacID, err)
			return
		}
		for i, data := range records {
			if data != nil && igGetInt(data, "hls_end", 0) == 0 {
				connections = append(connections, enforcedConn{
					uuid:         uuids[i],
					dateStart:    igGetInt(data, "date_start", 0),
					ip:           igGetStr(data, "user_ip", ""),
					userAgent:    igGetStr(data, "user_agent", ""),
					container:    igGetStr(data, "container", ""),
					connServerID: int(igGetInt(data, "server_id", 0)),
					pid:          int(igGetInt(data, "pid", 0)),
				})
			}
		}
	} else {
		rows, err := t.db.Query(
			"SELECT `uuid`, `date_start`, `user_ip`, `user_agent`, `container`, `server_id`, `pid` FROM `lines_live` WHERE `hmac_id` = ? AND `hmac_identifier` = ? AND `hls_end` = 0 ORDER BY `date_start` ASC",
			hmacID, identifier,
		)
		if err != nil {
			log.Printf("enforce hmac=%d: %v", hmacID, err)
			return
		}
		defer rows.Close()
		for rows.Next() {
			var c enforcedConn
			if err := rows.Scan(&c.uuid, &c.dateStart, &c.ip, &c.userAgent, &c.container, &c.connServerID, &c.pid); err == nil {
				connections = append(connections, c)
			}
		}
	}

	t.enforceClose(connections, maxConnections, currentUUID, currentIP, currentUA, fmt.Sprintf("hmac=%d", hmacID))
}

// enforceClose implements the PHP 3-pass eviction logic.
// Sort by date_start ASC. Only close connections OLDER than currentUUID.
// Pass 2: same IP + same UA first; Pass 1: same IP; Pass 0: any.
// Eviction is container/server-aware, matching PHP ConnectionLimiter::closeConnection().
func (t *Tracker) enforceClose(connections []enforcedConn, maxConnections int, currentUUID, currentIP, currentUA, label string) {
	if len(connections) <= maxConnections {
		return
	}

	// Sort by date_start ASC, then by UUID for deterministic order
	sort.Slice(connections, func(i, j int) bool {
		if connections[i].dateStart == connections[j].dateStart {
			return connections[i].uuid < connections[j].uuid
		}
		return connections[i].dateStart < connections[j].dateStart
	})

	// Find the position of the current UUID — only close connections older than it
	olderThan := len(connections)
	for i, c := range connections {
		if c.uuid == currentUUID {
			olderThan = i
			break
		}
	}

	toClose := len(connections) - maxConnections
	closed := 0

	// 3-pass eviction matching PHP ConnectionLimiter
	for pass := 2; pass >= 0 && closed < toClose; pass-- {
		for i := 0; i < olderThan && closed < toClose; i++ {
			c := connections[i]
			if c.uuid == currentUUID {
				continue
			}
			if c.uuid == "" {
				continue // already closed in earlier pass
			}

			switch pass {
			case 2: // Same IP AND same user_agent
				if c.ip != currentIP || c.userAgent != currentUA {
					continue
				}
			case 1: // Same IP only
				if c.ip != currentIP {
					continue
				}
			} // pass 0: close any

			t.smartEvict(c)
			log.Printf("enforce: evicted uuid=%s container=%s server=%d pid=%d (%s, pass=%d, max=%d)", c.uuid, c.container, c.connServerID, c.pid, label, pass, maxConnections)
			connections[i].uuid = "" // mark as processed
			closed++
		}
	}
}

// ──────────────────────────────────────────────
//  IP Checks — with optional subnet matching
// ──────────────────────────────────────────────

// CheckUserIP returns the IP of the oldest active connection for a user.
// Used for disallow_2nd_ip_con. When ipSubnetMatch=1, compares by /24 subnet.
func (t *Tracker) CheckUserIP(userID int) (string, error) {
	if t.redis != nil {
		return t.redis.CheckUserIP(fmt.Sprintf("%d", userID))
	}
	var ip sql.NullString
	err := t.db.QueryRow(
		"SELECT `user_ip` FROM `lines_live` WHERE `user_id` = ? AND `hls_end` = 0 ORDER BY `activity_id` ASC LIMIT 1",
		userID,
	).Scan(&ip)
	if err == sql.ErrNoRows || !ip.Valid {
		return "", nil
	}
	if err != nil {
		return "", err
	}
	return ip.String, nil
}

// IPsMatch compares two IPs. When ipSubnetMatch=1, compares by /24 subnet.
// When ipSubnetMatch=0, exact match.
func (t *Tracker) IPsMatch(ip1, ip2 string) bool {
	if ip1 == "" || ip2 == "" {
		return ip1 == ip2
	}
	if t.ipSubnetMatch == 0 {
		return ip1 == ip2
	}
	// Subnet match: compare /24 for IPv4, /48 for IPv6
	return sameSubnet(ip1, ip2)
}

// sameSubnet checks if two IPs are in the same subnet (/24 IPv4, /48 IPv6).
func sameSubnet(a, b string) bool {
	ipA := net.ParseIP(a)
	ipB := net.ParseIP(b)
	if ipA == nil || ipB == nil {
		return a == b
	}
	// IPv4 /24
	if ipA.To4() != nil && ipB.To4() != nil {
		maskV4 := net.CIDRMask(24, 32)
		return ipA.Mask(maskV4).Equal(ipB.Mask(maskV4))
	}
	// IPv6 /48
	maskV6 := net.CIDRMask(48, 128)
	return ipA.Mask(maskV6).Equal(ipB.Mask(maskV6))
}

// ──────────────────────────────────────────────
//  Heartbeat loop
// ──────────────────────────────────────────────

// Register records a new viewer for heartbeat tracking.
func (t *Tracker) Register(uuid string, streamID int) chan struct{} {
	stopCh := make(chan struct{})
	t.mu.Lock()
	t.viewers[uuid] = &TrackedViewer{
		UUID:      uuid,
		StreamID:  streamID,
		StartTime: time.Now(),
		LastBeat:  time.Now(),
		StopCh:    stopCh,
	}
	t.mu.Unlock()
	t.heartbeatOne(uuid)
	return stopCh
}

// Unregister removes a viewer and closes the connection.
func (t *Tracker) Unregister(uuid string) {
	t.mu.Lock()
	v, ok := t.viewers[uuid]
	if ok {
		v.Stopped = true
		delete(t.viewers, uuid)
	}
	t.mu.Unlock()

	if ok {
		t.endConnection(uuid)
	}
}

// ActiveCount returns current tracked viewers.
func (t *Tracker) ActiveCount() int {
	t.mu.Lock()
	defer t.mu.Unlock()
	return len(t.viewers)
}

// DropByUUID disconnects a viewer by UUID (used by signal poller for admin kills).
// Matches PHP closeConnection with $rRemove=true: stops the viewer goroutine
// then deletes the row from lines_live.
func (t *Tracker) DropByUUID(uuid string) bool {
	t.mu.Lock()
	v, ok := t.viewers[uuid]
	if ok && !v.Stopped {
		v.Stopped = true
		close(v.StopCh)
	}
	t.mu.Unlock()

	if ok {
		// Admin kill: delete the row (matching PHP closeConnection $rRemove=true)
		t.db.Exec("DELETE FROM `lines_live` WHERE `uuid` = ?", uuid)
		if t.redis != nil {
			t.redis.RemoveRecord(uuid)
		}
		t.removeConsTmpFile(uuid)
	}

	return ok
}

func (t *Tracker) heartbeatLoop() {
	ticker := time.NewTicker(t.interval)
	defer ticker.Stop()
	for {
		select {
		case <-t.stopCh:
			return
		case <-ticker.C:
			t.heartbeatAll()
		}
	}
}

func (t *Tracker) heartbeatAll() {
	t.mu.Lock()
	uuids := make([]string, 0, len(t.viewers))
	for uuid := range t.viewers {
		uuids = append(uuids, uuid)
	}
	t.mu.Unlock()

	for _, uuid := range uuids {
		t.heartbeatOne(uuid)
	}
}

// heartbeatOne updates hls_last_read for an active connection.
// When Redis is attached:
//   - If Redis record has hls_end != 0 → admin set hls_end via panel → disconnect
//   - If Redis record is nil (DEL'd by removeRecord/admin kill) → disconnect
//   - Otherwise: update hls_last_read in Redis record
func (t *Tracker) heartbeatOne(uuid string) {
	now := time.Now().Unix() - int64(t.timeOffset)

	// Redis heartbeat (when redis_handler=1)
	if t.redis != nil {
		data, err := t.redis.UpdateHeartbeat(uuid, now)
		if err != nil {
			log.Printf("heartbeat redis error uuid=%s: %v", uuid, err)
		} else if data != nil && igGetInt(data, "hls_end", 0) != 0 {
			// Connection marked ended in Redis (admin set hls_end=1 via panel)
			t.mu.Lock()
			v, ok := t.viewers[uuid]
			if ok && !v.Stopped {
				v.Stopped = true
				close(v.StopCh)
				log.Printf("heartbeat: uuid=%s ended in Redis (hls_end!=0), signaling disconnect", uuid)
			}
			t.mu.Unlock()
			return
		} else if data == nil {
			// Redis key DEL'd — removeRecord was called (admin kill or enforcement).
			// The connection has been fully removed from Redis. Disconnect viewer.
			t.mu.Lock()
			v, ok := t.viewers[uuid]
			if ok && !v.Stopped {
				v.Stopped = true
				close(v.StopCh)
				log.Printf("heartbeat: uuid=%s removed from Redis (admin kill), signaling disconnect", uuid)
			}
			t.mu.Unlock()
			return
		}
	}

	// MySQL heartbeat (always)
	result, err := t.db.Exec(
		"UPDATE `lines_live` SET `hls_last_read` = ?, `pid` = 0 WHERE `uuid` = ? AND `hls_end` = 0",
		now, uuid,
	)
	if err != nil {
		log.Printf("heartbeat error uuid=%s: %v", uuid, err)
		return
	}
	affected, _ := result.RowsAffected()
	if affected == 0 {
		// Connection closed externally (admin DELETE or hls_end set) — disconnect
		t.mu.Lock()
		v, ok := t.viewers[uuid]
		if ok && !v.Stopped {
			v.Stopped = true
			close(v.StopCh)
			log.Printf("heartbeat: uuid=%s closed externally (MySQL), signaling disconnect", uuid)
		}
		t.mu.Unlock()
	} else {
		t.mu.Lock()
		if v, ok := t.viewers[uuid]; ok {
			v.LastBeat = time.Now()
		}
		t.mu.Unlock()
	}
}

// ──────────────────────────────────────────────
//  Connection close
// ──────────────────────────────────────────────

// endConnection closes a connection — NORMAL viewer disconnect.
// Redis: SoftClose (SADD ENDED, SET uuid hls_end=1, ZREM active sets).
// MariaDB: UPDATE hls_end=1.
// Activity log: writes offline activity if save_closed_connection=1.
func (t *Tracker) endConnection(uuid string) {
	if t.redis != nil {
		if err := t.redis.SoftClose(uuid); err != nil {
			log.Printf("end connection redis error uuid=%s: %v", uuid, err)
		}
	}

	_, err := t.db.Exec(
		"UPDATE `lines_live` SET `hls_end` = 1 WHERE `uuid` = ? AND `hls_end` = 0",
		uuid,
	)
	if err != nil {
		log.Printf("end connection error uuid=%s: %v", uuid, err)
	} else {
		log.Printf("connection ended uuid=%s", uuid)
	}

	// Write activity log (matching PHP writeOfflineActivity)
	t.writeOfflineActivity(uuid)
}

// evictConnection closes a connection — ENFORCEMENT (max_connections).
// Used for same-server Go-served (pid=0) or PHP TS (pid>0) connections.
// Redis: RemoveRecord (DEL uuid, ZREM ALL sets, SREM ENDED).
// MariaDB: DELETE the row (matching PHP closeConnection with $rRemove=true).
func (t *Tracker) evictConnection(uuid string) {
	if t.redis != nil {
		if err := t.redis.RemoveRecord(uuid); err != nil {
			log.Printf("evict connection redis error uuid=%s: %v", uuid, err)
		}
	}

	_, err := t.db.Exec(
		"DELETE FROM `lines_live` WHERE `uuid` = ?",
		uuid,
	)
	if err != nil {
		log.Printf("evict connection error uuid=%s: %v", uuid, err)
	} else {
		log.Printf("connection evicted uuid=%s (deleted)", uuid)
	}

	t.writeOfflineActivity(uuid)

	// Remove connection tmp file (matching PHP)
	t.removeConsTmpFile(uuid)
}

// smartEvict closes an evicted connection using the correct method for its type,
// matching PHP ConnectionLimiter::closeConnection() exactly:
//
//   - HLS/m3u8: UPDATE hls_end=1 (soft close) — player detects on next segment refresh
//   - Same server, pid=0 (Go/daemon): DELETE + tracker drop
//   - Same server, pid>0 (PHP TS worker): DELETE + SIGKILL the worker
//   - Different server: send a signal via the signals DB table for the target server's daemon
func (t *Tracker) smartEvict(c enforcedConn) {
	isHLS := c.container == "hls" || c.container == "m3u8"
	sameServer := t.serverID > 0 && c.connServerID == t.serverID

	if isHLS {
		// HLS: soft close — UPDATE hls_end=1, unlink cons_tmp file
		// Matches PHP closeConnection for container == 'hls'
		t.softCloseForEnforcement(c.uuid)
		return
	}

	if sameServer {
		if c.pid == 0 {
			// Go/daemon-served TS: DELETE + drop via tracker (if tracked locally)
			t.evictConnection(c.uuid)
			// Also signal the Go tracker to stop the viewer goroutine
			t.mu.Lock()
			v, ok := t.viewers[c.uuid]
			if ok && !v.Stopped {
				v.Stopped = true
				close(v.StopCh)
			}
			if ok {
				delete(t.viewers, c.uuid)
			}
			t.mu.Unlock()
		} else {
			// PHP-served TS: activity log first (row still exists), then DELETE + SIGKILL
			t.writeOfflineActivity(c.uuid)
			if t.redis != nil {
				t.redis.RemoveRecord(c.uuid)
			}
			t.db.Exec("DELETE FROM `lines_live` WHERE `uuid` = ?", c.uuid)
			t.removeConsTmpFile(c.uuid)
			// SIGKILL the PHP-fpm worker (matching PHP posix_kill($pid, 9))
			if c.pid > 0 {
				syscall.Kill(c.pid, syscall.SIGKILL)
				log.Printf("enforce: killed php pid=%d for uuid=%s", c.pid, c.uuid)
			}
		}
	} else {
		// Different server: dispatch a signal via the signals table
		// The target server's signal daemon will pick it up and act
		t.sendEvictSignal(c)
	}
}

// softCloseForEnforcement is the HLS-specific enforcement close.
// Matches PHP closeConnection for container='hls': UPDATE hls_end=1.
// The HLS player detects this on its next segment/playlist refresh.
func (t *Tracker) softCloseForEnforcement(uuid string) {
	if t.redis != nil {
		if err := t.redis.SoftClose(uuid); err != nil {
			log.Printf("enforce soft close redis error uuid=%s: %v", uuid, err)
		}
	}

	_, err := t.db.Exec(
		"UPDATE `lines_live` SET `hls_end` = 1 WHERE `uuid` = ? AND `hls_end` = 0",
		uuid,
	)
	if err != nil {
		log.Printf("enforce soft close error uuid=%s: %v", uuid, err)
	} else {
		log.Printf("enforce: soft close (hls_end=1) uuid=%s", uuid)
	}

	// Remove cons_tmp file to end segment delivery quickly
	// Matches PHP: @unlink(CONS_TMP_PATH . $rActivityInfo['uuid'])
	t.removeConsTmpFile(uuid)
}

// sendEvictSignal dispatches a drop signal to a different server via the signals table.
// Matches PHP SignalDispatcher::kill() / ConnectionTracker::dropDaemonViewer().
// The target server's signal daemon reads signals WHERE server_id=target AND cache=1.
func (t *Tracker) sendEvictSignal(c enforcedConn) {
	customData := fmt.Sprintf(`{"type":"drop_con","uuid":"%s"}`, c.uuid)
	_, err := t.db.Exec(
		"INSERT INTO `signals` (`server_id`, `cache`, `custom_data`) VALUES (?, 1, ?)",
		c.connServerID, customData,
	)
	if err != nil {
		log.Printf("enforce: send signal to server=%d for uuid=%s failed: %v", c.connServerID, c.uuid, err)
	} else {
		log.Printf("enforce: dispatched drop signal to server=%d for uuid=%s", c.connServerID, c.uuid)
	}
}

// ──────────────────────────────────────────────
//  Activity logging — PHP parity: writeOfflineActivity
// ──────────────────────────────────────────────

// writeOfflineActivity writes a closed connection record for the XC_VM activity
// processor, matching PHP's ConnectionTracker::writeOfflineActivity().
// Only active when save_closed_connection=1 and activityLogDir is set.
func (t *Tracker) writeOfflineActivity(uuid string) {
	if t.activityLogDir == "" {
		return
	}

	// Read the connection row for activity data
	var userID, streamID, serverID sql.NullInt64
	var userIP, container, ua, isp, cc, extDev sql.NullString
	var dateStart, hlsLastRead sql.NullInt64
	var hmacID sql.NullInt64
	var hmacIdent sql.NullString

	err := t.db.QueryRow(
		"SELECT `user_id`, `stream_id`, `server_id`, `user_ip`, `container`, `user_agent`, `isp`, `geoip_country_code`, `external_device`, `date_start`, `hls_last_read`, `hmac_id`, `hmac_identifier` FROM `lines_live` WHERE `uuid` = ? LIMIT 1",
		uuid,
	).Scan(&userID, &streamID, &serverID, &userIP, &container, &ua, &isp, &cc, &extDev, &dateStart, &hlsLastRead, &hmacID, &hmacIdent)
	if err != nil {
		return // row already gone or error, skip logging
	}

	activity := map[string]interface{}{
		"user_id":            nullInt(userID),
		"stream_id":          nullInt(streamID),
		"server_id":          nullInt(serverID),
		"user_ip":            nullStr(userIP),
		"container":          nullStr(container),
		"user_agent":         nullStr(ua),
		"isp":                nullStr(isp),
		"geoip_country_code": nullStr(cc),
		"external_device":    nullStr(extDev),
		"date_start":         nullInt(dateStart),
		"date_end":           time.Now().Unix(),
		"hls_last_read":      nullInt(hlsLastRead),
		"uuid":               uuid,
	}
	if hmacID.Valid {
		activity["hmac_id"] = hmacID.Int64
		activity["hmac_identifier"] = nullStr(hmacIdent)
	}

	data, err := json.Marshal(activity)
	if err != nil {
		return
	}

	// Write to activity log file (append, one JSON object per line)
	logFile := filepath.Join(t.activityLogDir, "activity")
	f, err := os.OpenFile(logFile, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0640)
	if err != nil {
		log.Printf("activity log: open %s: %v", logFile, err)
		return
	}
	defer f.Close()
	f.Write(data)
	f.Write([]byte("\n"))
}

func nullInt(v sql.NullInt64) int64 {
	if v.Valid {
		return v.Int64
	}
	return 0
}

func nullStr(v sql.NullString) string {
	if v.Valid {
		return v.String
	}
	return ""
}

// removeConsTmpFile removes the connection temp file (matching PHP's unlink).
func (t *Tracker) removeConsTmpFile(uuid string) {
	if t.consTmpPath == "" {
		return
	}
	path := filepath.Join(t.consTmpPath, uuid)
	os.Remove(path) // ignore error (file may not exist)
}

// ──────────────────────────────────────────────
//  Shutdown
// ──────────────────────────────────────────────

func (t *Tracker) Stop() {
	close(t.stopCh)
	t.mu.Lock()
	remaining := make([]string, 0, len(t.viewers))
	for uuid := range t.viewers {
		remaining = append(remaining, uuid)
	}
	t.viewers = make(map[string]*TrackedViewer)
	t.mu.Unlock()

	for _, uuid := range remaining {
		t.endConnection(uuid)
	}
	if t.redis != nil {
		t.redis.Close()
	}
	t.db.Close()
}
