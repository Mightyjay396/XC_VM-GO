package main

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/md5"
	"crypto/sha1"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// TokenData is the decrypted XC_VM stream token structure.
// Main mints it, encrypts it, and sends it via 302 redirect to the LB.
type TokenData struct {
	StreamID       int         `json:"stream_id"`
	Username       string      `json:"username"`
	Password       string      `json:"password"`
	Extension      string      `json:"extension"`
	ChannelInfo    ChannelInfo `json:"channel_info"`
	UserInfo       UserInfo    `json:"user_info"`
	PID            int         `json:"pid"`
	Prebuffer      interface{} `json:"prebuffer"` // can be false or int
	CountryCode    string      `json:"country_code"`
	ActivityStart  int64       `json:"activity_start"`
	ExternalDevice *string     `json:"external_device"`
	VideoCodec     string      `json:"video_codec"`
	UUID           string      `json:"uuid"`
	Expires        int64       `json:"expires,omitempty"`
	HMACID         *int        `json:"hmac_id,omitempty"`
	Identifier     string      `json:"identifier,omitempty"`
	VideoPath      *string     `json:"video_path,omitempty"`
	OffAir         *bool       `json:"off_air,omitempty"`
}

type ChannelInfo struct {
	StreamID     int  `json:"stream_id"`
	RedirectID   int  `json:"redirect_id"`
	OriginatorID *int `json:"originator_id"`
	PID          int  `json:"pid"`
	OnDemand     int  `json:"on_demand"`
	LLOD         int  `json:"llod"`
	MonitorPID   int  `json:"monitor_pid"`
	Proxy        int  `json:"proxy"`
}

type UserInfo struct {
	ID             int    `json:"id"`
	MaxConnections int    `json:"max_connections"`
	PairID         *int   `json:"pair_id"`
	ConISPName     string `json:"con_isp_name"`
	IsRestreamer   int    `json:"is_restreamer"`
}

// GetPrebufferSec returns the effective prebuffer in seconds.
func (t *TokenData) GetPrebufferSec(clientPrebuffer, restreamerPrebuffer, segTime int) int {
	if t.UserInfo.IsRestreamer == 1 {
		switch v := t.Prebuffer.(type) {
		case bool:
			if !v {
				return restreamerPrebuffer
			}
			return segTime
		case float64:
			if v != 0 {
				return segTime
			}
			return restreamerPrebuffer
		default:
			return restreamerPrebuffer
		}
	}
	return clientPrebuffer
}

// TokenDecryptor handles XC_VM token decryption.
// Supports both sealed (AES-256-GCM) and legacy (AES-256-CBC) tokens.
type TokenDecryptor struct {
	liveStreamingPass string
	opensslExtra      string
	gcmKey            []byte
	cbcKey            string
	cbcIV             string
}

const (
	sealNonce = 12
	sealTag   = 16
)

func NewTokenDecryptor(liveStreamingPass, opensslExtra string) *TokenDecryptor {
	d := &TokenDecryptor{
		liveStreamingPass: liveStreamingPass,
		opensslExtra:      opensslExtra,
	}

	// Precompute GCM seal key: HMAC-SHA256("xc_vm stream token v2|" + extra, pass)
	mac := hmac.New(sha256.New, []byte(liveStreamingPass))
	mac.Write([]byte("xc_vm stream token v2|" + opensslExtra))
	d.gcmKey = mac.Sum(nil)

	// Precompute CBC key/IV
	extraHash := fmt.Sprintf("%x", sha1.Sum([]byte(opensslExtra)))
	passHash := fmt.Sprintf("%x", sha1.Sum([]byte(liveStreamingPass)))
	d.cbcKey = fmt.Sprintf("%x", md5.Sum([]byte(extraHash+liveStreamingPass)))
	d.cbcIV = fmt.Sprintf("%x", md5.Sum([]byte(passHash)))[:16]

	return d
}

// Decrypt tries GCM first, then CBC fallback.
func (d *TokenDecryptor) Decrypt(token string) (*TokenData, error) {
	raw, err := base64urlDecode(token)
	if err != nil {
		return nil, fmt.Errorf("base64 decode: %w", err)
	}

	// Try AES-256-GCM (sealed)
	if len(raw) > sealNonce+sealTag {
		if plain, err := d.decryptGCM(raw); err == nil {
			return d.parseToken(plain)
		}
	}

	// Fallback: AES-256-CBC (legacy)
	plain, err := d.decryptCBC(raw)
	if err != nil {
		return nil, fmt.Errorf("decrypt failed (both GCM and CBC): %w", err)
	}
	return d.parseToken(plain)
}

