package main

import (
	"context"
	"fmt"
	"log"
	"time"

	"github.com/redis/go-redis/v9"
)

// RedisTracker mirrors PHP's ConnectionTracker Redis operations.
// When redis_handler=1, every create/heartbeat/close writes to BOTH Redis
// (the primary store the reaper and panel read) and MariaDB (persistence).
//
// Redis key structure (matching XC_VM PHP):
//
//	<uuid>                         → igbinary(connection_data)
//	LIVE                           → SortedSet (score=date_start, member=uuid)
//	CONNECTIONS                    → SortedSet (score=date_start, member=uuid) — global index
//	LINE#<identity>                → SortedSet (score=date_start, member=uuid)
//	LINE_ALL#<identity>            → SortedSet (score=date_start, member=uuid)
//	STREAM#<stream_id>             → SortedSet (score=date_start, member=uuid)
//	SERVER#<server_id>             → SortedSet (score=date_start, member=uuid)
//	SERVER_LINES#<server_id>       → SortedSet (score=user_id, member=uuid)
//	PROXY#<proxy_id>               → SortedSet (score=date_start, member=uuid)  [if proxy_id>0]
//	ENDED                          → Set (uuid)
type RedisTracker struct {
	client *redis.Client
	ctx    context.Context
}

// NewRedisTracker creates a Redis connection for XC_VM connection tracking.
func NewRedisTracker(addr, password string, db int) (*RedisTracker, error) {
	client := redis.NewClient(&redis.Options{
		Addr:         addr,
		Password:     password,
		DB:           db,
		DialTimeout:  5 * time.Second,
		ReadTimeout:  3 * time.Second,
		WriteTimeout: 3 * time.Second,
		PoolSize:     10,
		MinIdleConns: 2,
	})
	ctx := context.Background()
	if err := client.Ping(ctx).Err(); err != nil {
		client.Close()
		return nil, fmt.Errorf("redis ping: %w", err)
	}
	return &RedisTracker{client: client, ctx: ctx}, nil
}

// connIdentity returns the identity string used for LINE# sorted set keys.
// Regular user: "<user_id>", HMAC: "<hmac_id>_<identifier>"
func connIdentity(data map[string]interface{}) string {
	if id, ok := data["identity"]; ok {
		switch v := id.(type) {
		case int64:
			return fmt.Sprintf("%d", v)
		case string:
			return v
		}
	}
	if uid := igGetInt(data, "user_id", 0); uid > 0 {
		return fmt.Sprintf("%d", uid)
	}
	hmacID := igGetInt(data, "hmac_id", 0)
	hmacIdent := igGetStr(data, "hmac_identifier", "")
	if hmacID > 0 {
		return fmt.Sprintf("%d_%s", hmacID, hmacIdent)
	}
	return "0"
}

// CreateConnection writes a new connection to Redis (MULTI/EXEC) matching
// PHP's ConnectionTracker::createConnection().
// Writes to ALL sorted sets: LIVE, CONNECTIONS, LINE#, LINE_ALL#, STREAM#,
// SERVER#, SERVER_LINES#, PROXY#.
func (rt *RedisTracker) CreateConnection(data map[string]interface{}) error {
	uuid := igGetStr(data, "uuid", "")
	if uuid == "" {
		return fmt.Errorf("redis create: missing uuid")
	}

	identity := connIdentity(data)
	streamID := igGetInt(data, "stream_id", 0)
	serverID := igGetInt(data, "server_id", 0)
	proxyID := igGetInt(data, "proxy_id", 0)
	userID := igGetInt(data, "user_id", 0)
	dateStart := float64(igGetInt(data, "date_start", 0))

	data["hls_end"] = int64(0)

	serialized, err := IgbinaryEncode(data)
	if err != nil {
		return fmt.Errorf("redis create: igbinary encode: %w", err)
	}

	pipe := rt.client.TxPipeline()
	pipe.SRem(rt.ctx, "ENDED", uuid)
	pipe.ZAdd(rt.ctx, "LIVE", redis.Z{Score: dateStart, Member: uuid})
	pipe.ZAdd(rt.ctx, "CONNECTIONS", redis.Z{Score: dateStart, Member: uuid})
	pipe.ZAdd(rt.ctx, fmt.Sprintf("LINE#%s", identity), redis.Z{Score: dateStart, Member: uuid})
	pipe.ZAdd(rt.ctx, fmt.Sprintf("LINE_ALL#%s", identity), redis.Z{Score: dateStart, Member: uuid})
	pipe.ZAdd(rt.ctx, fmt.Sprintf("STREAM#%d", streamID), redis.Z{Score: dateStart, Member: uuid})
	pipe.ZAdd(rt.ctx, fmt.Sprintf("SERVER#%d", serverID), redis.Z{Score: dateStart, Member: uuid})
	if userID > 0 {
		pipe.ZAdd(rt.ctx, fmt.Sprintf("SERVER_LINES#%d", serverID), redis.Z{Score: float64(userID), Member: uuid})
	}
	if proxyID > 0 {
		pipe.ZAdd(rt.ctx, fmt.Sprintf("PROXY#%d", proxyID), redis.Z{Score: dateStart, Member: uuid})
	}
	pipe.Set(rt.ctx, uuid, serialized, 0)

	_, err = pipe.Exec(rt.ctx)
	if err != nil {
		return fmt.Errorf("redis create: exec: %w", err)
	}
	return nil
}

