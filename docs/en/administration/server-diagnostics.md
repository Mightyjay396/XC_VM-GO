# Server Diagnostics (`server:diagnose`)

The panel marks a proxy/LB node **offline** purely from a stale heartbeat (`enabled` + `status = 1` + a recent `last_check_ago`), which never tells you *why* the node stopped reporting. The `server:diagnose` command answers that question.

```bash
/home/xc_vm/console.php server:diagnose [server_id]
```

The command is **read-only**: it only runs ping/curl/`fsockopen` probes, `SELECT` queries, and `sudo -n iptables -nL`. It never restarts or reconfigures anything.

Implemented by `src/Cli/Commands/ServerDiagnoseCommand.php`. The command ships in **both** MAIN and LB builds — the local mode is the whole point of having it on the node.

---

## Two Modes

The mode is auto-selected from where the command runs (`server_id` in `config.ini` → `is_main` in the `servers` table):

### Mode A — remote probe from the MAIN

```bash
sudo /home/xc_vm/console.php server:diagnose <server_id>
```

Probes the target node from the outside and reads its panel-side state:

| Check | What it tells you |
| --- | --- |
| Enabled / Status / Heartbeat | How the panel currently sees the node (`status = 4` means a failed install/provision) |
| ICMP ping | Is the host alive at all |
| TCP to `http_broadcast_port` | Is nginx reachable, or is the port dropped |
| HTTP `GET /api` | Does PHP actually answer behind nginx |
| Clock offset | `time_offset` vs the panel (skew > 30 s can flap the node online/offline) |
| Signal queue | Unconsumed rows in `signals` for this node (backlog > 120 s = the node's callback loop is stuck) |
| OPENSSL_EXTRA | Whether the node holds the main's `OPENSSL_EXTRA` (see [below](#repairing-an-openssl_extra-mismatch)). `unknown (node not updated)` / `unknown (main not updated)`: that side runs a build that does not publish it yet. Not checked for proxies |

The probe combinations map to causes:

- **No ICMP + port closed** — node powered off, network-partitioned, or fully firewalled.
- **ICMP replies but the port is dropped** — the classic: the node's own iptables blocked the main's IP (RootSignals flood/block false-positive), or nginx/the service is down. Run the local mode on the node for the exact cause.
- **Port open but `/api` silent** — nginx is up, PHP is not: check php-fpm on the node.
- **`/api` answers but the heartbeat is stale** — the node's watchdog daemon (the heartbeat writer) is not running, or it cannot write to the panel DB. Run the local mode on the node.

### Mode B — local self-diagnosis ON the node

```bash
sudo /home/xc_vm/console.php server:diagnose
```

Run this **on the silent LB/proxy node itself** — the causes usually live there. No argument needed; the node identifies itself from `config.ini`. Checks:

1. **My own panel row** — enabled/status/heartbeat as the main sees them.
2. **Can I reach the MAIN** — DB connectivity (implicitly proven), ICMP, and TCP to the main's broadcast port.
3. **Did I firewall the main?** — scans this node's `iptables INPUT` chain for a `DROP` of the main's IP, plus the flood-block marker file. This is the classic "node went silent for no reason" cause: the flood protection drops public IPs silently, and the main's callbacks stop landing.
4. **Service / nginx** — `systemctl is-active xc_vm` and a local TCP check on the node's own broadcast port.
5. **Watchdog daemon** — the actual heartbeat writer: the `watchdog` daemon updates `last_check_ago` every few seconds. When its MySQL connection to the main drops it **waits for the database to come back** (retrying every 5 s) and resumes the heartbeat immediately. Older builds exited instead — which made **all nodes go offline at the same moment** on any MySQL restart/blip on the main, until `cron:servers` revived them.
6. **Babysitter cron** — three sub-checks, because a dead watchdog stays dead only when the babysitter chain is broken:
   - is `cron:servers` present in the **`xc_vm` user's crontab** (if missing, regenerate with `rm -f /home/xc_vm/tmp/crontab` and restart the service);
   - is the system **cron service** active (no cron → the crontab never fires);
   - is a previous `cron:servers` instance **hung on its cron lock** — a hung instance blocks every subsequent run for up to 30 minutes (`acquireCronLock` stale timeout), which is exactly how one DB blip keeps a node offline for half an hour. The command prints the holding PID and the kill command.
7. **Clock skew** — `time_offset` vs the panel.
8. **OPENSSL_EXTRA** — whether this node holds the main's value, as in Mode A.

> **Note:** the iptables check requires passwordless sudo (`sudo -n`). Without it the check reports `cannot check (need sudo iptables)` instead of failing — run the command as `root` for a full picture.

---

## Repairing an OPENSSL_EXTRA mismatch

`OPENSSL_EXTRA` (`config/openssl_extra`, or a built-in default when that file is absent) keys the stream tokens the main mints for the redirects an LB serves (`/auth`, `/vauth`, `/tsauth`, `/thauth`, subtitles). A main and its LBs must hold the same value; an LB that holds another one rejects all of those tokens, so playback redirected from the main fails there. The current installer gives a new main a random value, and LBs added by `server:install` before it shipped that file to them run on the default.

Each node's `cron:servers` publishes a fingerprint of its value (never the value itself) in `servers.server_hardware`, and the OPENSSL_EXTRA check compares the node's with the main's. To repair a mismatch, run on the **MAIN**:

```bash
sudo /home/xc_vm/console.php server:sync-openssl-extra <server_id>
sudo /home/xc_vm/console.php server:sync-openssl-extra --all
```

- The command queues one root signal with the main's value for each streaming LB that reports another fingerprint. The LB's `cron:root_signals` applies it within a minute: it writes `config/openssl_extra` (0600, owned by `xc_vm`) and php-fpm uses it from the next request, with no restart. The value stays in the `signals` table until then.
- For **10 minutes** afterwards the LB still accepts tokens it minted with its old value (kept in `config/openssl_extra.prev`), so links it has just handed out keep playing. Legacy-format tokens (`secure_stream_tokens` off) can still be refused in that window, about 1 in 256.
- LBs already in sync, LBs that publish no fingerprint (not updated yet) and offline LBs are skipped. `--force` queues them anyway; an offline LB applies the signal if it comes back within a day (queued signals expire after 24 hours). Proxies and the main are never sent the value.
- When the main has **no** `config/openssl_extra`, it runs on the built-in default and there is nothing to send. An LB that still reports a mismatch has a stale `config/openssl_extra` of its own: delete it on that LB (`sudo rm -f /home/xc_vm/config/openssl_extra`); the change applies at the next request.
- The command sends nothing when the main publishes another fingerprint than its `config/openssl_extra` holds: either `xc_vm` cannot read that file, so the main's php-fpm runs on the default (`sudo chown xc_vm:xc_vm /home/xc_vm/config/openssl_extra && sudo chmod 600 /home/xc_vm/config/openssl_extra`), or the file changed within the last minute (wait for `cron:servers`). It also sends nothing when the main has not published a fingerprint yet.

Check the result with `server:diagnose <server_id>` a minute or two later, after the LB's next `cron:servers` run.

---

## Nodes report `STARTING` (the cluster API pools)

On the MAIN, the cluster API (`/cluster/v1/`) runs on two PHP-FPM pools of its own, `cluster_ctl` and `cluster_ingest`, next to the panel's pools. Until both answer, the API replies `503 STARTING` to every call but `health`, and the nodes' agents back off and report the MAIN as degraded. After a boot, a restart or an update this lasts until `status` has run the migrations and found both pools answering, usually a few seconds.

When it lasts longer, check on the MAIN:

```bash
sudo /home/xc_vm/console.php status                    # ends with "Cluster API pools are ready." or "... not answering yet."
sudo -u xc_vm /home/xc_vm/console.php cluster:pools    # starts or resizes the pools, as xc_vm; exit code 0 once both answer
ls -l /home/xc_vm/tmp/cluster_ready                    # present while the API serves
ls -l /home/xc_vm/bin/php/etc/cluster/                 # cluster_ctl.conf, cluster_ingest.conf
ps -eo pid,user,args | grep 'master process (/home/xc_vm/bin/php/etc/cluster/'
```

- The two masters run as `xc_vm` and name their config: `php-fpm: master process (/home/xc_vm/bin/php/etc/cluster/cluster_ctl.conf)`. The panel's masters name `bin/php/etc/<n>.conf`.
- `cron:servers` checks the pools every minute: it starts a pool whose master is gone and resizes both as servers are added or removed. A resize reloads the pool and does not interrupt the API.
- `cluster:pools` refuses to run as root. Run it as `xc_vm`, as above.
- A pool that will not start: run `sudo -u xc_vm /home/xc_vm/bin/php/sbin/php-fpm -t -y /home/xc_vm/bin/php/etc/cluster/cluster_ctl.conf` to see why.

---

## The cluster API's nginx config

On the MAIN, the nginx route for the cluster API is written by XC_VM, not fixed in `nginx.conf`:

| File in `/home/xc_vm/bin/nginx/conf/` | What it holds |
| --- | --- |
| `cluster_locations.conf` | The `/cluster/v1/` route, included by the main web server. It passes to the cluster pools and allows each LB 100 requests a second (bursts of 400; above that nginx answers `429`) |
| `cluster.d/listen.conf` | Only when **Cluster API Port** is not `0`: a plain-HTTP server on that port. It serves `/cluster/v1/` and answers `404` to anything else |
| `cluster.d/old_port.conf` | For 7 days after the port the LBs use changes (the HTTP broadcast port, or the Cluster API Port): the old port keeps serving `/cluster/v1/` alone, so an LB that missed the change still finds the MAIN |

`status` writes these files at every boot and after an update, a port change writes them at once, and a job checks them against the settings every minute. A change is kept only when `nginx -t` passes. A new Cluster API Port must also be free, and nginx must be serving it right after the reload. Otherwise the previous files are put back, and saving the new port fails with the reason. To write them again and see what nginx says, run on the MAIN:

```bash
sudo -u xc_vm /home/xc_vm/console.php cluster:nginx    # exit code 0 when the files are current
ls -l /home/xc_vm/bin/nginx/conf/cluster.d/
```

- Do not edit these files: the next write replaces them.
- `cluster:nginx` refuses to run as root. Run it as `xc_vm`, as above.
- A Cluster API Port other than `0` must be open from the LBs to the MAIN in every firewall between them.
- The first write removes `cluster_legacy.conf`, which earlier releases used for old ports; `cluster.d/old_port.conf` replaces it.

---

## Output & Exit Codes

Each check prints one aligned `[OK]`/`[WARN]` line, followed by a numbered **Probable cause(s)** summary with the exact fix command where one exists (e.g. the `iptables -D INPUT ... -j DROP` unblock line).

| Exit code | Meaning |
| --- | --- |
| `0` | No obvious cause found (or the target is the MAIN itself — nothing to diagnose) |
| `1` | Usage error: missing/unknown `server_id` |
| `2` | One or more probable causes were found and printed |

Example (local mode, self-blocked main):

```text
Self-diagnosis on node #3 — LB-Frankfurt (type 1)
----------------------------------------------------------------
[OK]   Enabled          yes
[OK]   Status           1 (online)
[WARN] Heartbeat        last check-in 641s ago (limit 180s)
[OK]   DB → main        reachable (this query ran)
[OK]   Ping main        reply (203.0.113.10)
[WARN] Main :8080       closed/timeout
[WARN] Main in iptables DROP present (+flood marker)
[OK]   Service xc_vm    active
[OK]   nginx :8080      listening
[OK]   watchdog daemon  running
[OK]   cron:servers     in xc_vm crontab
[OK]   Clock offset     2s vs panel
----------------------------------------------------------------
Probable cause(s):
  1. Heartbeat is stale (641s > 180s): the node stopped reporting — the checks below narrow down why.
  2. This node has DROPPED the main's IP 203.0.113.10 in its own iptables (flood/block false-positive). Unblock: `sudo iptables -D INPUT -s 203.0.113.10 -j DROP && sudo rm -f /home/xc_vm/tmp/flood/block_203.0.113.10`.
```

---

## Quick Reference — Common Causes

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| Pings, port dropped | Node's iptables blocked the main's IP | `sudo iptables -D INPUT -s <main_ip> -j DROP` + remove the `block_<ip>` marker (the command prints the exact line) |
| No ping, no port | Host down / network partition / external firewall | Check hosting console, routes, provider firewall |
| Port open, `/api` dead | php-fpm down | Restart the `xc_vm` service on the node |
| `/api` fine, heartbeat stale | Watchdog daemon dead (exits on DB connection loss) | `sudo -u xc_vm console.php watchdog` on the node; check DB grants (`tools mysql` on the main) |
| **All nodes drop at the same moment** | A MySQL restart/blip on the main hit every node's watchdog at once (fatal for pre-fix builds; current builds wait it out) | Check the main's MySQL error log around the drop time; update the nodes so the watchdog survives outages |
| Node flaps online/offline | Clock skew > 30 s (or recurring MySQL blips) | Sync NTP on the node; check MySQL stability on the main |
| Status = 4 | Install/provision errored | Re-run `server:install` from the main |
| Playback redirected from the main fails on one LB | `OPENSSL_EXTRA` mismatch (the check reports it) | `sudo /home/xc_vm/console.php server:sync-openssl-extra <server_id>` on the main (see [above](#repairing-an-openssl_extra-mismatch)) |
| Every node reports `STARTING` | The cluster API pools on the main are not answering | `sudo -u xc_vm /home/xc_vm/console.php cluster:pools` on the main (see [above](#nodes-report-starting-the-cluster-api-pools)) |
| Saving a new Cluster API Port fails: nginx refused it | `nginx -t` fails with the new port (the message quotes nginx), or nginx did not serve the port after the reload | Fix what nginx names, check that nginx runs, then save again (see [above](#the-cluster-apis-nginx-config)) |
| Saving a new Cluster API Port fails: another program listens on it | A service on the MAIN already uses that port | Pick another port, or stop that service (`ss -ltnp 'sport = :<port>'` names it) |
| `cluster:nginx` says `nginx.conf predates the rendered cluster config` | An update's `nginx.conf` failed `nginx -t` and the previous one was put back | Find why the release's `nginx.conf` failed `nginx -t` (the update log records the rollback), fix it, then update again |

---

## Related files

| File | Role |
| --- | --- |
| `src/Cli/Commands/ServerDiagnoseCommand.php` | The diagnostic command (both modes) |
| `src/Cli/Commands/WatchdogCommand.php` | The watchdog daemon — writes the heartbeat (`last_check_ago`) |
| `src/Cli/CronJobs/ServersCronJob.php` | Babysitter cron — relaunches a dead watchdog |
| `src/Cli/CronJobs/RootSignalsCronJob.php` | Applies iptables blocks (the false-positive source) and the `OPENSSL_EXTRA` repair signal |
| `src/Cli/Commands/ServerSyncOpensslExtraCommand.php` | `server:sync-openssl-extra` — queues the main's `OPENSSL_EXTRA` for mismatched LBs (MAIN only) |
| `src/Core/Config/OpensslExtra.php` | `OPENSSL_EXTRA` fingerprint, repair signal and the 10-minute fallback |
| `src/Domain/Cluster/ClusterPool.php` | The cluster API's FPM pools on the main and the `STARTING` marker |
| `src/Cli/Commands/ClusterPoolsCommand.php` | `cluster:pools` — starts or resizes the pools as `xc_vm` (MAIN only) |
| `src/Domain/Cluster/ClusterNginxConfig.php` | The cluster API's nginx config on the main: the route, the Cluster API Port and the old ports |
| `src/Cli/Commands/ClusterNginxCommand.php` | `cluster:nginx` — writes that config as `xc_vm`, tests it with `nginx -t` and reloads nginx (MAIN only) |
| `src/Domain/Server/ServerRepository.php` | `servers` table access |

See also: [CLI Tools](../guides/cli-tools.md), [Updating a Server](../administration/server-update.md).
