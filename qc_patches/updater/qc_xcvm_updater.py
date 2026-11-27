#!/usr/bin/env python3
"""QC XC_VM updater: installs the QC modifications on an XC_VM Main or load balancer.

  python3 qc_xcvm_updater.py --dry-run          # show what would change, touch nothing
  python3 qc_xcvm_updater.py                    # install (backup first, PHP lint, auto-rollback on error)
  python3 qc_xcvm_updater.py --restart          # same + restart XC_VM so PHP/nginx reload the files
  python3 qc_xcvm_updater.py --rollback /root/qc_xcvm_backup_YYYYmmdd_HHMMSS
Supports XC_VM 2.7.2 and 2.7.4. Installs missing system tools (patch, iproute2) automatically. Options: --role main|lb (auto-detected), --no-deps (skip odrelay/livehold/ckts/mpdproxy/ffmpeg), --force (other XC_VM version).
Every modification is active after install: companion services are enabled and started, odrelay/livehold/ckts apply to all channels ("*"), and live copy channels run on the Go engine (ffmpeg-golive) at their next (re)start.
No channels, database rows, proxies or credentials are shipped: only code, empty templates and empty tables.
"""
import argparse, glob, hashlib, json, os, re, shutil, subprocess, sys, time

XC = os.environ.get("QC_XC_ROOT", "/home/xc_vm")
PKG = os.path.dirname(os.path.abspath(__file__))
PHP = os.environ.get("QC_PHP", f"{XC}/bin/php/bin/php")
MPD, ADS = list(range(8098, 8114)), list(range(8115, 8130))
DEP_PORTS = {"odrelay": [8147], "livehold": [8150], "ckts": [8160] + ADS, "mpdproxy": MPD}
DEP_UNITS = {"odrelay": ["odrelay.service"], "livehold": ["livehold.service"],
             "ckts": ["ckts.service"] + [f"mpdproxy-ads@{p}.{k}" for p in ADS for k in ("socket", "service")],
             "mpdproxy": [f"mpdproxy@{p}.{k}" for p in MPD for k in ("socket", "service")]}
ALL_LISTS = {f"{XC}/config/odrelay_streams.txt": "odrelay", f"{XC}/config/livehold_streams.txt": "livehold",
             "/etc/qc_ckts_ids": "ckts"}
CLEARKEY = ("ckts", "mpdproxy")  # another build of these is replaced by ours (moved aside, --rollback puts it back)
LEGACY_UNITS = {"mpdproxy": ["mpdproxy.socket", "mpdproxy.service"], "ckts": []}
FAMILY = {"mpdproxy": ("mpdproxy",), "ckts": ("ckts", "mpdproxy-ads")}
SYSD = "/etc/systemd/system"


def md5(p):
    return hashlib.md5(open(p, "rb").read()).hexdigest()


def sh(cmd, check=False, **kw):
    r = subprocess.run(cmd, shell=isinstance(cmd, str), capture_output=True, text=True, **kw)
    if check and r.returncode:
        raise RuntimeError(f"{cmd}: {r.stderr.strip() or r.stdout.strip()}")
    return r


def say(msg):
    print(msg, flush=True)


def targets(path):
    if "*" in path:
        return sorted(glob.glob(f"{XC}/{path}"))
    return [f"{XC}/{path}"]


TOOLS = {"patch": "patch", "ss": "iproute2", "systemctl": "systemd"}


def ensure_tools():
    """Install the system tools the updater needs (apt, dnf or yum)."""
    missing = sorted({pkg for cmd, pkg in TOOLS.items() if not shutil.which(cmd)})
    if not missing:
        return
    say(f"installing system packages: {', '.join(missing)}")
    if shutil.which("apt-get"):
        env = dict(os.environ, DEBIAN_FRONTEND="noninteractive")
        sh(["apt-get", "update", "-qq"], env=env)
        r = sh(["apt-get", "install", "-y", "-qq"] + missing, env=env)
    elif shutil.which("dnf") or shutil.which("yum"):
        r = sh([shutil.which("dnf") or shutil.which("yum"), "install", "-y"] + missing)
    else:
        sys.exit(f"cannot install {missing}: no apt/dnf/yum found")
    still = [c for c in TOOLS if not shutil.which(c)]
    if r.returncode or still:
        sys.exit(f"package install failed ({r.stderr.strip()[-200:]}); missing: {still}")