func (d *TokenDecryptor) decryptGCM(raw []byte) ([]byte, error) {
	nonce := raw[:sealNonce]
	tag := raw[len(raw)-sealTag:]
	ciphertext := raw[sealNonce : len(raw)-sealTag]

	block, err := aes.NewCipher(d.gcmKey)
	if err != nil {
		return nil, err
	}
	gcm, err := cipher.NewGCMWithNonceSize(block, sealNonce)
	if err != nil {
		return nil, err
	}

	combined := append(ciphertext, tag...)
	plain, err := gcm.Open(nil, nonce, combined, nil)
	if err != nil {
		return nil, err
	}
	return plain, nil
}

func (d *TokenDecryptor) decryptCBC(raw []byte) ([]byte, error) {
	key := []byte(d.cbcKey)
	iv := []byte(d.cbcIV)

	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}

	if len(raw)%aes.BlockSize != 0 || len(raw) == 0 {
		return nil, fmt.Errorf("invalid ciphertext length %d", len(raw))
	}

	mode := cipher.NewCBCDecrypter(block, iv)
	plain := make([]byte, len(raw))
	mode.CryptBlocks(plain, raw)

	plain, err = pkcs7Unpad(plain)
	if err != nil {
		return nil, err
	}
	return plain, nil
}

func (d *TokenDecryptor) parseToken(data []byte) (*TokenData, error) {
	var td TokenData
	if err := json.Unmarshal(data, &td); err != nil {
		return nil, fmt.Errorf("json parse: %w", err)
	}
	return &td, nil
}

func base64urlDecode(s string) ([]byte, error) {
	s = strings.ReplaceAll(s, "-", "+")
	s = strings.ReplaceAll(s, "_", "/")
	switch len(s) % 4 {
	case 2:
		s += "=="
	case 3:
		s += "="
	}
	return base64.StdEncoding.DecodeString(s)
}

func pkcs7Unpad(data []byte) ([]byte, error) {
	if len(data) == 0 {
		return nil, fmt.Errorf("empty data")
	}
	padding := int(data[len(data)-1])
	if padding > aes.BlockSize || padding == 0 {
		return nil, fmt.Errorf("invalid padding %d", padding)
	}
	for i := len(data) - padding; i < len(data); i++ {
		if data[i] != byte(padding) {
			return nil, fmt.Errorf("invalid padding byte")
		}
	}
	return data[:len(data)-padding], nil
}

// AuthHandler handles /auth/<token> requests: decrypt, create DB record, serve TS.
type AuthHandler struct {
	decryptor        *TokenDecryptor
	tsHandler        *TSHandler
	tracker          *Tracker
	serverID         int
	timeOffset       int
	clientPrebuffer  int
	restrPrebuffer   int
	segTime          int
	createExpiration int
	// Settings for PHP parity
	disallow2ndIPCon int
	disallow2ndIPMax int
	restrictSameIP   int
	ipSubnetMatch    int
	// On-demand stream start
	onDemand         *OnDemandStarter
}

var authPathRe = regexp.MustCompile(`^/auth/(.+)$`)

func NewAuthHandler(decryptor *TokenDecryptor, tsHandler *TSHandler, tracker *Tracker,
	serverID, timeOffset, clientPrebuffer, restrPrebuffer, segTime, createExpiration int,
	settings *XCSettings, onDemand *OnDemandStarter) *AuthHandler {
	h := &AuthHandler{
		decryptor:        decryptor,
		tsHandler:        tsHandler,
		tracker:          tracker,
		serverID:         serverID,
		timeOffset:       timeOffset,
		clientPrebuffer:  clientPrebuffer,
		restrPrebuffer:   restrPrebuffer,
		segTime:          segTime,
		createExpiration: createExpiration,
		onDemand:         onDemand,
	}
	if settings != nil {
		h.disallow2ndIPCon = settings.Disallow2ndIPCon
		h.disallow2ndIPMax = settings.Disallow2ndIPMax
		h.restrictSameIP = settings.RestrictSameIP
		h.ipSubnetMatch = settings.IPSubnetMatch
	}
	return h
}

