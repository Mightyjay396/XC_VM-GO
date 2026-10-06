# XC_VM-GO Installation Guide

This fork adds a high-performance Go delivery server (`xc_ts_server`) to XC_VM.
Go handles live TS/HLS streams natively (auth + delivery), with automatic
PHP fallback when Go is not running. VOD uses PHP auth with Go file serving.

## Requirements

- Ubuntu 20/22/24 or Debian 11/12 (x86_64)
- Root access
- 2+ GB RAM
- Go 1.22+ (installed automatically during setup)

## Installation Methods

### Method 1: Build + Install (recommended)

Build the archive on a build machine, then install on the target server.

**On the build machine:**
```bash
git clone https://github.com/Mightyjay396/XC_VM-GO.git
cd XC_VM-GO

# For a MAIN server:
make main
# Creates: XC_VM.zip (includes Go binary, PHP, nginx, everything)

# For a Load Balancer:
make lb
# Creates: loadbalancer.tar.gz
```

**On the target server:**
```bash
# Copy the archive to the server, then:
python3 install
# The installer handles everything: MariaDB, nginx, PHP, Go, config
```

### Method 2: Direct Install (clone on target server)

Clone and install directly on the target server.

```bash
git clone https://github.com/Mightyjay396/XC_VM-GO.git
cd XC_VM-GO

# For a MAIN server:
sudo make install-local TYPE=main

# For a Load Balancer:
sudo make install-local TYPE=lb
```

### Method 3: Add Go to Existing XC_VM

If you already have XC_VM installed and want to add Go delivery:

```bash
# 1. Install Go
wget https://go.dev/dl/go1.22.10.linux-amd64.tar.gz
sudo tar -C /usr/local -xzf go1.22.10.linux-amd64.tar.gz

# 2. Clone the Go source
git clone https://github.com/Mightyjay396/XC_VM-GO.git /tmp/xc_vm_go
cd /tmp/xc_vm_go

# 3. Build the binary
cd src/bin/xc_ts_server
/usr/local/go/bin/go build -trimpath -ldflags="-s -w" -o xc_ts_server .

# 4. Deploy to your XC_VM installation
sudo mkdir -p /home/xc_vm/bin/xc_ts_server
sudo cp xc_ts_server run.sh xc_ts_server.sh /home/xc_vm/bin/xc_ts_server/
sudo chown -R xc_vm:xc_vm /home/xc_vm/bin/xc_ts_server
sudo chmod +x /home/xc_vm/bin/xc_ts_server/xc_ts_server
sudo chmod +x /home/xc_vm/bin/xc_ts_server/run.sh

# 5. Deploy PHP patches
sudo cp src/Public/stream/live.php /home/xc_vm/Public/stream/live.php
sudo cp src/Public/stream/vod.php /home/xc_vm/Public/stream/vod.php
sudo chown xc_vm:xc_vm /home/xc_vm/Public/stream/live.php /home/xc_vm/Public/stream/vod.php

# 6. Deploy nginx config
sudo cp lb_configs/go_ts_server.conf /home/xc_vm/bin/nginx/conf/go_ts_server.conf

# 7. Comment out the /auth/ rewrite in nginx.conf
sudo sed -i 's|rewrite ^/auth/|# rewrite ^/auth/|' /home/xc_vm/bin/nginx/conf/nginx.conf

# 8. Add include (if not already present)
grep -q 'go_ts_server.conf' /home/xc_vm/bin/nginx/conf/nginx.conf || \
  sudo sed -i '/include custom.conf/a\        include go_ts_server.conf;' /home/xc_vm/bin/nginx/conf/nginx.conf

# 9. Test and reload nginx
sudo -u xc_vm /home/xc_vm/bin/nginx/sbin/nginx -t
sudo -u xc_vm /home/xc_vm/bin/nginx/sbin/nginx -s reload

# 10. Start Go server
sudo -u xc_vm bash /home/xc_vm/bin/xc_ts_server/run.sh &

# 11. Verify
curl -s http://127.0.0.1:8089/health | python3 -m json.tool
```

## Verification

After installation, verify Go is running:

```bash
# Check health
curl -s http://127.0.0.1:8089/health | python3 -m json.tool

# Expected output includes:
# "ok": true, "auth_native": true, "hls_native": true, "vod_native": true

# Check logs
tail -f /home/xc_vm/bin/xc_ts_server/xc_ts_server.log
```

## Disabling Go (emergency rollback)

If you need to disable Go and fall back to PHP:

```bash
# Option 1: Stop Go (PHP fallback activates automatically via nginx)
touch /home/xc_vm/bin/xc_ts_server/disabled
pkill -u xc_vm xc_ts_server

# Option 2: Re-enable PHP rewrite (bypasses Go entirely)
sudo sed -i 's|# rewrite ^/auth/|rewrite ^/auth/|' /home/xc_vm/bin/nginx/conf/nginx.conf
sudo -u xc_vm /home/xc_vm/bin/nginx/sbin/nginx -s reload
```

To re-enable Go:
```bash
rm /home/xc_vm/bin/xc_ts_server/disabled
sudo -u xc_vm bash /home/xc_vm/bin/xc_ts_server/run.sh &
```

## Architecture

```
Go running (default):
  /auth/<token> → Go (full pipeline: auth + delivery)
  /vauth/<token> → PHP vod.php → X-Accel → Go (file serving)

Go stopped (automatic fallback):
  /auth/<token> → Go (502) → @go_auth_fallback → PHP live.php
  /vauth/<token> → PHP vod.php (serves file itself)
```

See `src/bin/xc_ts_server/README.md` for detailed documentation.