// GetConnection reads and deserializes a connection record from Redis.
// Returns nil if the key doesn't exist.
func (rt *RedisTracker) GetConnection(uuid string) (map[string]interface{}, error) {
	raw, err := rt.client.Get(rt.ctx, uuid).Bytes()
	if err == redis.Nil {
		return nil, nil
	}
	if err != nil {
		return nil, fmt.Errorf("redis get %s: %w", uuid, err)
	}
	data, err := IgbinaryDecode(raw)
	if err != nil {
		return nil, fmt.Errorf("redis decode %s: %w", uuid, err)
	}
	return data, nil
}

// UpdateHeartbeat refreshes hls_last_read in the Redis record (data update only,
// no sorted set changes). Matches PHP: updateConnection($existing, ['hls_last_read' => $now]).
func (rt *RedisTracker) UpdateHeartbeat(uuid string, lastRead int64) (map[string]interface{}, error) {
	data, err := rt.GetConnection(uuid)
	if err != nil {
		return nil, err
	}
	if data == nil {
		return nil, nil
	}
	if igGetInt(data, "hls_end", 0) != 0 {
		return data, nil // ended, return as-is so caller can detect admin kill
	}

	data["hls_last_read"] = lastRead

	serialized, err := IgbinaryEncode(data)
	if err != nil {
		return nil, fmt.Errorf("redis heartbeat encode: %w", err)
	}
	if err := rt.client.Set(rt.ctx, uuid, serialized, 0).Err(); err != nil {
		return nil, fmt.Errorf("redis heartbeat set: %w", err)
	}
	return data, nil
}

// SoftClose marks a connection as ended in Redis (MULTI/EXEC), matching
// PHP's ShutdownHandler → updateConnection($data, [], 'close').
//
// Used for NORMAL viewer disconnect. Keeps the UUID key (with hls_end=1)
// and adds to ENDED set so reapers/crons know to clean up later.
// Removes from active sorted sets (LIVE, LINE#, STREAM#, SERVER#, etc.)
// but does NOT touch CONNECTIONS or LINE_ALL# (those are only managed by
// createConnection and removeRecord).
func (rt *RedisTracker) SoftClose(uuid string) error {
	data, err := rt.GetConnection(uuid)
	if err != nil {
		return err
	}
	if data == nil {
		return nil
	}

	identity := connIdentity(data)
	streamID := igGetInt(data, "stream_id", 0)
	serverID := igGetInt(data, "server_id", 0)
	proxyID := igGetInt(data, "proxy_id", 0)
	userID := igGetInt(data, "user_id", 0)

	data["hls_end"] = int64(1)
	serialized, err := IgbinaryEncode(data)
	if err != nil {
		return fmt.Errorf("redis soft_close encode: %w", err)
	}

	pipe := rt.client.TxPipeline()
	pipe.SAdd(rt.ctx, "ENDED", uuid)
	pipe.ZRem(rt.ctx, "LIVE", uuid)
	pipe.ZRem(rt.ctx, fmt.Sprintf("LINE#%s", identity), uuid)
	pipe.ZRem(rt.ctx, fmt.Sprintf("STREAM#%d", streamID), uuid)
	pipe.ZRem(rt.ctx, fmt.Sprintf("SERVER#%d", serverID), uuid)
	if userID > 0 {
		pipe.ZRem(rt.ctx, fmt.Sprintf("SERVER_LINES#%d", serverID), uuid)
	}
	if proxyID > 0 {
		pipe.ZRem(rt.ctx, fmt.Sprintf("PROXY#%d", proxyID), uuid)
	}
	pipe.Set(rt.ctx, uuid, serialized, 0)

	_, err = pipe.Exec(rt.ctx)
	return err
}

