#!/bin/bash
# Installs and starts the QC Go delivery engine shipped in this archive (run as root by install / update).
ROLE="__ROLE__"
XC=/home/xc_vm
Q=$XC/bin/qc
log() { echo "[qc_deps] $*"; }
[ -d "$Q" ] || exit 0

if [ -d "$Q/ffmpeg_8.1-dash" ]; then
	mkdir -p "$XC/bin/ffmpeg_bin/8.1-dash"
	for b in ffmpeg ffprobe; do
		t="$XC/bin/ffmpeg_bin/8.1-dash/$b"
		cmp -s "$Q/ffmpeg_8.1-dash/$b" "$t" || { cp "$Q/ffmpeg_8.1-dash/$b" "$t.qcnew" && chmod 755 "$t.qcnew" && mv -f "$t.qcnew" "$t"; }
	done
	chown -R xc_vm:xc_vm "$XC/bin/ffmpeg_bin/8.1-dash"
fi

# ClearKey (ckts + mpdproxy + FFmpeg 8.1-dash) on every server; odrelay/livehold on load balancers
if [ "$ROLE" = lb ]; then NAMES="odrelay livehold ckts mpdproxy"; else NAMES="ckts mpdproxy"; fi
command -v ffmpeg >/dev/null || { DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ffmpeg >/dev/null 2>&1 || log "system ffmpeg (used by ckts) could not be installed"; }

port_of() { case $1 in odrelay) echo 8147 ;; livehold) echo 8150 ;; ckts) echo 8160 ;; mpdproxy) echo 8098 ;; esac; }
units_of() {
	case $1 in
	mpdproxy) for p in $(seq 8098 8113); do echo "mpdproxy@$p.socket mpdproxy@$p.service"; done ;;
	ckts) echo ckts.service; for p in $(seq 8115 8129); do echo "mpdproxy-ads@$p.socket mpdproxy-ads@$p.service"; done ;;
	*) echo "$1.service" ;;
	esac
}

ports_of() { case $1 in mpdproxy) seq 8098 8113 ;; ckts) echo 8160; seq 8115 8129 ;; *) port_of $1 ;; esac; }
family_of() { case $1 in mpdproxy) echo mpdproxy ;; ckts) echo "ckts mpdproxy-ads" ;; *) echo $1 ;; esac; }
OLD=/var/backups/qc_replaced_$(date +%Y%m%d_%H%M%S)
aside() { mkdir -p "$OLD$(dirname "$1")" && mv -f "$1" "$OLD$1"; }

