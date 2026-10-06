package main

import (
	"encoding/binary"
	"log"
	"path/filepath"
	"regexp"
	"strconv"
	"sync"

	"golang.org/x/sys/unix"
)

// StreamWatcher uses inotify to watch for new TS segments in the streams directory.
// Instead of PHP's sleep/poll loop, we get instant notification when ffmpeg writes.
type StreamWatcher struct {
	streamsPath string
	fd          int
	mu          sync.RWMutex
	listeners   map[int][]chan int // stream_id → notification channels
	stopCh      chan struct{}
}

var segmentRe = regexp.MustCompile(`^(\d+)_(\d+)\.ts$`)

func NewStreamWatcher(streamsPath string) *StreamWatcher {
	fd, err := unix.InotifyInit1(unix.IN_NONBLOCK | unix.IN_CLOEXEC)
	if err != nil {
		log.Printf("WARNING: inotify init failed: %v — falling back to polling", err)
		fd = -1
	}

	w := &StreamWatcher{
		streamsPath: streamsPath,
		fd:          fd,
		listeners:   make(map[int][]chan int),
		stopCh:      make(chan struct{}),
	}

	if fd >= 0 {
		_, err = unix.InotifyAddWatch(fd, streamsPath, unix.IN_CLOSE_WRITE|unix.IN_MOVED_TO)
		if err != nil {
			log.Printf("WARNING: inotify add watch failed: %v", err)
			unix.Close(fd)
			w.fd = -1
		}
	}

	return w
}

// Subscribe returns a channel that receives new segment indices for a stream.
func (w *StreamWatcher) Subscribe(streamID int) chan int {
	ch := make(chan int, 32)
	w.mu.Lock()
	w.listeners[streamID] = append(w.listeners[streamID], ch)
	w.mu.Unlock()
	return ch
}

// Unsubscribe removes a listener channel.
func (w *StreamWatcher) Unsubscribe(streamID int, ch chan int) {
	w.mu.Lock()
	defer w.mu.Unlock()
	subs := w.listeners[streamID]
	for i, sub := range subs {
		if sub == ch {
			w.listeners[streamID] = append(subs[:i], subs[i+1:]...)
			break
		}
	}
	if len(w.listeners[streamID]) == 0 {
		delete(w.listeners, streamID)
	}
}

func (w *StreamWatcher) Run() {
	if w.fd < 0 {
		return // no inotify
	}

	buf := make([]byte, 65536)
	epfd, err := unix.EpollCreate1(unix.EPOLL_CLOEXEC)
	if err != nil {
		log.Printf("epoll create failed: %v", err)
		return
	}
	defer unix.Close(epfd)

	event := unix.EpollEvent{Events: unix.EPOLLIN, Fd: int32(w.fd)}
	if err := unix.EpollCtl(epfd, unix.EPOLL_CTL_ADD, w.fd, &event); err != nil {
		log.Printf("epoll ctl failed: %v", err)
		return
	}

	events := make([]unix.EpollEvent, 8)

	for {
		select {
		case <-w.stopCh:
			return
		default:
		}

		// epoll_wait with 500ms timeout
		n, err := unix.EpollWait(epfd, events, 500)
		if err != nil {
			if err == unix.EINTR {
				continue
			}
			return
		}
		if n == 0 {
			continue
		}

		// Read inotify events
		nbytes, err := unix.Read(w.fd, buf)
		if err != nil || nbytes <= 0 {
			continue
		}

		w.parseEvents(buf[:nbytes])
	}
}

func (w *StreamWatcher) parseEvents(buf []byte) {
	offset := 0
	for offset+unix.SizeofInotifyEvent <= len(buf) {
		nameLen := binary.LittleEndian.Uint32(buf[offset+12 : offset+16])
		eventEnd := offset + unix.SizeofInotifyEvent + int(nameLen)
		if eventEnd > len(buf) {
			break
		}

		if nameLen > 0 {
			nameBytes := buf[offset+unix.SizeofInotifyEvent : eventEnd]
			name := extractName(nameBytes)

			if matches := segmentRe.FindStringSubmatch(name); matches != nil {
				streamID, _ := strconv.Atoi(matches[1])
				segIdx, _ := strconv.Atoi(matches[2])
				w.notify(streamID, segIdx)
			}
		}

		offset = eventEnd
	}
}

func extractName(b []byte) string {
	for i, c := range b {
		if c == 0 {
			return string(b[:i])
		}
	}
	return string(b)
}

func (w *StreamWatcher) notify(streamID, segIdx int) {
	w.mu.RLock()
	defer w.mu.RUnlock()
	for _, ch := range w.listeners[streamID] {
		select {
		case ch <- segIdx:
		default:
		}
	}
}

func (w *StreamWatcher) Stop() {
	close(w.stopCh)
	if w.fd >= 0 {
		unix.Close(w.fd)
	}
}

// SegmentExists checks if a segment file exists.
func SegmentExists(streamsPath string, streamID, segIdx int) bool {
	path := filepath.Join(streamsPath, strconv.Itoa(streamID)+"_"+strconv.Itoa(segIdx)+".ts")
	var stat unix.Stat_t
	return unix.Stat(path, &stat) == nil
}
