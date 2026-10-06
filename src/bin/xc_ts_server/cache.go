package main

import (
	"sync"
	"time"
)

// CachedSegment holds one TS segment in memory.
type CachedSegment struct {
	Data      []byte
	Timestamp time.Time
	Size      int64
}

// StreamCache holds cached segments for one stream.
type StreamCache struct {
	mu       sync.RWMutex
	segments map[int]*CachedSegment // segment index → data
	maxSegs  int
	ttl      time.Duration
}

// SegmentCache is the global cache across all streams.
type SegmentCache struct {
	mu      sync.RWMutex
	streams map[int]*StreamCache // stream_id → cache
	maxSegs int
	ttl     time.Duration
}

func NewSegmentCache(maxSegments int, ttl time.Duration) *SegmentCache {
	sc := &SegmentCache{
		streams: make(map[int]*StreamCache),
		maxSegs: maxSegments,
		ttl:     ttl,
	}
	go sc.cleanupLoop()
	return sc
}

func (sc *SegmentCache) getOrCreate(streamID int) *StreamCache {
	sc.mu.Lock()
	defer sc.mu.Unlock()
	if s, ok := sc.streams[streamID]; ok {
		return s
	}
	s := &StreamCache{
		segments: make(map[int]*CachedSegment),
		maxSegs:  sc.maxSegs,
		ttl:      sc.ttl,
	}
	sc.streams[streamID] = s
	return s
}

// Put caches a segment.
func (sc *SegmentCache) Put(streamID, segIdx int, data []byte) {
	s := sc.getOrCreate(streamID)
	s.mu.Lock()
	defer s.mu.Unlock()

	// Copy data to avoid holding references to mmap'd memory
	copied := make([]byte, len(data))
	copy(copied, data)

	s.segments[segIdx] = &CachedSegment{
		Data:      copied,
		Timestamp: time.Now(),
		Size:      int64(len(copied)),
	}

	// Evict oldest if over limit
	if len(s.segments) > s.maxSegs {
		oldest := -1
		var oldestTime time.Time
		for idx, seg := range s.segments {
			if oldest == -1 || seg.Timestamp.Before(oldestTime) {
				oldest = idx
				oldestTime = seg.Timestamp
			}
		}
		if oldest >= 0 {
			delete(s.segments, oldest)
		}
	}
}

// Get returns a cached segment or nil.
func (sc *SegmentCache) Get(streamID, segIdx int) *CachedSegment {
	sc.mu.RLock()
	s, ok := sc.streams[streamID]
	sc.mu.RUnlock()
	if !ok {
		return nil
	}
	s.mu.RLock()
	defer s.mu.RUnlock()
	seg, ok := s.segments[segIdx]
	if !ok || time.Since(seg.Timestamp) > s.ttl {
		return nil
	}
	return seg
}

// StreamCount returns number of tracked streams.
func (sc *SegmentCache) StreamCount() int {
	sc.mu.RLock()
	defer sc.mu.RUnlock()
	return len(sc.streams)
}

// TotalSegments returns total cached segments.
func (sc *SegmentCache) TotalSegments() int {
	sc.mu.RLock()
	defer sc.mu.RUnlock()
	total := 0
	for _, s := range sc.streams {
		s.mu.RLock()
		total += len(s.segments)
		s.mu.RUnlock()
	}
	return total
}

func (sc *SegmentCache) cleanupLoop() {
	ticker := time.NewTicker(10 * time.Second)
	defer ticker.Stop()
	for range ticker.C {
		sc.mu.Lock()
		for sid, s := range sc.streams {
			s.mu.Lock()
			for idx, seg := range s.segments {
				if time.Since(seg.Timestamp) > s.ttl {
					delete(s.segments, idx)
				}
			}
			if len(s.segments) == 0 {
				delete(sc.streams, sid)
			}
			s.mu.Unlock()
		}
		sc.mu.Unlock()
	}
}