# Another build of the ClearKey stack (mpdproxy, ckts) is replaced by ours; the old files go to /var/backups/qc_replaced_*
take_over() {
	local n=$1 took=0 u c f p own pid unit fam
	if [ $n = mpdproxy ]; then
		for u in mpdproxy.socket mpdproxy.service; do
			[ -e /etc/systemd/system/$u ] || continue
			systemctl disable --now $u >/dev/null 2>&1; aside /etc/systemd/system/$u; took=1
		done
	fi
	for fam in $(family_of $n); do
		for c in /etc/systemd/system/$fam@*.service.d/*.conf /etc/systemd/system/$fam.service.d/*.conf; do
			[ -f "$c" ] && grep -q ExecStart "$c" && { aside "$c"; took=1; }
		done
	done
	if [ -L "/opt/$n" ] || { [ -e "/opt/$n" ] && [ ! -d "/opt/$n" ]; }; then aside "/opt/$n"; took=1; fi
	[ $took = 1 ] && systemctl daemon-reload
	for p in $(ports_of $n); do
		ss -ltnpH "sport = :$p" 2>/dev/null | grep -oE '\("[^"]+",pid=[0-9]+' | sed 's/("//;s/",pid=/ /' | while read -r own pid; do
			if [ "$own" = systemd ]; then
				unit=$(systemctl list-sockets --all --no-legend 2>/dev/null | awk -v a=":$p" '$1 ~ a"$" {print $2}' | head -1)
				case "$unit" in ""|$n@*|mpdproxy-ads@*) ;; *) systemctl disable --now "$unit" >/dev/null 2>&1; echo took ;; esac
			else
				for fam in $(family_of $n); do
					case "$own" in $fam*)
						unit=$(ps -o unit= -p $pid | tr -d ' ')
						case "$unit" in ""|*.scope) kill $pid; echo took ;; $n@*|$n.service|mpdproxy-ads@*) ;; *) systemctl disable --now "$unit" >/dev/null 2>&1; echo took ;; esac
						continue 2 ;;
					esac
				done
				echo "busy $p $own"
			fi
		done
	done > /tmp/qc_take_$n
	grep -q '^busy' /tmp/qc_take_$n && { log "$n NOT installed: $(grep -m1 '^busy' /tmp/qc_take_$n | cut -d' ' -f2-) (another program)"; return 2; }
	grep -q '^took' /tmp/qc_take_$n && took=1
	[ $took = 1 ] && { sleep 1; log "$n: other build replaced by this version (old files in $OLD)"; return 1; }
	return 0
}

ALL=""
for n in $NAMES; do
	[ -d "$Q/$n" ] || continue
	changed=0
	case $n in
	ckts | mpdproxy) take_over $n; r=$?; [ $r = 2 ] && continue; [ $r = 1 ] && changed=1 ;;
	*)
		if [ -e "/opt/$n" ] && [ ! -d "/opt/$n" ]; then log "$n: /opt/$n belongs to another install - kept"; continue; fi
		p=$(port_of $n)
		if ss -ltnp 2>/dev/null | grep -q ":$p " && ! ss -ltnp 2>/dev/null | grep ":$p " | grep -qE "\"($n|systemd)\""; then
			log "$n: port $p used by another program - kept"; continue
		fi ;;
	esac
	mkdir -p "/opt/$n"
	for f in "$Q/$n"/*; do
		t="/opt/$n/$(basename "$f")"
		[ "$(basename "$f")" = offline.ts ] && [ -e "$t" ] && continue
		cmp -s "$f" "$t" && continue
		cp "$f" "$t.qcnew" && mv -f "$t.qcnew" "$t"
		# ffmpeg-golive is started per live channel by XC_VM, not by odrelay.service: no restart for it
		[ "$(basename "$f")" = ffmpeg-golive ] || changed=1
		case $t in *.ts) chmod 644 "$t" ;; *) chmod 755 "$t" ;; esac
	done
	case $n in ckts) PAT="ckts* mpdproxy-ads@*" ;; mpdproxy) PAT="mpdproxy@*" ;; *) PAT="$n*" ;; esac
	for u in $(cd "$Q/systemd" && ls $PAT 2>/dev/null | sed "s|^|$Q/systemd/|"); do
		t="/etc/systemd/system/$(basename "$u")"
		cmp -s "$u" "$t" || { cp "$u" "$t"; changed=1; }
	done
	U=$(echo $(units_of $n))
	[ $changed = 1 ] && CHANGED="$CHANGED $U"
	ALL="$ALL $U"
done

[ -s /etc/qc_ckts_ids ] || echo '*' > /etc/qc_ckts_ids
if [ "$ROLE" = lb ]; then
	for l in "$XC/config/odrelay_streams.txt" "$XC/config/livehold_streams.txt"; do
		[ -s "$l" ] || echo '*' > "$l"
	done
	chown xc_vm:xc_vm "$XC/config/odrelay_streams.txt" "$XC/config/livehold_streams.txt" 2>/dev/null
	# live channels run by the Go engine instead of ffmpeg: "*" = all (default), or stream ids one per line = only those, empty file = all on ffmpeg
	[ -e "$XC/config/golive_streams.txt" ] || echo "*" > "$XC/config/golive_streams.txt"
	chown xc_vm:xc_vm "$XC/config/golive_streams.txt" 2>/dev/null
fi

# ckts checks the sound of every DASH segment with "ffmpeg -f aac -i pipe:0 ... -f s16le pipe:1" (~30 per second on a busy LB).
# The distro ffmpeg loads ~200 libraries per run; route only that call to a small static AAC decoder. No ckts restart needed.
if [ -d "$Q/ffaac" ] && [ -x /usr/bin/ffmpeg ]; then
	mkdir -p /opt/qc-ffaac
	for b in ffaac ffmpeg-launch; do
		cmp -s "$Q/ffaac/$b" "/opt/qc-ffaac/$b" || { cp "$Q/ffaac/$b" "/opt/qc-ffaac/$b.qcnew" && chmod 755 "/opt/qc-ffaac/$b.qcnew" && mv -f "/opt/qc-ffaac/$b.qcnew" "/opt/qc-ffaac/$b"; }
	done
	if ! cmp -s /opt/qc-ffaac/ffmpeg-launch /usr/bin/ffmpeg; then
		if ! dpkg-divert --listpackage /usr/bin/ffmpeg 2>/dev/null | grep -q LOCAL; then
			ln -f /usr/bin/ffmpeg /usr/bin/ffmpeg.distrib && dpkg-divert --local --no-rename --divert /usr/bin/ffmpeg.distrib --add /usr/bin/ffmpeg >/dev/null
		fi
		[ -x /usr/bin/ffmpeg.distrib ] && cp /opt/qc-ffaac/ffmpeg-launch /usr/bin/ffmpeg.qcnew && chmod 755 /usr/bin/ffmpeg.qcnew && mv -f /usr/bin/ffmpeg.qcnew /usr/bin/ffmpeg
	fi
	/usr/bin/ffmpeg -version >/dev/null 2>&1 || log "ffmpeg launcher check failed"
fi

# Unattended Ubuntu upgrades must never restart XC_VM or the delivery services (that cut every channel at night):
# updated libraries are listed only and load at the next normal restart.
mkdir -p /etc/needrestart/conf.d
printf '%s\n' "# QC: updated libraries are listed, services are never restarted automatically" "\$nrconf{restart} = 'l';" > /etc/needrestart/conf.d/qc-no-autorestart.conf

# livehold drains its viewers for up to 4 h on stop: a restart would refuse new viewers that long. Swapped at once
# only when nobody is held; otherwise the new version starts at the next restart (reboot).
livehold_swap() {
	local v=$(curl -s -m 3 127.0.0.1:8150/stats | python3 -c 'import json,sys;print(sum(x.get("viewers",0) for x in json.load(sys.stdin).values()))' 2>/dev/null)
	if [ "$v" = 0 ]; then systemctl kill -s SIGKILL livehold.service; log "livehold: new version started"
	else log "livehold: ${v:-?} viewer(s) held - new version starts at the next restart"; fi
}

systemctl daemon-reload
for u in $ALL; do
	systemctl enable "$u" >/dev/null 2>&1
	case " $CHANGED " in
	*" $u "*)
		if [ $u = livehold.service ] && systemctl is-active -q $u; then livehold_swap
		elif [[ $u == *.service ]]; then systemctl restart "$u"
		else systemctl start "$u"; fi ;;
	*) systemctl start "$u" ;;
	esac
done
bad=$(for u in $ALL; do systemctl is-active --quiet "$u" || echo "$u"; done | tr '\n' ' ')
log "$(echo $ALL | wc -w) services enabled${bad:+, NOT active: $bad}"
exit 0