// ServeAuth handles: GET /auth/<encrypted_token>
//
// This replaces live.php entirely for TS/HLS delivery on MAIN and LB:
//  1. Send stream headers (CORS, protection — like PHP sendStreamHeaders)
//  2. Decrypt token (GCM -> CBC fallback)
//  3. Handle off-air/video_path tokens
//  4. Validate expiry
//  5. Check disallow_2nd_ip_con
//  6. Create connection in lines_live (or update existing)
//  7. Check restrict_same_ip on existing connection
//  8. Enforce max_connections (regular + HMAC + pair_id)
//  9. Serve MPEG-TS stream (prebuffer + chase-read)
// 10. On disconnect: set hls_end=1
func (a *AuthHandler) ServeAuth(w http.ResponseWriter, r *http.Request) {
	matches := authPathRe.FindStringSubmatch(r.URL.Path)
	if matches == nil {
		http.Error(w, "invalid auth path", http.StatusBadRequest)
		return
	}
	token := matches[1]

	// ── 1. Send stream headers (PHP parity: sendStreamHeaders) ──
	// Always send CORS header like PHP does
	w.Header().Set("Access-Control-Allow-Origin", "*")

	clientIP := r.Header.Get("X-Real-IP")
	if clientIP == "" {
		clientIP = r.Header.Get("X-Forwarded-For")
		if clientIP != "" {
			clientIP = strings.Split(clientIP, ",")[0]
			clientIP = strings.TrimSpace(clientIP)
		}
	}
	if clientIP == "" {
		clientIP = strings.Split(r.RemoteAddr, ":")[0]
	}

	userAgent := r.Header.Get("User-Agent")

	// ── 2. Decrypt token ──
	td, err := a.decryptor.Decrypt(token)
	if err != nil {
		log.Printf("auth: token decrypt failed from %s: %v", clientIP, err)
		http.Error(w, "LB_TOKEN_INVALID", http.StatusForbidden)
		return
	}

	// ── 3. Handle off-air/video_path tokens (PHP parity) ──
	if td.VideoPath != nil || td.OffAir != nil {
		// PHP reads the off-air video file and returns it. Go returns a simple error
		// since we don't have access to the video file rendering.
		log.Printf("auth: off-air/video_path token for stream=%d, returning 404", td.StreamID)
		http.Error(w, "STREAM_OFF_AIR", http.StatusNotFound)
		return
	}

	// ── 4. Validate token expiry ──
	now := time.Now().Unix() - int64(a.timeOffset)
	if td.Expires > 0 && td.Expires < now {
		log.Printf("auth: token expired for stream=%d uuid=%s", td.StreamID, td.UUID)
		http.Error(w, "TOKEN_EXPIRED", http.StatusForbidden)
		return
	}

	// Only handle TS and HLS (m3u8) extensions
	isHLS := td.Extension == "m3u8"
	if td.Extension != "" && td.Extension != "ts" && !isHLS {
		log.Printf("auth: unsupported extension %q for uuid=%s, falling through", td.Extension, td.UUID)
		http.Error(w, "UNSUPPORTED_EXTENSION", http.StatusBadRequest)
		return
	}

	streamID := td.StreamID
	if streamID == 0 && td.ChannelInfo.StreamID > 0 {
		streamID = td.ChannelInfo.StreamID
	}

	// Resolve server_id and proxy_id from channel_info (like PHP)
	serverID := a.serverID
	var proxyID int
	if td.ChannelInfo.OriginatorID != nil && *td.ChannelInfo.OriginatorID != 0 {
		serverID = *td.ChannelInfo.OriginatorID
		proxyID = td.ChannelInfo.RedirectID
	} else if td.ChannelInfo.RedirectID != 0 {
		serverID = td.ChannelInfo.RedirectID
		proxyID = 0
	}

	log.Printf("auth: stream=%d uuid=%s user=%d ip=%s ext=%s prebuf=%v",
		streamID, td.UUID, td.UserInfo.ID, clientIP, td.Extension, td.Prebuffer)

	// Check stream has segments — on-demand start if needed
	// For HLS, also need the m3u8 playlist file on disk
	currentSeg := a.tsHandler.findCurrentSegment(streamID)
	if currentSeg < 0 {
		// No segments: check if this is an on-demand stream or proxy
		isProxy := td.ChannelInfo.Proxy != 0
		isOnDemand := td.ChannelInfo.OnDemand == 1
		if a.onDemand != nil && (isOnDemand || isProxy) {
			log.Printf("auth: stream %d has no segments, attempting start (on_demand=%d proxy=%v)", streamID, td.ChannelInfo.OnDemand, isProxy)
			result := a.onDemand.StartAndWait(streamID, isProxy)
			if !result.OK {
				log.Printf("auth: on-demand start failed for stream=%d: %s", streamID, result.Error)
				http.Error(w, "WAIT_TIME_EXPIRED", http.StatusNotFound)
				return
			}
			// Re-check for segments after successful start
			currentSeg = a.tsHandler.findCurrentSegment(streamID)
			if currentSeg < 0 {
				log.Printf("auth: stream %d started but no segments found", streamID)
				http.Error(w, "STREAM_NOT_FOUND", http.StatusNotFound)
				return
			}
			log.Printf("auth: on-demand stream %d started successfully, current_seg=%d", streamID, currentSeg)
		} else {
			log.Printf("auth: stream %d has no segments (on_demand=%d proxy=%v)", streamID, td.ChannelInfo.OnDemand, isProxy)
			http.Error(w, "STREAM_NOT_FOUND", http.StatusNotFound)
			return
		}
	}

	// ── HLS: override UUID with deterministic key (PHP hlsConnectionKey) ──
	// Repeated playlist requests from the same player reuse ONE connection.
	connUUID := td.UUID
	container := "ts"
	if isHLS {
		isHMAC := td.HMACID != nil
		connUUID = hlsConnectionKey(isHMAC, td.Identifier, td.UserInfo.ID, streamID, clientIP, userAgent)
		container = "hls"
	}

	// ── 5. Check disallow_2nd_ip_con (PHP parity) ──
	if a.tracker != nil && a.disallow2ndIPCon != 0 &&
		td.UserInfo.IsRestreamer == 0 && td.HMACID == nil {
		// Check if user's max_connections qualifies
		checkIP := false
		if a.disallow2ndIPMax == 0 {
			checkIP = td.UserInfo.MaxConnections > 0
		} else {
			checkIP = td.UserInfo.MaxConnections > 0 && td.UserInfo.MaxConnections <= a.disallow2ndIPMax
		}
		if checkIP {
			acceptedIP, err := a.tracker.CheckUserIP(td.UserInfo.ID)
			if err == nil && acceptedIP != "" && !a.tracker.IPsMatch(acceptedIP, clientIP) {
				log.Printf("auth: disallow_2nd_ip_con: user=%d existing_ip=%s new_ip=%s",
					td.UserInfo.ID, acceptedIP, clientIP)
				http.Error(w, "USER_ALREADY_CONNECTED", http.StatusForbidden)
				return
			}
		}
	}

	// ── 6. Create/update connection in lines_live ──
	var stopCh chan struct{}
	if a.tracker != nil {
		extDevice := ""
		if td.ExternalDevice != nil {
			extDevice = *td.ExternalDevice
		}

		conn := &LiveConnection{
			UserID:           td.UserInfo.ID,
			StreamID:         streamID,
			ServerID:         serverID,
			ProxyID:          proxyID,
			UserAgent:        userAgent,
			UserIP:           clientIP,
			Container:        container,
			PID:              0, // Go-served = pid 0
			DateStart:        td.ActivityStart,
			GeoIPCountryCode: td.CountryCode,
			ISP:              td.UserInfo.ConISPName,
			ExternalDevice:   extDevice,
			HLSLastRead:      now,
			UUID:             connUUID,
			OnDemand:         td.ChannelInfo.OnDemand,
		}
		if td.HMACID != nil {
			conn.HMACID = td.HMACID
			conn.HMACIdentifier = td.Identifier
		}

		// Delete any old closed row with same UUID (like PHP does)
		a.tracker.DeleteClosedByUUID(connUUID)

		// Check for existing connection
		existing, _ := a.tracker.LookupConnection(connUUID)
		if existing != nil {
			// ── 7. Check restrict_same_ip on existing connection (PHP parity) ──
			if a.restrictSameIP != 0 && !a.tracker.IPsMatch(existing.UserIP, clientIP) {
				log.Printf("auth: restrict_same_ip: uuid=%s existing_ip=%s new_ip=%s",
					connUUID, existing.UserIP, clientIP)
				http.Error(w, "IP_MISMATCH", http.StatusForbidden)
				return
			}
			// Update existing (refresh hls_last_read for HLS, or reconnect for TS)
			err = a.tracker.UpdateConnectionReuse(connUUID, serverID, proxyID, 0, now)
			if err != nil {
				log.Printf("auth: failed to update existing connection uuid=%s: %v", connUUID, err)
			}
		} else {
			// Check create_expiration
			executionTime := time.Now().Unix() - td.ActivityStart
			expiresAt := td.ActivityStart + int64(a.createExpiration) + executionTime - int64(a.timeOffset)
			if time.Now().Unix() > expiresAt {
				log.Printf("auth: create_expiration exceeded for uuid=%s", connUUID)
				http.Error(w, "TOKEN_EXPIRED", http.StatusForbidden)
				return
			}
			// Create new connection
			err = a.tracker.CreateConnection(conn)
			if err != nil {
				log.Printf("auth: failed to create connection uuid=%s: %v", connUUID, err)
				http.Error(w, "LINE_CREATE_FAIL", http.StatusInternalServerError)
				return
			}
		}

		// ── 8. Enforce max_connections (PHP parity: validateConnections) ──
		if td.UserInfo.MaxConnections > 0 {
			if td.HMACID == nil {
				// Regular user
				a.tracker.EnforceMaxConnections(td.UserInfo.ID, td.UserInfo.MaxConnections, connUUID, clientIP, userAgent)
				if td.UserInfo.PairID != nil && *td.UserInfo.PairID > 0 {
					a.tracker.EnforceMaxConnections(*td.UserInfo.PairID, td.UserInfo.MaxConnections, connUUID, clientIP, userAgent)
				}
			} else {
				// HMAC identity
				a.tracker.EnforceMaxConnectionsHMAC(*td.HMACID, td.Identifier, td.UserInfo.MaxConnections, connUUID, clientIP, userAgent)
			}
		}

		// ── HLS: serve playlist and return (no persistent connection) ──
		if isHLS {
			// Create cons_tmp with IP for segment handler validation
			writeConsTmpWithIP(a.tsHandler.consTmpPath, connUUID, clientIP)

			// Generate tokenized HLS playlist
			playlist, err := generateHLSPlaylist(a.tsHandler.streamsPath, streamID, connUUID)
			if err != nil {
				log.Printf("auth: HLS playlist generation failed for stream=%d: %v", streamID, err)
				http.Error(w, "PLAYLIST_NOT_FOUND", http.StatusNotFound)
				return
			}

			log.Printf("auth: serving HLS playlist stream=%d uuid=%s", streamID, connUUID)

			w.Header().Set("Content-Type", "application/x-mpegurl")
			w.Header().Set("Cache-Control", "no-store, no-cache, must-revalidate")
			w.Header().Set("Content-Length", strconv.Itoa(len(playlist)))
			w.Write([]byte(playlist))
			return
		}

		// ── TS: register for heartbeat — returns stopCh that signals external disconnect ──
		stopCh = a.tracker.Register(connUUID, streamID)
		defer a.tracker.Unregister(connUUID)

		// On-demand instant off queue tracking (matching PHP addToQueue/removeFromQueue)
		if a.onDemand != nil && td.ChannelInfo.OnDemand == 1 {
			a.onDemand.AddToQueue(streamID, connUUID)
			defer a.onDemand.RemoveFromQueue(streamID, connUUID)
		}
	}

	// ── 9. Calculate prebuffer ──
	prebufferSec := td.GetPrebufferSec(a.clientPrebuffer, a.restrPrebuffer, a.segTime)

	log.Printf("auth: serving stream=%d uuid=%s prebuffer=%ds", streamID, connUUID, prebufferSec)

	// ── 10. Delegate to TS delivery (reuses the core chase-read loop) ──
	a.tsHandler.DeliverTS(w, r, streamID, prebufferSec, connUUID, stopCh)
}
