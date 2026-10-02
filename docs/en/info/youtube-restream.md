# Restreaming YouTube

XC_VM can take a YouTube live broadcast as a stream source and restream it to your
lines like any other channel. You add the YouTube page link as the source; the panel
turns it into a playable stream with the bundled `yt-dlp` and pulls it with ffmpeg.

For this to work on a server in a data center you need an **HTTP proxy for each
YouTube stream**. This page explains why, how to set it up, and how to troubleshoot it.

---

## Why a proxy is required

YouTube blocks data-center IP addresses. Without a proxy, a stream from a hosting
server fails with:

```
ERROR: [youtube] ...: Sign in to confirm you're not a bot.
```

Two things decide how the proxy must be used:

- **The playable link is tied to one IP.** The link YouTube hands out only plays from
  the IP that asked for it. The panel therefore sends *both* steps through the same
  proxy: getting the link (`yt-dlp`) and downloading the video (ffmpeg).
- **YouTube limits each IP.** Every start, restart and source check asks YouTube again,
  and the video itself is downloaded through the proxy. A few streams on one IP are
  enough to get that IP blocked. Give **each YouTube stream its own proxy IP**.

---

## Proxy requirements

| Requirement | Why |
|---|---|
| **HTTP proxy** (`http://`) | ffmpeg cannot use SOCKS proxies. A `socks5://` proxy lets the panel get the link, but the video download then goes out directly and fails. |
| **Not a hosting / data-center IP** | YouTube blocks data-center ranges. Use a residential or mobile proxy, or a server on a home connection. |
| **One IP per stream** | Spreads YouTube's per-IP limit. With a residential provider, use a separate sticky session per stream. |
| **Sticky, not rotating per request** | The link must be downloaded from the IP that got it, for as long as the stream runs (the link lives about 6 hours). |
| **Enough traffic** | All video goes through the proxy: about 3–6 Mbit/s for a 1080p stream. Residential proxies are usually billed per GB. |

Accepted formats for the proxy value:

```
ip:port
http://ip:port
http://user:password@host:port
```

A value without a scheme (`ip:port`) is treated as `http://ip:port`.

---

## Setting up a YouTube stream

1. Open **Streams → Add Stream**.
2. On the **Details** tab, give the stream a name and category as usual.
3. On the **Sources** tab, add the YouTube link of the live broadcast, for example:

    ```
    https://www.youtube.com/watch?v=rFZHOHl-L8A
    ```

    Supported link forms are `https://www.youtube.com/watch?v=...` and
    `https://youtu.be/...`.

4. On the **Advanced** tab, fill in **HTTP Proxy** with this stream's proxy, for example:

    ```
    http://user:password@proxy.example.com:8080
    ```

5. On the same **Advanced** tab, leave **Direct Source** off. With direct source the
   panel sends viewers to the YouTube link itself, which does not play outside the
   proxy's IP.
6. Choose the server(s) on the **Servers** tab and save. Start the stream.

The stream is resolved and downloaded on the server that runs it. On a load balancer the
same proxy is used from that node, so every node that runs YouTube streams needs to be
able to reach the proxy. To check a stream before going live, use the command in
[Troubleshooting](#troubleshooting) on the server that will run it.

---

## What is supported

- **YouTube live broadcasts:** supported. The panel picks a single stream that carries
  both video and audio (up to 1080p).
- **Regular (non-live) YouTube videos:** not supported for now. YouTube refuses their
  download links to the panel's `yt-dlp` without a JavaScript runtime, even through a
  proxy.
- **Other platforms resolved the same way:** Twitch, Vimeo, Dailymotion, Facebook,
  Livestream, Ustream and CNN links also go through `yt-dlp` and use the stream's proxy
  the same way.

`yt-dlp` is updated automatically on every server once a day, so changes on YouTube's
side are usually picked up without a panel update.

---

## Troubleshooting

The quickest check is to run `yt-dlp` on the server the way the panel does, as the
`xc_vm` user and with the stream's proxy:

```bash
sudo -u xc_vm /home/xc_vm/bin/yt-dlp \
  --proxy 'http://user:password@proxy.example.com:8080' \
  --extractor-args 'youtube:player_client=default,android_vr' \
  -q --get-url --skip-download -f best \
  'https://www.youtube.com/watch?v=VIDEO_ID'
```

A working setup prints one `https://manifest.googlevideo.com/...` link.

| Symptom | Cause | Fix |
|---|---|---|
| `Sign in to confirm you're not a bot` | No proxy on the stream, or the proxy's IP is blocked or flagged as hosting. | Set **HTTP Proxy** on the stream; use a residential/home IP; give the stream an IP no other stream uses. |
| `Requested format is not available` | The panel version predates YouTube restream support. | Update the panel. |
| The link is found, but the stream does not start or segments fail (`403 Forbidden`, `Failed to open segment`) | The video is downloaded from a different IP than the link was fetched from, usually because the proxy is SOCKS. | Use an HTTP proxy. |
| Works for a while, then stops for several streams at once | Several YouTube streams share one proxy IP and it got rate-limited. | One proxy IP per stream. |
| `Unable to connect to proxy` / timeouts | The proxy is unreachable from this server or the login is wrong. | Test it with `curl -x 'http://user:password@host:port' https://api.ipify.org` on the server; it should print the proxy's IP. |

!!! note
    The proxy login and password are stored in the stream's settings and appear in the
    ffmpeg command line on the server (visible in `ps`). Use credentials dedicated to
    this purpose.
