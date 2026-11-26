ckts audio check helper (XC_VM-GO 2.7.4-p6)
- ckts runs `$CKTS_FFMPEG -v quiet -f aac -i pipe:0 -ac 1 -ar N -f s16le pipe:1` ~30x/s (silence check).
- /usr/bin/ffmpeg (Ubuntu, 215 shared libs) cost ~73 ms CPU + 4.3k page faults per call -> zone-lock contention bursts.
- ffaac: static FFmpeg 6.1.1, only AAC decoder + s16le (configure flags in build.sh), 15 ms / 570 faults. PCM identical within 1 LSB.
- fflaunch.c: installed as /usr/bin/ffmpeg via `dpkg-divert --local --no-rename --divert /usr/bin/ffmpeg.distrib` (no ckts restart);
  routes only the exact ckts decode pattern to /opt/qc-ffaac/ffaac, everything else to /usr/bin/ffmpeg.distrib.
- Shipped from 2.7.4-p6 in bin/qc/ffaac/ (ffaac + ffmpeg-launch), installed by qc_deps.sh on load balancers.
- Rollback: mv /usr/bin/ffmpeg.distrib over /usr/bin/ffmpeg (hardlink of the original) && dpkg-divert --remove --no-rename /usr/bin/ffmpeg
