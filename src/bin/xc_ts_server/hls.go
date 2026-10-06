package main

import (
	"crypto/md5"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

// HLSHandler serves HLS playlists and individual segments for Go-native HLS.
// Playlists are served from auth.go (after token validation); segments are
// served by this handler at /auth/seg/<file>?uuid=<uuid>.
//
// Security model:
//   - UUID is deterministic (hlsConnectionKey) — not guessable
//   - cons_tmp file existence validates the connection is active
//   - IP is validated against the IP stored in the cons_tmp file
//
// PHP parity: replaces segment.php for on-disk live HLS segments when
// fanout is off (VIA_LEGACY path in FanoutMode::hlsDelivery).
type HLSHandler struct {
	tracker        *Tracker
	streamsPath    string
	consTmpPath    string
	restrictSameIP int
	ipSubnetMatch  int
}

func NewHLSHandler(tracker *Tracker, streamsPath, consTmpPath string, restrictSameIP, ipSubnetMatch int) *HLSHandler {
	return &HLSHandler{
		tracker:        tracker,
		streamsPath:    streamsPath,
		consTmpPath:    consTmpPath,
		restrictSameIP: restrictSameIP,
		ipSubnetMatch:  ipSubnetMatch,
	}
}

var hlsSegRe = regexp.MustCompile(`^/auth/seg/(.+)$`)

// ServeSegment handles: GET /auth/seg/<segment_file>?uuid=<uuid>
// This is the HLS segment delivery endpoint. Each segment URL in the
// tokenized m3u8 playlist points here.
func (h *HLSHandler) ServeSegment(w http.ResponseWriter, r *http.Request) {
	matches := hlsSegRe.FindStringSubmatch(r.URL.Path)
	if matches == nil {
		http.NotFound(w, r)
		return
	}

	segFile := matches[1]
	uuid := r.URL.Query().Get("uuid")
	if uuid == "" {
		http.Error(w, "missing uuid", http.StatusBadRequest)
		return
	}

	// ── 1. Validate connection: cons_tmp file must exist ──
	consTmpFile := filepath.Join(h.consTmpPath, uuid)
	storedData, err := os.ReadFile(consTmpFile)
	if err != nil {
		// Connection no longer active (enforcement closed it, or admin kill)
		log.Printf("hls-seg: uuid=%s cons_tmp not found, returning 404", uuid)
		http.NotFound(w, r)
		return
	}

	// ── 2. Validate IP (restrict_same_ip, PHP parity) ──
	if h.restrictSameIP != 0 && len(storedData) > 0 {
		storedIP := strings.TrimSpace(string(storedData))
		clientIP := getClientIP(r)
		if storedIP != "" && clientIP != "" {
			if h.ipSubnetMatch != 0 {
				if !sameSubnetStr(storedIP, clientIP) {
					log.Printf("hls-seg: uuid=%s IP mismatch (subnet) stored=%s client=%s", uuid, storedIP, clientIP)
					http.NotFound(w, r)
					return
				}
			} else if storedIP != clientIP {
				log.Printf("hls-seg: uuid=%s IP mismatch stored=%s client=%s", uuid, storedIP, clientIP)
				http.NotFound(w, r)
				return
			}
		}
	}

	// ── 3. Sanitize segment filename (prevent path traversal) ──
	segFile = filepath.Base(segFile)
	if segFile == "." || segFile == ".." || segFile == "" {
		http.NotFound(w, r)
		return
	}

	// ── 4. Find and serve the segment file ──
	segPath := filepath.Join(h.streamsPath, segFile)
	stat, err := os.Stat(segPath)
	if err != nil || stat.IsDir() {
		http.NotFound(w, r)
		return
	}

	// Set content type based on extension
	ext := strings.ToLower(filepath.Ext(segFile))
	switch ext {
	case ".m4s", ".mp4":
		w.Header().Set("Content-Type", "video/iso.segment")
	default:
		w.Header().Set("Content-Type", "video/mp2t")
	}
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Cache-Control", "no-store, no-cache, must-revalidate")

	// Serve the file
	http.ServeFile(w, r, segPath)
}

// hlsConnectionKey generates a deterministic UUID for HLS connections,
// matching PHP ConnectionTracker::hlsConnectionKey(). Repeated playlist
// requests from the same player reuse ONE tracked connection instead of
// creating a new row each time — preventing balloon on channel switch.
func hlsConnectionKey(isHMAC bool, identifier string, userID, streamID int, ip, userAgent string) string {
	hash := md5.Sum([]byte(ip + userAgent))
	hashStr := fmt.Sprintf("%x", hash)
	if isHMAC {
		return fmt.Sprintf("hmac_%s_%d_%s", identifier, streamID, hashStr)
	}
	return fmt.Sprintf("user_%d_%d_%s", userID, streamID, hashStr)
}

// generateHLSPlaylist reads the on-disk m3u8 and rewrites segment URLs
// to point to the Go segment handler. Matches PHP HLSGenerator::generateHLS()
// for the VIA_LEGACY (fanout off) path.
//
// Input: on-disk m3u8 at STREAMS_PATH/<stream_id>_.m3u8
// Output: tokenized m3u8 with segment URLs like: seg/<file>?uuid=<uuid>
func generateHLSPlaylist(streamsPath string, streamID int, uuid string) (string, error) {
	m3u8Path := filepath.Join(streamsPath, fmt.Sprintf("%d_.m3u8", streamID))
	content, err := os.ReadFile(m3u8Path)
	if err != nil {
		return "", fmt.Errorf("m3u8 not found: %w", err)
	}

	source := string(content)
	lines := strings.Split(source, "\n")
	replaced := 0

	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") {
			// Handle #EXT-X-MAP:URI="<file>" for fMP4 init segments
			if strings.Contains(trimmed, "#EXT-X-MAP:URI=") {
				mapRe := regexp.MustCompile(`URI="([^"]+)"`)
				lines[i] = mapRe.ReplaceAllStringFunc(trimmed, func(match string) string {
					m := mapRe.FindStringSubmatch(match)
					if len(m) > 1 {
						return fmt.Sprintf(`URI="seg/%s?uuid=%s"`, filepath.Base(m[1]), uuid)
					}
					return match
				})
			}
			continue
		}
		// Non-comment, non-empty line: should be a segment filename
		if strings.HasSuffix(trimmed, ".ts") || strings.HasSuffix(trimmed, ".m4s") {
			lines[i] = fmt.Sprintf("seg/%s?uuid=%s", trimmed, uuid)
			replaced++
		}
	}

	if replaced == 0 {
		return "", fmt.Errorf("m3u8 has no segments")
	}

	return strings.Join(lines, "\n"), nil
}