SQL_RUNNER = r"""<?php
require '%s/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI, ['process' => 'XC_VM[QC-Updater]']);
$db = \XcVm\Infrastructure\Database\DatabaseFactory::get();
if (!$db) { \XcVm\Infrastructure\Database\DatabaseFactory::connect(); $db = \XcVm\Infrastructure\Database\DatabaseFactory::get(); }
foreach (array_filter(array_map('trim', explode(';', file_get_contents($argv[1])))) as $q) {
	if (!$db->query($q)) { fwrite(STDERR, "SQL failed: " . substr($q, 0, 60) . "\n"); exit(1); }
}
echo "ok\n";
"""


def run_sql(path):
    """Create the tables through XC_VM's own DB connection (its credentials), else the mariadb/mysql client."""
    runner = "/tmp/qc_sql_runner.php"
    open(runner, "w").write(SQL_RUNNER % XC)
    r = sh([PHP, runner, path], cwd="/tmp") if os.path.exists(PHP) else None
    os.remove(runner)
    if r and r.returncode == 0:
        return True, ""
    cli = shutil.which("mariadb") or shutil.which("mysql")
    if cli:
        r2 = sh(f"{cli} xc_vm < {path}")
        if r2.returncode == 0:
            return True, ""
        return False, r2.stderr.strip()
    return False, (r.stderr.strip() if r else "no PHP/mysql client")


LB_LIST = r"""<?php
require '%s/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI, ['process' => 'XC_VM[QC-Updater]']);
$db = \XcVm\Infrastructure\Database\DatabaseFactory::get();
if (!$db) { \XcVm\Infrastructure\Database\DatabaseFactory::connect(); $db = \XcVm\Infrastructure\Database\DatabaseFactory::get(); }
$db->query('SELECT `server_name`, `server_ip` FROM `servers` WHERE `is_main` = 0 AND `server_type` = 0 AND `enabled` = 1 ORDER BY `id`');
echo json_encode($db->get_rows());
"""
SSH_KEY = "/root/.ssh/qc_xcvm_updater"  # own key: another key of this Main may be restricted on the LB (forced command)
SSH_OPT = ["-i", SSH_KEY, "-o", "IdentitiesOnly=yes", "-o", "StrictHostKeyChecking=accept-new", "-o", "ConnectTimeout=15", "-o", "ServerAliveInterval=30"]


def list_lbs():
    runner = "/tmp/qc_lb_list.php"
    open(runner, "w").write(LB_LIST % XC)
    r = sh([PHP, runner], cwd="/tmp"); os.remove(runner)
    if r.returncode:
        sys.exit(f"cannot read the load balancers from the database: {r.stderr.strip()[-200:]}")
    return json.loads(r.stdout or "[]")


def ssh_ready(ip, port):
    """Root access by key from this Main; asks the root password once and installs the key when missing."""
    base = ["ssh", "-p", str(port)] + SSH_OPT + [f"root@{ip}"]
    if not os.path.exists(SSH_KEY):
        os.makedirs("/root/.ssh", mode=0o700, exist_ok=True)
        sh(["ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-C", "qc_xcvm_updater", "-f", SSH_KEY], check=True)
    if sh(base[:1] + ["-o", "BatchMode=yes"] + base[1:] + ["true"]).returncode == 0:
        return base
    if not sys.stdin.isatty():
        say(f"  no SSH key access to root@{ip}: run once from a terminal to enter its root password"); return None
    import getpass
    if not shutil.which("sshpass"):
        apt_install(["sshpass"])
    pw = getpass.getpass(f"  root password for {ip} (asked once, a key is installed): ")
    r = sh(["sshpass", "-e", "ssh-copy-id", "-f", "-i", SSH_KEY + ".pub", "-p", str(port), "-o", "StrictHostKeyChecking=accept-new", f"root@{ip}"], env=dict(os.environ, SSHPASS=pw))
    if r.returncode or sh(base[:1] + ["-o", "BatchMode=yes"] + base[1:] + ["true"]).returncode:
        say(f"  could not log in as root@{ip}: {r.stderr.strip()[-150:]}"); return None
    return base


