package main

import (
	"os"
	"syscall"
)

// flockExclusive takes an exclusive advisory lock (matching PHP flock(LOCK_EX)).
func flockExclusive(f *os.File) error {
	return syscall.Flock(int(f.Fd()), syscall.LOCK_EX)
}

// flockUnlock releases the advisory lock (matching PHP flock(LOCK_UN)).
func flockUnlock(f *os.File) error {
	return syscall.Flock(int(f.Fd()), syscall.LOCK_UN)
}
