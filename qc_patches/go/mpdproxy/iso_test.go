package main

import "testing"

func box(t string, n int) []byte {
	b := make([]byte, n)
	b[0], b[1], b[2], b[3] = byte(n>>24), byte(n>>16), byte(n>>8), byte(n)
	copy(b[4:], t)
	return b
}

func TestISO(t *testing.T) {
	seg := append(append(box("styp", 24), box("moof", 500)...), box("mdat", 100000)...)
	if !isoComplete(seg) {
		t.Fatal("complete seg rejected")
	}
	if isoComplete(seg[:len(seg)-1]) || isoComplete(seg[:600]) {
		t.Fatal("truncated seg accepted")
	}
	if !isoComplete([]byte{0x47, 0x40, 0x11, 0x10, 0, 0, 0, 0, 1}) {
		t.Fatal("ts rejected")
	}
}
