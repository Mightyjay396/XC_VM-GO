#!/usr/bin/env python3
"""Builds the fork's release assets: official XC_VM 2.7.4 + QC patches + Go engine, as XC_VM's own installer/updater expect them."""
import glob, hashlib, json, os, re, shutil, subprocess, sys, zipfile

VERSION, OWNER, REPO = "2.7.4-p9", "Mightyjay396", "XC_VM-GO"
PKG, OUT, SRC = "/root/qc_xcvm_pkg", "/tmp/qc_dist", {"main": "/tmp/r274_main", "lb": "/tmp/r274_lb"}
HERE = os.path.dirname(os.path.abspath(__file__))


def edit(path, old, new, count=1):
    s = open(path).read()
    assert s.count(old) >= 1, f"{path}: not found: {old[:60]}"
    open(path, "w").write(s.replace(old, new, count))


def build_tree(role):
    t = f"{OUT}/{role}"
    shutil.rmtree(t, ignore_errors=True); shutil.copytree(SRC[role], t, symlinks=True)
    for e in json.load(open(f"{PKG}/manifest.json"))["files"]:
        if e["role"] != role or "*" in e["path"]:
            continue  # wildcard entries patch optional modules that the release does not ship
        src = f"{PKG}/" + ((e.get("v") or {}).get("2.7.4", {}).get("file") or f"files/{role}/{e['path']}")
        dst = f"{t}/{e['path']}"
        if e["kind"] == "template" and os.path.exists(dst):
            continue
        os.makedirs(os.path.dirname(dst), exist_ok=True); shutil.copy2(src, dst)
    c = f"{t}/Core/Config/ConstantsInitializer.php"
    edit(c, "define('XC_VM_VERSION', '2.7.4')", f"define('XC_VM_VERSION', '{VERSION}')")
    edit(c, "'GIT_REPO_MAIN'     => 'XC_VM',", f"'GIT_OWNER_MAIN'    => '{OWNER}',\n\t\t\t'GIT_REPO_MAIN'     => '{REPO}',")
    edit(f"{t}/Core/Updates/UpdateChannels.php", "new GitHubReleases(GIT_OWNER, GIT_REPO_MAIN,", "new GitHubReleases(GIT_OWNER_MAIN, GIT_REPO_MAIN,")
    if role == "main":
        edit(f"{t}/Cli/Commands/LbInstallFlow.php", "'https://raw.githubusercontent.com/' . GIT_OWNER . '/' . GIT_REPO_MAIN", "'https://raw.githubusercontent.com/' . GIT_OWNER_MAIN . '/' . GIT_REPO_MAIN")
        edit(f"{t}/Cli/CronJobs/UpdateCronJob.php", "defined('GIT_OWNER') && defined('GIT_REPO_MAIN')", "defined('GIT_OWNER_MAIN') && defined('GIT_REPO_MAIN')")
        s = open(f"{t}/Cli/CronJobs/UpdateCronJob.php").read()
        open(f"{t}/Cli/CronJobs/UpdateCronJob.php", "w").write(re.sub(r"GIT_OWNER(?!_MAIN)(\s*\.\s*'/'\s*\.\s*GIT_REPO_MAIN|,\s*GIT_REPO_MAIN)", r"GIT_OWNER_MAIN\1", s))
        shutil.copy2(f"{PKG}/sql/main.sql" if os.path.exists(f"{PKG}/sql/main.sql") else f"{HERE}/main.sql", f"{t}/migrations/database/up/106_qc_tables.sql")
        mods = {}
        for mj in glob.glob(f"{t}/Modules/*/module.json"):
            m = json.load(open(mj))
            if m.get("name") in ("refundcredit", "trialapi", "providerimport"):
                mods[m["name"]] = m.get("version", "1.0.0")
        mp = f"{t}/config/modules.php"
        s = open(mp).read().rstrip()
        assert s.endswith("];"), "modules.php format"
        add = "".join(f"\t'{n}' => ['installed_version' => '{v}', 'source' => 'local'],\n" for n, v in sorted(mods.items()) if f"'{n}'" not in s)
        open(mp, "w").write(s[:-2].rstrip() + "\n" + add + "];\n")
    # Go engine + FFmpeg + installer hook, run as root by install_xcvm_core.sh (fresh install) and ./update (updates)
    q = f"{t}/bin/qc"
    os.makedirs(f"{q}/systemd", exist_ok=True)
    for n in (("odrelay", "livehold", "ckts", "mpdproxy", "ffaac") if role == "lb" else ("mpdproxy",)) + ("ffmpeg_8.1-dash",):
        shutil.copytree(f"{PKG}/deps/{n}", f"{q}/{n}")
    for u in glob.glob(f"{HERE}/systemd/*"):
        shutil.copy2(u, f"{q}/systemd/")
    open(f"{q}/qc_deps.sh", "w").write(open(f"{HERE}/dist/qc_deps.sh").read().replace("__ROLE__", role)); os.chmod(f"{q}/qc_deps.sh", 0o755)
    hook = "\n# QC: Go delivery engine shipped in this archive\n[ -x /home/xc_vm/bin/qc/qc_deps.sh ] && /home/xc_vm/bin/qc/qc_deps.sh || true\n"
    open(f"{t}/bin/install/install_xcvm_core.sh", "a").write(hook)
    edit(f"{t}/update", 'log_msg("INFO", "Running post-update...")', 'log_msg("INFO", "QC: installing the Go delivery engine...")\n        os.system("sudo bash /home/xc_vm/bin/qc/qc_deps.sh")\n        log_msg("INFO", "Running post-update...")')
    return t


