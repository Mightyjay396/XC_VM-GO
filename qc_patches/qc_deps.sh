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
		cmp -s "$Q/ffmpeg_8.1-dash/$b" "$XC/bin/ffmpeg_bin/8.1-dash/$b" || install -m 755 "$Q/ffmpeg_8.1-dash/$b" "$XC/bin/ffmpeg_bin/8.1-dash/$b"
	done
	chown -R xc_vm:xc_vm "$XC/bin/ffmpeg_bin/8.1-dash"
fi

if [ "$ROLE" = lb ]; then
	NAMES="odrelay livehold ckts mpdproxy"
	command -v ffmpeg >/dev/null || { DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ffmpeg >/dev/null 2>&1 || log "system ffmpeg (used by ckts) could not be installed"; }
else
	NAMES="mpdproxy"
fi

port_of() { case $1 in odrelay) echo 8147 ;; livehold) echo 8150 ;; ckts) echo 8160 ;; mpdproxy) echo 8098 ;; esac; }
units_of() {
	case $1 in
	mpdproxy) for p in $(seq 8098 8113); do echo "mpdproxy@$p.socket mpdproxy@$p.service"; done ;;
	ckts) echo ckts.service; for p in $(seq 8115 8129); do echo "mpdproxy-ads@$p.socket mpdproxy-ads@$p.service"; done ;;
	*) echo "$1.service" ;;
	esac
}

ALL=""
for n in $NAMES; do
	[ -d "$Q/$n" ] || continue
	if [ -e "/opt/$n" ] && [ ! -d "/opt/$n" ]; then log "$n: /opt/$n belongs to another install - kept"; continue; fi
	p=$(port_of $n)
	if ss -ltnp 2>/dev/null | grep -q ":$p " && ! ss -ltnp 2>/dev/null | grep ":$p " | grep -qE "\"($n|systemd)\""; then
		log "$n: port $p used by another program - kept"; continue
	fi
	changed=0
	mkdir -p "/opt/$n"
	for f in "$Q/$n"/*; do
		t="/opt/$n/$(basename "$f")"
		[ "$(basename "$f")" = offline.ts ] && [ -e "$t" ] && continue
		cmp -s "$f" "$t" && continue
		cp "$f" "$t.qcnew" && mv -f "$t.qcnew" "$t"; changed=1
		case $t in *.ts) chmod 644 "$t" ;; *) chmod 755 "$t" ;; esac
	done
	case $n in ckts) PAT="ckts* mpdproxy-ads@*" ;; mpdproxy) PAT="mpdproxy@*" ;; *) PAT="$n*" ;; esac
	for u in $(cd "$Q/systemd" && ls $PAT 2>/dev/null | sed "s|^|$Q/systemd/|"); do
		t="/etc/systemd/system/$(basename "$u")"
		cmp -s "$u" "$t" || { cp "$u" "$t"; changed=1; }
	done
	U=$(units_of $n | tr ' ' '\n')
	[ $changed = 1 ] && CHANGED="$CHANGED $U"
	ALL="$ALL $U"
done

if [ "$ROLE" = lb ]; then
	for l in "$XC/config/odrelay_streams.txt" "$XC/config/livehold_streams.txt" /etc/qc_ckts_ids; do
		[ -s "$l" ] || echo '*' > "$l"
	done
	chown xc_vm:xc_vm "$XC/config/odrelay_streams.txt" "$XC/config/livehold_streams.txt" 2>/dev/null
	# live channels run by the Go engine instead of ffmpeg: opt-in list of stream ids (one per line), empty = all on ffmpeg
	[ -e "$XC/config/golive_streams.txt" ] || : > "$XC/config/golive_streams.txt"
	chown xc_vm:xc_vm "$XC/config/golive_streams.txt" 2>/dev/null
fi

systemctl daemon-reload
for u in $ALL; do
	systemctl enable "$u" >/dev/null 2>&1
	case " $CHANGED " in *" $u "*) [[ $u == *.service ]] && systemctl restart "$u" || systemctl start "$u" ;; *) systemctl start "$u" ;; esac
done
bad=$(for u in $ALL; do systemctl is-active --quiet "$u" || echo "$u"; done | tr '\n' ' ')
log "$(echo $ALL | wc -w) services enabled${bad:+, NOT active: $bad}"
exit 0