// writeConsTmpWithIP creates the cons_tmp file with the client IP stored in it.
// Unlike TS (which uses an empty file), HLS stores the IP so the segment
// handler can validate it without a DB lookup on each request.
func writeConsTmpWithIP(consTmpPath, uuid, clientIP string) {
	if consTmpPath == "" || uuid == "" {
		return
	}
	consTmpFile := filepath.Join(consTmpPath, uuid)
	os.WriteFile(consTmpFile, []byte(clientIP), 0644)
}

// sameSubnetStr checks if two IPs are in the same /24 subnet (IPv4)
// or /48 subnet (IPv6). Simple string-based check matching PHP.
func sameSubnetStr(ip1, ip2 string) bool {
	// For IPv4: compare first 3 octets
	parts1 := strings.Split(ip1, ".")
	parts2 := strings.Split(ip2, ".")
	if len(parts1) == 4 && len(parts2) == 4 {
		return parts1[0] == parts2[0] && parts1[1] == parts2[1] && parts1[2] == parts2[2]
	}
	// For IPv6: compare first 3 groups (48 bits)
	groups1 := strings.Split(ip1, ":")
	groups2 := strings.Split(ip2, ":")
	if len(groups1) >= 3 && len(groups2) >= 3 {
		return groups1[0] == groups2[0] && groups1[1] == groups2[1] && groups1[2] == groups2[2]
	}
	return ip1 == ip2
}

// getClientIP extracts the real client IP from the request.
func getClientIP(r *http.Request) string {
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
	return clientIP
}

// consTmpExists checks if a connection's cons_tmp file exists.
func consTmpExists(consTmpPath, uuid string) bool {
	if consTmpPath == "" || uuid == "" {
		return false
	}
	_, err := os.Stat(filepath.Join(consTmpPath, uuid))
	return err == nil
}

// Used for non-used import prevention
var _ = strconv.Itoa