// RemoveRecord fully removes a connection from Redis (MULTI/EXEC), matching
// PHP's ConnectionTracker::removeRecord().
//
// Used for ENFORCEMENT (max_connections eviction) and CLEANUP.
// Removes ALL traces from Redis: DEL uuid key, ZREM from every sorted set
// (including CONNECTIONS and LINE_ALL#), SREM from ENDED.
func (rt *RedisTracker) RemoveRecord(uuid string) error {
	data, err := rt.GetConnection(uuid)
	if err != nil {
		return err
	}
	if data == nil {
		return nil
	}

	identity := connIdentity(data)
	streamID := igGetInt(data, "stream_id", 0)
	serverID := igGetInt(data, "server_id", 0)
	proxyID := igGetInt(data, "proxy_id", 0)
	userID := igGetInt(data, "user_id", 0)

	pipe := rt.client.TxPipeline()
	pipe.ZRem(rt.ctx, fmt.Sprintf("LINE#%s", identity), uuid)
	pipe.ZRem(rt.ctx, fmt.Sprintf("LINE_ALL#%s", identity), uuid)
	pipe.ZRem(rt.ctx, fmt.Sprintf("STREAM#%d", streamID), uuid)
	pipe.ZRem(rt.ctx, fmt.Sprintf("SERVER#%d", serverID), uuid)
	if userID > 0 {
		pipe.ZRem(rt.ctx, fmt.Sprintf("SERVER_LINES#%d", serverID), uuid)
	}
	if proxyID > 0 {
		pipe.ZRem(rt.ctx, fmt.Sprintf("PROXY#%d", proxyID), uuid)
	}
	pipe.Del(rt.ctx, uuid)
	pipe.ZRem(rt.ctx, "CONNECTIONS", uuid)
	pipe.ZRem(rt.ctx, "LIVE", uuid)
	pipe.SRem(rt.ctx, "ENDED", uuid)

	_, err = pipe.Exec(rt.ctx)
	return err
}