def push_lbs(a):
    """From the Main: update every enabled load balancer, one at a time, stopping at the first failure."""
    lbs = list_lbs()
    say(f"load balancers: {', '.join(x['server_name'] for x in lbs) or 'none'}")
    tgz = "/tmp/qc_xcvm_updater.tgz"
    sh(["tar", "czf", tgz, "-C", os.path.dirname(PKG), os.path.basename(PKG)], check=True)
    flags = [f for f, on in (("--restart", a.restart), ("--no-deps", a.no_deps), ("--force", a.force), ("--dry-run", a.dry_run)) if on]
    done = []
    for lb in lbs:
        name, ip = lb["server_name"], lb["server_ip"]
        say(f"=== {name} ({ip})")
        base = ssh_ready(ip, a.ssh_port)
        if not base:
            sys.exit(f"stopped at {name}: no SSH access. Updated: {', '.join(done) or 'none'}")
        if sh(["scp", "-P", str(a.ssh_port)] + SSH_OPT + [tgz, f"root@{ip}:/root/qc_xcvm_updater.tgz"]).returncode:
            sys.exit(f"stopped at {name}: copy failed. Updated: {', '.join(done) or 'none'}")
        cmd = "cd /root && rm -rf qc_xcvm_pkg && tar xzf qc_xcvm_updater.tgz && python3 qc_xcvm_pkg/qc_xcvm_updater.py --role lb " + " ".join(flags)
        r = subprocess.run(base + [cmd], text=True)
        if r.returncode:
            sys.exit(f"stopped at {name}: update failed (it rolled itself back). Updated: {', '.join(done) or 'none'}")
        done.append(name)
    say(("dry run done on: " if a.dry_run else "all load balancers updated: ") + (", ".join(done) or "none"))


def resolve(e, ver):
    """Per-version file/diff/md5 for core files; new files and templates are version independent."""
    v = (e.get("v") or {}).get(ver)
    if v:
        e = dict(e, **v)
    else:
        e = dict(e, file=f"files/{e['role']}/{e['path']}")
    return e


def older_qc(cur, ours):
    """True when the file already carries QC changes from an earlier run of this updater (replaced by the new version)."""
    marks = lambda p: set(re.findall(r"QC[-_][A-Z0-9][A-Z0-9_-]+|QCIPTV|QC ClearKey|od_rearm|od_v2", open(p, errors="ignore").read()))
    return bool(marks(cur))


KEEP_LOCAL = f"{XC}/config/qc_keep_local.txt"  # one path per line (relative to the XC_VM folder): never touched by updates


def kept_local():
    if not os.path.exists(KEEP_LOCAL):
        return set()
    return {os.path.join(XC, l.strip().lstrip("/")) if not l.strip().startswith(XC) else l.strip()
            for l in open(KEEP_LOCAL) if l.strip() and not l.lstrip().startswith("#")}


def nginx_blocks_present(t, diff):
    """True when every nginx location the diff adds already exists in the file (added in another order earlier)."""
    added = [l[1:].strip() for l in open(diff) if l.startswith("+") and not l.startswith("+++") and l[1:].strip().startswith("location")]
    cur = {l.strip() for l in open(t, errors="ignore")}
    return bool(added) and all(a in cur for a in added)


