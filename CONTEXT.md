# XC_VM — Domain Glossary

Terms as the code and docs use them. Use these words, not synonyms.

## Streaming

**Producer**
: The process that produces a live stream's bytes: ffmpeg, `xc_fanout remux`, the proxy, the LLOD / delay / loopback workers, or a source driver's engine. Core recognises a running producer by its executable and the stream's playlist (`<id>_.m3u8`) in its command line. Not a format: MPEG-TS and HLS are what a producer outputs.
_Avoid_: encoder (only ffmpeg encodes), worker.

**Source driver**
: A module class (`SourceDriverInterface`) that owns a URL scheme for live sources and builds the producer command for them. It decides who runs a source; it is not the running process. See `docs/en/development/source-drivers.md`.
_Avoid_: resolver (yt-dlp's `needsResolver` is URL resolution, not this), source handler.

**Supervisor**
: The `xc_fanout` daemon when it starts, watches, restarts and adopts a stream's producer from a spec (`StreamProcess::buildSupervisorSpec`). With supervision off, the PHP monitor (`console.php monitor`) does the same job.