def md5(p):
    h = hashlib.md5()
    with open(p, "rb") as f:
        for b in iter(lambda: f.read(1 << 20), b""):
            h.update(b)
    return h.hexdigest()


def main():
    os.makedirs(OUT, exist_ok=True)
    assets = {"main": "xc_vm.tar.gz", "lb": "loadbalancer.tar.gz"}
    if "--zip-only" not in sys.argv:
        for role, name in assets.items():
            t = build_tree(role)
            r = subprocess.run(["tar", "czf", f"{OUT}/{name}", "-C", t, "."])
            assert r.returncode == 0
    inst = open("/tmp/xzi/install").read()
    for a, b in (
                 ('    # Final restart\n    run_command("service xc_vm restart")',
                  '    # QC: Go delivery engine + FFmpeg 8.1-dash shipped in the archive\n'
                  '    if os.path.exists("/home/xc_vm/bin/qc/qc_deps.sh"):\n'
                  '        printc("Installing the Go delivery engine...", col.OKBLUE)\n'
                  '        run_command("bash /home/xc_vm/bin/qc/qc_deps.sh")\n\n'
                  '    # Final restart\n    run_command("service xc_vm restart")'),
                 ("https://github.com/Vateron-Media/XC_VM/releases\",", f"https://github.com/{OWNER}/{REPO}/releases\","),("https://github.com/Vateron-Media/{repo}/releases/", f"https://github.com/{OWNER}/{REPO}/releases/"),
                 ("https://api.github.com/repos/Vateron-Media/XC_VM/releases/latest", f"https://api.github.com/repos/{OWNER}/{REPO}/releases/latest"),
                 ("https://github.com/Vateron-Media/XC_VM/releases/download/", f"https://github.com/{OWNER}/{REPO}/releases/download/"),
                 ("https://github.com/Vateron-Media/XC_VM/releases/latest/download/", f"https://github.com/{OWNER}/{REPO}/releases/latest/download/")):
        assert a in inst, a
        inst = inst.replace(a, b)
    with zipfile.ZipFile(f"{OUT}/XC_VM.zip", "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("install", inst); z.write(f"{OUT}/xc_vm.tar.gz", "xc_vm.tar.gz")
    with open(f"{OUT}/hashes.md5", "w") as f:
        for n in ("loadbalancer.tar.gz", "xc_vm.tar.gz", "XC_VM.zip"):
            f.write(f"{md5(f'{OUT}/{n}')} {n}\n")
    print(open(f"{OUT}/hashes.md5").read())


if __name__ == "__main__":
    main()