def plan(entries, ver):
    out = []
    keep = kept_local()
    for e in (resolve(x, ver) for x in entries):
        tg = targets(e["path"])
        if not tg:
            out.append((e, None, "absent (module not installed) - skipped")); continue
        for t in tg:
            if t in keep and os.path.exists(t):
                out.append((e, t, "keep (local)")); continue
            if e["kind"] == "template":
                out.append((e, t, "keep (exists)" if os.path.exists(t) else "create")); continue
            if not os.path.exists(t):
                out.append((e, t, "create" if e["kind"] == "new" else "MISSING core file - skipped")); continue
            cur = md5(t)
            if cur == e["ours_md5"] or (t.endswith(".conf") and e.get("diff") and nginx_blocks_present(t, f"{PKG}/{e['diff']}")):
                out.append((e, t, "already installed"))
            elif e["kind"] == "new":
                out.append((e, t, "replace"))
            elif not e.get("patch_only") and cur == e.get("pristine_md5"):
                out.append((e, t, "replace"))
            elif not e.get("patch_only") and older_qc(t, f"{PKG}/{e['file']}"):
                out.append((e, t, "upgrade"))
            elif sh(["patch", "--dry-run", "-s", "-f", "-p1", "-d", "/", t, f"{PKG}/{e['diff']}"]).returncode == 0:
                out.append((e, t, "patch"))
            else:
                out.append((e, t, "overwrite"))  # unknown local change: replaced by the QC version (backup kept)
    return out


def backup_dir():
    d = f"/root/qc_xcvm_backup_{time.strftime('%Y%m%d_%H%M%S')}"
    n = 1
    while os.path.exists(d + ("" if n == 1 else f"_{n}")):
        n += 1
    d += "" if n == 1 else f"_{n}"
    os.makedirs(d)
    return d


def linter():
    """First PHP binary that can lint a valid file (XC_VM's own php, then the system one)."""
    ok = "/tmp/qc_lint_ok.php"
    open(ok, "w").write("<?php echo 1;\n")
    for b in (PHP, shutil.which("php")):
        if b and os.path.exists(b) and sh([b, "-n", "-l", ok]).returncode == 0:
            return b
    return None


def place(src, dst):
    """Copy via a temp file + rename: works even when dst is a running program."""
    tmp = dst + ".qcnew"
    shutil.copy2(src, tmp); os.replace(tmp, dst)


def save(bk, t, created):
    if os.path.exists(t):
        dst = f"{bk}/files{t}"
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        shutil.copy2(t, dst)
    else:
        created.append(t)


def own(t):
    shutil.chown(t, "xc_vm", "xc_vm")
    d = os.path.dirname(t)
    while d.startswith(XC + "/") and d != XC:
        shutil.chown(d, "xc_vm", "xc_vm"); d = os.path.dirname(d)


def rollback(bk):
    meta = json.load(open(f"{bk}/backup.json"))
    for t in meta["created"]:
        if os.path.isfile(t):
            os.remove(t)
    for dp, _, fs in os.walk(f"{bk}/files"):
        for f in fs:
            s = os.path.join(dp, f); t = s[len(f"{bk}/files"):]
            os.makedirs(os.path.dirname(t), exist_ok=True); place(s, t)
    for u in meta.get("units", []):
        sh(["systemctl", "disable", "--now", u])
    for u in meta.get("unit_files", []):
        if os.path.exists(u):
            os.remove(u)
    for orig, moved in reversed(meta.get("moved", [])):
        if os.path.lexists(moved):
            if os.path.isdir(orig) and not os.path.islink(orig):
                shutil.rmtree(orig)
            os.replace(moved, orig)
    sh(["systemctl", "daemon-reload"])
    for u in meta.get("was_active", []):  # what ran before the update runs again (the other ClearKey build included)
        sh(["systemctl", "enable", u]); sh(["systemctl", "restart" if u.endswith(".service") else "start", u])
    for u in meta.get("stopped", []):
        sh(["systemctl", "enable", "--now", u])
    say(f"rollback done from {bk}")


def port_busy(p):
    return f":{p} " in sh(f"ss -ltnH 'sport = :{p}'").stdout + " "


MODULES_PHP = r"""$f = $argv[1]; $o = is_file($f) ? (include $f) : [];
if (!is_array($o)) { $o = []; }
foreach (json_decode($argv[2], true) as $n => $v) {
	if (empty($o[$n]['installed_version'])) { $o[$n] = array_merge($o[$n] ?? [], ['installed_version' => $v, 'source' => 'local']); }
}
file_put_contents($f, "<?php\n\nreturn " . var_export($o, true) . ";\n");"""


