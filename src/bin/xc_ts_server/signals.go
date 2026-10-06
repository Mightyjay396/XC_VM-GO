package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"sync"
	"time"
)

// SignalPoller polls the XC_VM `signals` table for cache signals targeting
// this server (drop_con, delete_con) and acts on them. This handles admin
// kills for Go-served connections (pid=0) which the PHP signals daemon
// routes through FanoutClient::dropConnection() — a path that fails when
// the fanout daemon is not running.
//
// When redis_handler=1 and Redis is attached, also polls SIGNALS#<server_id>
// for Redis-based signals.
//
// Architecture:
//
//	Panel admin kill → closeConnection() → dropDaemonViewer()
//	 ├─ DELETE FROM lines_live (detected by heartbeat, ~5-15s)
//	 └─ SignalDispatcher::cache(server_id, {type:drop_con, uuid:...})
//	     → signals table → SignalPoller reads it → instant disconnect
type SignalPoller struct {
	db          *sql.DB
	tracker     *Tracker
	redis       *RedisTracker // may be nil
	serverID    int
	consTmpPath string
	interval    time.Duration
	stopCh      chan struct{}
	mu          sync.Mutex
}

func NewSignalPoller(db *sql.DB, tracker *Tracker, serverID int, consTmpPath string) *SignalPoller {
	return &SignalPoller{
		db:          db,
		tracker:     tracker,
		serverID:    serverID,
		consTmpPath: consTmpPath,
		interval:    500 * time.Millisecond,
		stopCh:      make(chan struct{}),
	}
}

// AttachRedis enables Redis signal polling.
func (sp *SignalPoller) AttachRedis(rt *RedisTracker) {
	sp.mu.Lock()
	sp.redis = rt
	sp.mu.Unlock()
}

// Start begins polling in a goroutine.
func (sp *SignalPoller) Start() {
	go sp.pollLoop()
	log.Printf("signal poller started: server_id=%d interval=%s", sp.serverID, sp.interval)
}

// Stop halts the polling loop.
func (sp *SignalPoller) Stop() {
	close(sp.stopCh)
}

func (sp *SignalPoller) pollLoop() {
	ticker := time.NewTicker(sp.interval)
	defer ticker.Stop()

	for {
		select {
		case <-sp.stopCh:
			return
		case <-ticker.C:
			sp.pollDBSignals()
			sp.pollRedisSignals()
		}
	}
}

// pollDBSignals reads cache signals from the `signals` table.
// Matches the flow in SignalsCommand::execute() for cache signals.
func (sp *SignalPoller) pollDBSignals() {
	rows, err := sp.db.Query(
		"SELECT `signal_id`, `custom_data` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 ORDER BY `signal_id` ASC LIMIT 50",
		sp.serverID,
	)
	if err != nil {
		return
	}
	defer rows.Close()

	var processedIDs []int64
	for rows.Next() {
		var signalID int64
		var customDataJSON string
		if err := rows.Scan(&signalID, &customDataJSON); err != nil {
			continue
		}

		var customData map[string]interface{}
		if err := json.Unmarshal([]byte(customDataJSON), &customData); err != nil {
			continue
		}

		sigType, _ := customData["type"].(string)
		switch sigType {
		case "drop_con":
			uuid, _ := customData["uuid"].(string)
			if uuid != "" {
				if sp.dropViewer(uuid) {
					log.Printf("signal: drop_con uuid=%s (signal_id=%d)", uuid, signalID)
					processedIDs = append(processedIDs, signalID)
				}
			}
		case "delete_con":
			uuid, _ := customData["uuid"].(string)
			if uuid != "" && sp.consTmpPath != "" {
				os.Remove(filepath.Join(sp.consTmpPath, uuid))
				log.Printf("signal: delete_con uuid=%s (signal_id=%d)", uuid, signalID)
				processedIDs = append(processedIDs, signalID)
			}
		}
		// Other cache signal types (update_stream, etc.) are left for the PHP daemon
	}

	// Delete only the signals we processed
	for _, id := range processedIDs {
		sp.db.Exec("DELETE FROM `signals` WHERE `signal_id` = ?", id)
	}
}

// pollRedisSignals checks SIGNALS#<server_id> when Redis is active.
func (sp *SignalPoller) pollRedisSignals() {
	sp.mu.Lock()
	rt := sp.redis
	sp.mu.Unlock()

	if rt == nil {
		return
	}

	// Read SIGNALS#<server_id> members
	members, err := rt.client.SMembers(rt.ctx, fmt.Sprintf("SIGNALS#%d", sp.serverID)).Result()
	if err != nil || len(members) == 0 {
		return
	}

	// Batch read signal data
	vals, err := rt.client.MGet(rt.ctx, members...).Result()
	if err != nil {
		return
	}

	var processedKeys []string
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
			continue
		}

		customData := data["custom_data"]
		if cdMap, ok := customData.(map[string]interface{}); ok {
			sigType := igGetStr(cdMap, "type", "")
			if sigType == "drop_con" {
				uuid := igGetStr(cdMap, "uuid", "")
				if uuid != "" && sp.dropViewer(uuid) {
					log.Printf("signal: redis drop_con uuid=%s key=%s", uuid, members[i])
					processedKeys = append(processedKeys, members[i])
				}
			}
		}

		// Also handle PID-based signals for Go viewers (pid=0 doesn't apply)
		pid := igGetInt(data, "pid", 0)
		if pid == 0 {
			processedKeys = append(processedKeys, members[i])
		}
	}

	// Clean up processed signals
	if len(processedKeys) > 0 {
		pipe := rt.client.TxPipeline()
		ifaces := make([]interface{}, len(processedKeys))
		for i, k := range processedKeys {
			ifaces[i] = k
		}
		pipe.Del(rt.ctx, processedKeys...)
		pipe.SRem(rt.ctx, fmt.Sprintf("SIGNALS#%d", sp.serverID), ifaces...)
		pipe.Exec(rt.ctx)
	}
}

// dropViewer disconnects a viewer by UUID. Returns true if the viewer was found.
func (sp *SignalPoller) dropViewer(uuid string) bool {
	if sp.tracker == nil {
		return false
	}
	return sp.tracker.DropByUUID(uuid)
}