// UpdateLive re-opens an existing connection (matching PHP's updateConnection
// with option='open'). Used when a viewer reconnects with the same UUID.
// Unlike createConnection, does NOT add to CONNECTIONS or LINE_ALL# (those are
// only set on initial create). Handles server/stream/proxy migration by removing
// from old sorted sets before adding to new ones.
func (rt *RedisTracker) UpdateLive(uuid string, changes map[string]interface{}) error {
	data, err := rt.GetConnection(uuid)
	if err != nil {
		return err
	}
	if data == nil {
		return fmt.Errorf("redis update_live: uuid %s not found", uuid)
	}

	origIdentity := connIdentity(data)
	origStreamID := igGetInt(data, "stream_id", 0)
	origServerID := igGetInt(data, "server_id", 0)
	origProxyID := igGetInt(data, "proxy_id", 0)
	wasEnded := igGetInt(data, "hls_end", 0) != 0

	for k, v := range changes {
		data[k] = v
	}
	data["hls_end"] = int64(0)

	identity := connIdentity(data)
	streamID := igGetInt(data, "stream_id", 0)
	serverID := igGetInt(data, "server_id", 0)
	proxyID := igGetInt(data, "proxy_id", 0)
	userID := igGetInt(data, "user_id", 0)
	dateStart := float64(igGetInt(data, "date_start", 0))
	serverMoved := origServerID != serverID

	serialized, err := IgbinaryEncode(data)
	if err != nil {
		return fmt.Errorf("redis update_live encode: %w", err)
	}

	pipe := rt.client.TxPipeline()

	// Remove from old sorted sets if field changed (PHP migration handling)
	if origIdentity != identity && origIdentity != "" && origIdentity != "0" {
		pipe.ZRem(rt.ctx, fmt.Sprintf("LINE#%s", origIdentity), uuid)
	}
	if origStreamID != streamID && origStreamID > 0 {
		pipe.ZRem(rt.ctx, fmt.Sprintf("STREAM#%d", origStreamID), uuid)
	}
	if serverMoved && origServerID > 0 {
		pipe.ZRem(rt.ctx, fmt.Sprintf("SERVER#%d", origServerID), uuid)
		if userID > 0 {
			pipe.ZRem(rt.ctx, fmt.Sprintf("SERVER_LINES#%d", origServerID), uuid)
		}
	}
	if origProxyID != proxyID && origProxyID > 0 {
		pipe.ZRem(rt.ctx, fmt.Sprintf("PROXY#%d", origProxyID), uuid)
	}

	pipe.SRem(rt.ctx, "ENDED", uuid)
	pipe.ZAdd(rt.ctx, "LIVE", redis.Z{Score: dateStart, Member: uuid})
	pipe.ZAdd(rt.ctx, fmt.Sprintf("LINE#%s", identity), redis.Z{Score: dateStart, Member: uuid})
	pipe.ZAdd(rt.ctx, fmt.Sprintf("STREAM#%d", streamID), redis.Z{Score: dateStart, Member: uuid})
	pipe.ZAdd(rt.ctx, fmt.Sprintf("SERVER#%d", serverID), redis.Z{Score: dateStart, Member: uuid})
	if proxyID > 0 {
		pipe.ZAdd(rt.ctx, fmt.Sprintf("PROXY#%d", proxyID), redis.Z{Score: dateStart, Member: uuid})
	}
	// SERVER_LINES# only when re-opening a closed connection or server moved
	if (wasEnded || serverMoved) && userID > 0 {
		pipe.ZAdd(rt.ctx, fmt.Sprintf("SERVER_LINES#%d", serverID), redis.Z{Score: float64(userID), Member: uuid})
	}
	pipe.Set(rt.ctx, uuid, serialized, 0)

	_, err = pipe.Exec(rt.ctx)
	return err
}

// GetUserConnections returns all active UUIDs for a user identity from Redis.
// Used for max_connections enforcement and disallow_2nd_ip_con checks.
func (rt *RedisTracker) GetUserConnections(identity string) ([]string, error) {
	uuids, err := rt.client.ZRangeByScore(rt.ctx, fmt.Sprintf("LINE#%s", identity), &redis.ZRangeBy{
		Min: "-inf",
		Max: "+inf",
	}).Result()
	if err != nil {
		return nil, err
	}
	return uuids, nil
}

// GetRecords batch-reads connection records from Redis.
func (rt *RedisTracker) GetRecords(uuids []string) ([]map[string]interface{}, error) {
	if len(uuids) == 0 {
		return nil, nil
	}
	keys := make([]string, len(uuids))
	for i, u := range uuids {
		keys[i] = u
	}
	vals, err := rt.client.MGet(rt.ctx, keys...).Result()
	if err != nil {
		return nil, err
	}
	records := make([]map[string]interface{}, len(vals))
	for i, val := range vals {
		if val == nil {
			continue
		}
		raw, ok := val.(string)
		if !ok {
			continue
		}
		data, err := IgbinaryDecode([]byte(raw))
		if err != nil {
			log.Printf("redis: decode %s: %v", uuids[i], err)
			continue
		}
		records[i] = data
	}
	return records, nil
}

// CheckUserIP returns the IP of the oldest active connection for a user
// identity in Redis. Used for disallow_2nd_ip_con.
func (rt *RedisTracker) CheckUserIP(identity string) (string, error) {
	uuids, err := rt.GetUserConnections(identity)
	if err != nil {
		return "", err
	}
	if len(uuids) == 0 {
		return "", nil
	}

	records, err := rt.GetRecords(uuids)
	if err != nil {
		return "", err
	}

	for _, data := range records {
		if data == nil {
			continue
		}
		if igGetInt(data, "hls_end", 0) == 0 {
			return igGetStr(data, "user_ip", ""), nil
		}
	}
	return "", nil
}

// Close shuts down the Redis connection.
func (rt *RedisTracker) Close() {
	rt.client.Close()
}