def register_modules(bk, meta):
    """Record the QC modules as installed in config/modules.php (what the panel's module manager does on install)."""
    mods = {}
    for mj in glob.glob(f"{XC}/Modules/*/module.json"):
        m = json.load(open(mj))
        if m.get("name") in ("refundcredit", "trialapi", "providerimport"):
            mods[m["name"]] = m.get("version", "1.0.0")
    f = f"{XC}/config/modules.php"
    save(bk, f, meta["created"])
    sh([PHP, "-n", "-r", MODULES_PHP, f, json.dumps(mods)], check=True)
    own(f)
    say(f"modules: {', '.join(sorted(mods))} registered as installed")


def apt_install(pkgs):
    if shutil.which("apt-get"):
        env = dict(os.environ, DEBIAN_FRONTEND="noninteractive")
        sh(["apt-get", "update", "-qq"], env=env)
        return sh(["apt-get", "install", "-y", "-qq"] + pkgs, env=env).returncode == 0
    m = shutil.which("dnf") or shutil.which("yum")
    return bool(m) and sh([m, "install", "-y"] + pkgs).returncode == 0


def install_ffaac(src):
    # ckts checks the sound of each DASH segment with "ffmpeg -f aac -i pipe:0 ... -f s16le pipe:1" (~30/s on a busy LB).
    # The distro ffmpeg loads ~200 libraries per run: route only that call to a small static AAC decoder (no ckts restart).
    os.makedirs("/opt/qc-ffaac", exist_ok=True)
    for b in ("ffaac", "ffmpeg-launch"):
        t = f"/opt/qc-ffaac/{b}"
        if not (os.path.exists(t) and md5(t) == md5(f"{src}/{b}")):
            place(f"{src}/{b}", t); os.chmod(t, 0o755)
    if md5("/usr/bin/ffmpeg") == md5("/opt/qc-ffaac/ffmpeg-launch"):
        return
    if "LOCAL" not in sh(["dpkg-divert", "--listpackage", "/usr/bin/ffmpeg"]).stdout:
        if os.path.exists("/usr/bin/ffmpeg.distrib"):
            os.remove("/usr/bin/ffmpeg.distrib")
        os.link("/usr/bin/ffmpeg", "/usr/bin/ffmpeg.distrib")
        sh(["dpkg-divert", "--local", "--no-rename", "--divert", "/usr/bin/ffmpeg.distrib", "--add", "/usr/bin/ffmpeg"])
    shutil.copy("/opt/qc-ffaac/ffmpeg-launch", "/usr/bin/ffmpeg.qcnew"); os.chmod("/usr/bin/ffmpeg.qcnew", 0o755)
    os.replace("/usr/bin/ffmpeg.qcnew", "/usr/bin/ffmpeg")
    ok = sh(["/usr/bin/ffmpeg", "-version"]).returncode == 0
    say("deps: ckts sound check uses the small AAC decoder" + ("" if ok else " - ffmpeg launcher check FAILED"))


def move_aside(p, meta):
    dst = f"{p}.qc-replaced_{time.strftime('%Y%m%d_%H%M%S')}"
    os.replace(p, dst); meta["moved"].append([p, dst])


def port_owners(p):
    out = sh(f"ss -ltnpH 'sport = :{p}'").stdout
    return [(c, int(pid)) for c, pid in re.findall(r'\("([^"]+)",pid=(\d+)', out)]


def take_over(name, want, meta):
    """Replaces another build of the ClearKey stack by ours. None if a port is held by an unrelated program."""
    took = False
    for u in LEGACY_UNITS[name]:
        if os.path.exists(f"{SYSD}/{u}"):
            sh(["systemctl", "disable", "--now", u]); meta["stopped"].append(u)
            move_aside(f"{SYSD}/{u}", meta); took = True
    for fam in FAMILY[name]:
        for conf in glob.glob(f"{SYSD}/{fam}@*.service.d/*.conf") + glob.glob(f"{SYSD}/{fam}.service.d/*.conf"):
            if "ExecStart" in open(conf, errors="replace").read():
                move_aside(conf, meta); took = True
    p = f"/opt/{name}"
    if os.path.lexists(p) and (os.path.islink(p) or not os.path.isdir(p)):
        move_aside(p, meta); took = True
    if took:
        sh(["systemctl", "daemon-reload"])
    socks = sh(["systemctl", "list-sockets", "--all", "--no-legend"]).stdout.splitlines()
    for port in DEP_PORTS[name]:
        for comm, pid in port_owners(port):
            if comm == "systemd":
                for line in socks:
                    f = line.split()
                    if len(f) > 1 and f[0].endswith(f":{port}") and f[1] not in want:
                        sh(["systemctl", "disable", "--now", f[1]]); meta["stopped"].append(f[1]); took = True
            elif any(comm.startswith(x) for x in FAMILY[name]):
                unit = sh(["ps", "-o", "unit=", "-p", str(pid)]).stdout.strip()
                if unit and unit not in want and not unit.endswith(".scope"):
                    sh(["systemctl", "disable", "--now", unit]); meta["stopped"].append(unit); took = True
                elif not unit or unit.endswith(".scope"):
                    sh(["kill", str(pid)]); took = True
            else:
                say(f"deps: {name} NOT installed: port {port} is used by another program ({comm})"); return None
    if took:
        time.sleep(1)
        say(f"deps: {name} from another build replaced by this version (old files kept as *.qc-replaced_*)")
    return took


NEEDRESTART = "/etc/needrestart/conf.d/qc-no-autorestart.conf"


def no_autorestart(meta):
    """Unattended Ubuntu upgrades must never restart XC_VM / delivery services (it cut every channel at night)."""
    want = "# QC: updated libraries are listed, services are never restarted automatically\n$nrconf{restart} = 'l';\n"
    if os.path.exists(NEEDRESTART) and open(NEEDRESTART).read() == want:
        return
    os.makedirs(os.path.dirname(NEEDRESTART), exist_ok=True)
    if not os.path.exists(NEEDRESTART):
        meta["created"].append(NEEDRESTART)
    open(NEEDRESTART, "w").write(want); say("unattended upgrades: services are no longer restarted automatically")


def livehold_swap():
    """livehold drains viewers up to 4 h on stop (a restart refuses new viewers that long): swap only when nobody is held."""
    try:
        v = sum(x.get("viewers", 0) for x in json.loads(sh("curl -s -m 3 127.0.0.1:8150/stats").stdout).values())
    except Exception:
        v = None
    if v == 0:
        sh(["systemctl", "kill", "-s", "SIGKILL", "livehold.service"]); say("deps: livehold new version started")
    else:
        say(f"deps: livehold {v if v is not None else '?'} viewer(s) held - new version starts at the next restart")


def install_deps(bk, meta, role):
    no_autorestart(meta)
    d = f"{PKG}/deps"
    if not os.path.isdir(d):
        say("deps: not in package - skipped"); return
    ff = f"{XC}/bin/ffmpeg_bin/8.1-dash"
    if os.path.isdir(f"{d}/ffmpeg_8.1-dash"):  # ClearKey DASH build: always ours (an older copy is replaced, backup kept)
        os.makedirs(ff, exist_ok=True)
        for b in ("ffmpeg", "ffprobe"):
            if os.path.exists(f"{ff}/{b}") and md5(f"{ff}/{b}") == md5(f"{d}/ffmpeg_8.1-dash/{b}"):
                continue
            save(bk, f"{ff}/{b}", meta["created"]); place(f"{d}/ffmpeg_8.1-dash/{b}", f"{ff}/{b}")
            os.chmod(f"{ff}/{b}", 0o755); own(f"{ff}/{b}"); say(f"deps: FFmpeg 8.1-dash {b} installed")
    names = ("odrelay", "livehold", "ckts", "mpdproxy") if role == "lb" else ("ckts", "mpdproxy")  # ClearKey on every server
    if "ckts" in names and not shutil.which("ffmpeg"):
        say("deps: system ffmpeg (used by ckts) " + ("installed" if apt_install(["ffmpeg"]) else "COULD NOT be installed"))
    units, changed = [], set()
    for name in names:
        want = DEP_UNITS[name]
        dst_dir = f"/opt/{name}"
        if not os.path.isdir(f"{d}/{name}"):
            continue
        if name in CLEARKEY:
            meta.setdefault("was_active", []).extend(
                u for u in want if sh(["systemctl", "is-active", "--quiet", u]).returncode == 0)
            replaced = take_over(name, want, meta)
            if replaced is None:
                continue
            if replaced:
                changed.update(want)
        if os.path.exists(dst_dir) and not os.path.isdir(dst_dir):
            say(f"deps: /opt/{name} already exists (other install) - skipped"); continue
        running = sh(["systemctl", "is-active", "--quiet", want[0]]).returncode == 0
        exe = sh(["systemctl", "show", "-p", "ExecStart", want[0].replace(".socket", ".service")]).stdout
        if running and f"/opt/{name}/{name}" not in exe and name not in CLEARKEY:
            say(f"deps: {name} already runs from another build on this server - kept as is"); continue
        busy = [p for p in DEP_PORTS[name] if port_busy(p)] if not running and name not in CLEARKEY else []
        if busy:
            say(f"deps: {name} skipped, port(s) in use by another program: {busy}"); continue
        os.makedirs(dst_dir, exist_ok=True)
        for f in os.listdir(f"{d}/{name}"):
            t = f"{dst_dir}/{f}"
            if f == "offline.ts" and os.path.exists(t):
                continue  # keep the operator's own offline video
            if os.path.exists(t) and md5(t) == md5(f"{d}/{name}/{f}"):
                continue
            save(bk, t, meta["created"]); place(f"{d}/{name}/{f}", t)
            os.chmod(t, 0o644 if f.endswith(".ts") else 0o755)
            if f != "ffmpeg-golive":  # started per channel by XC_VM: replacing it must not restart odrelay (on-demand viewers)
                changed.update(want)
        for u in sorted({w.split("@")[0] + "@." + w.rsplit(".", 1)[1] if "@" in w else w for w in want}):
            t = f"/etc/systemd/system/{u}"
            if os.path.exists(t) and md5(t) == md5(f"{d}/systemd/{u}"):
                continue
            save(bk, t, meta["unit_files"]); place(f"{d}/systemd/{u}", t)
            changed.update(want)
        units += want
    if "ckts" in names and os.path.isdir(f"{d}/ffaac") and os.path.exists("/usr/bin/ffmpeg"):
        install_ffaac(f"{d}/ffaac")
    for lst, name in ALL_LISTS.items():
        if name in names and (not os.path.exists(lst) or not open(lst).read().strip()):
            save(bk, lst, meta["created"]); open(lst, "w").write("*\n")  # empty list = feature off: turn it on for all channels
            if lst.startswith(XC):
                own(lst)
    sh(["systemctl", "daemon-reload"], check=True)
    for u in units:  # restart only what changed: an unchanged running service keeps its viewers
        sh(["systemctl", "enable", u])
        if u == "livehold.service" and u in changed and sh(["systemctl", "is-active", "--quiet", u]).returncode == 0:
            livehold_swap(); continue
        sh(["systemctl", "restart" if u in changed and u.endswith(".service") else "start", u])
    meta["units"] = units
    bad = [u for u in units if sh(["systemctl", "is-active", "--quiet", u]).returncode]
    say(f"deps: {len(units)} services enabled and started" + (f", NOT active: {bad}" if bad else ", all active"))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--role", choices=["main", "lb"])
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--no-deps", action="store_true")
    ap.add_argument("--restart", action="store_true")
    ap.add_argument("--force", action="store_true")
    ap.add_argument("--rollback")
    ap.add_argument("--all-lbs", action="store_true", help="on the Main: also update every load balancer over SSH")
    ap.add_argument("--ssh-port", type=int, default=22)
    a = ap.parse_args()
    if os.geteuid():
        sys.exit("run as root")
    if os.uname().machine not in ("x86_64", "amd64"):
        sys.exit(f"unsupported CPU {os.uname().machine}: the bundled programs need a 64-bit Intel/AMD (x86_64) server")
    ensure_tools()
    if a.rollback:
        rollback(a.rollback); return
    man = json.load(open(f"{PKG}/manifest.json"))
    rel = open(f"{XC}/RELEASE_ID").read().strip() if os.path.exists(f"{XC}/RELEASE_ID") else "?"
    role = a.role or ("main" if os.path.isdir(f"{XC}/Ministra") else "lb")
    ver = next((v for v in man["xcvm_versions"] if rel.startswith(v + "+") or rel == v), None)
    say(f"XC_VM {rel} | role: {role} | supported: {', '.join(man['xcvm_versions'])}")
    if not ver:
        if not a.force:
            sys.exit("XC_VM version not supported: re-run with --force to try patching with the latest version's changes")
        ver = man["xcvm_versions"][-1]
    steps = plan([e for e in man["files"] if e["role"] == role], ver)
    for e, t, st in steps:
        say(f"  {st:<16} {t or e['path']}")
    conflicts = [t for _, t, st in steps if st.startswith("CONFLICT")]
    if conflicts and not a.force:
        sys.exit(f"{len(conflicts)} conflict(s): nothing changed (use --force to skip them)")
    if a.dry_run:
        say("dry run: nothing changed")
        if a.all_lbs and role == "main":
            push_lbs(a)
        return
    bk = backup_dir()
    meta = {"created": [], "unit_files": [], "units": [], "role": role, "moved": [], "stopped": []}
    try:
        changed = []
        for e, t, st in steps:
            if st not in ("create", "replace", "patch", "upgrade", "overwrite"):
                continue
            save(bk, t, meta["created"])
            os.makedirs(os.path.dirname(t), exist_ok=True)
            if st == "patch":
                sh(["patch", "-s", "-f", "-p1", "-d", "/", "--no-backup-if-mismatch", t, f"{PKG}/{e['diff']}"], check=True)
            else:
                shutil.copyfile(f"{PKG}/{e['file']}", t)
            own(t); changed.append(t)
        php = linter()
        bad = [t for t in changed if php and t.endswith(".php") and sh([php, "-n", "-l", t]).returncode]
        if bad:
            raise RuntimeError(f"PHP syntax error in {bad}")
        ngx = f"{XC}/bin/nginx/sbin/nginx"
        if any(t.endswith(".conf") and "/nginx/" in t for t in changed) and os.path.exists(ngx):
            r = sh([ngx, "-t", "-p", f"{XC}/bin/nginx/", "-c", f"{XC}/bin/nginx/conf/nginx.conf"])
            if r.returncode:
                raise RuntimeError(f"nginx config test failed: {(r.stderr or r.stdout).strip().splitlines()[0]}")
        say(f"files: {len(changed)} installed, " + ("PHP syntax OK" if php else "WARNING: no working PHP found, syntax not checked"))
        if role == "main":
            ok, err = run_sql(f"{PKG}/sql/main.sql")
            say("database: tables source_proxies, refund_history + argument proxy_id ready" if ok else f"database: FAILED {err}")
            register_modules(bk, meta)
        if not a.no_deps:
            install_deps(bk, meta, role)
    except Exception as ex:
        json.dump(meta, open(f"{bk}/backup.json", "w"))
        say(f"ERROR: {ex} -> rolling back"); rollback(bk); sys.exit(1)
    json.dump(meta, open(f"{bk}/backup.json", "w"))
    if a.restart:
        sh(["systemctl", "restart", "xc_vm"]); time.sleep(5)
        say(f"XC_VM restarted: {sh(['systemctl', 'is-active', 'xc_vm']).stdout.strip()}")
    else:
        say("restart XC_VM (systemctl restart xc_vm) for nginx/PHP to load the changes")
    say(f"backup: {bk}  (undo: python3 {sys.argv[0]} --rollback {bk})")
    if a.all_lbs and role == "main":
        push_lbs(a)


if __name__ == "__main__":
    main()
