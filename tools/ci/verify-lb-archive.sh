#!/usr/bin/env bash
#
# Security gate (plan blocker 1): the LoadBalancer archive must NOT contain
# privileged code. The LB build copies LB_DIRS and then removes LB_DIRS_TO_REMOVE
# / LB_FILES_TO_REMOVE. After the PascalCase rename (Фаза 1) a stale lowercase
# remove path would silently miss, leaking Admin/Reseller controllers, the
# user/device domain and cron jobs to an internet-facing DMZ node.
#
# This reproduces the Makefile's LB file selection from the real LB_* variables
# (no tarball needed) and asserts that:
#   - every LB_* entry still matches a tracked path under src/ (STALE),
#   - the sensitive trees are absent (LEAK),
#   - every script lb_configs/nginx.conf executes still ships (MISSING).
set -euo pipefail
cd "$(dirname "$0")/../.."

LB_DIRS=$(make -s print-LB_DIRS)
RM_DIRS=$(make -s print-LB_DIRS_TO_REMOVE)
RM_FILES=$(make -s print-LB_FILES_TO_REMOVE)
NGINX_CONF="$(make -s print-CONFIG_DIR)/nginx.conf"
NGINX_CONF="${NGINX_CONF#./}"

fail=0

# An entry that matches no tracked path copies or strips nothing, so a rename
# silently turns its strip rule into a no-op (how the old www/* entries went
# stale while Public/stream/auth.php and Public/admin/api.php kept shipping).
for p in $LB_DIRS $RM_DIRS $RM_FILES; do
	if ! git ls-files --error-unmatch -- "src/$p" >/dev/null 2>&1; then
		echo "STALE: '${p}' matches no tracked path under src/ (renamed or removed?)."
		fail=1
	fi
done

# Shipped manifest: tracked files under LB_DIRS, paths relative to src/.
manifest=$(for d in $LB_DIRS; do git ls-files "src/$d" 2>/dev/null; done | sed 's#^src/##')

# Apply directory removals.
if [ -n "${RM_DIRS// }" ]; then
	rm_re=$(printf '%s' "$RM_DIRS" | tr -s ' ' '|')
	manifest=$(printf '%s\n' "$manifest" | grep -Ev "^(${rm_re})/" || true)
fi
# Apply file removals.
for f in $RM_FILES; do
	manifest=$(printf '%s\n' "$manifest" | grep -vxF "$f" || true)
done

# Privileged paths that must never reach an LB (internet-facing) node. These are
# the genuinely admin/reseller/install-only trees and files. NOTE: LB legitimately
# ships most of Cli/Commands and Cli/CronJobs (it runs edge commands + crons like
# certbot/cache/cleanup), so only the specific privileged ones are listed — not
# the whole dirs. Each entry must be removed by the Makefile's LB_DIRS_TO_REMOVE /
# LB_FILES_TO_REMOVE; this asserts the removal actually took effect (catches a
# silent rm miss after a rename — security blocker 1).
SENSITIVE=(
	# Admin / reseller / player UI + the privileged domains (dir-level removes).
	"Public/Controllers/Admin"
	"Public/Controllers/Reseller"
	"Public/Controllers/Player"
	"Public/Controllers/PlayerV2"
	"Domain/User"
	"Domain/Device"
	# The cluster API server (MAIN side of MAIN <-> LB); LBs only ever call it.
	"Domain/Cluster"
	"Public/cluster"
	"Cli/Commands/ClusterInitCommand.php"
	# Admin / reseller APIs and the MAIN-only endpoints (the LB nginx routes none
	# of them; auth.php and probe.php need the stripped Domain/User).
	"Public/admin/api.php"
	"Public/admin/proxy_api.php"
	"Public/stream/auth.php"
	"Public/stream/probe.php"
	"Public/Controllers/Api/AdminApiController.php"
	"Public/Controllers/Api/AdminAPIWrapper.php"
	"Public/Controllers/Api/ActiveCodeApiController.php"
	"Public/Controllers/Api/ResellerRestApiController.php"
	"Public/Controllers/Api/ResellerAPIWrapper.php"
	"Infrastructure/ResellerApiDispatcher.php"
	"Infrastructure/ResellerTableRenderer.php"
	# Install / provisioning / schema commands and MAIN-only or root-privileged
	# cron jobs (file-level).
	"Cli/Commands/ServerInstallCommand.php"
	"Cli/Commands/ServerSyncOpensslExtraCommand.php"
	"Cli/Commands/LbInstallFlow.php"
	"Cli/Commands/ProxyInstallFlow.php"
	"Cli/Commands/MigrateCommand.php"
	"Cli/Commands/DbMigrateCommand.php"
	"Cli/Commands/CacheHandlerCommand.php"
	"Cli/migration_logic.php"
	"Cli/CronJobs/RootMysqlCronJob.php"
	"Cli/CronJobs/CacheEngineCronJob.php"
)

for s in "${SENSITIVE[@]}"; do
	# Match a directory prefix ("$s/") or an exact file path ("$s"). A here-string,
	# not a pipe: under pipefail an early `grep -q` exit can SIGPIPE the writer.
	if grep -qE "^${s}(/|$)" <<< "$manifest"; then
		echo "LEAK: '${s}' would ship to the LB archive (privileged code)."
		printf '%s\n' "$manifest" | grep -E "^${s}(/|$)" | sed 's/^/    /' | head -5
		fail=1
	fi
done

# Whatever the LB nginx executes must ship, or a strip entry breaks a route the
# LB serves: every SCRIPT_FILENAME, and Public/<scope>/<handler>.php for each
# handler the /stream/ and /admin/ gateway locations accept (the gateways 404 a
# handler whose file is missing).
routed=$(grep -oE 'SCRIPT_FILENAME /home/xc_vm/[^;[:space:]]+' "$NGINX_CONF" | sed 's#^SCRIPT_FILENAME /home/xc_vm/##' | sort -u || true)
if [ -z "$routed" ]; then
	echo "MISSING: no SCRIPT_FILENAME /home/xc_vm/... found in ${NGINX_CONF} (update this gate)."
	fail=1
fi
for scope in stream admin; do
	location_re='^[[:space:]]*location ~ \^/'"${scope}"'/\(([a-z_|]+)\)\$ \{'
	handlers=$(sed -nE "\#${location_re}#{s#${location_re}.*#\1#p;q;}" "$NGINX_CONF")
	if [ -z "$handlers" ]; then
		echo "MISSING: no 'location ~ ^/${scope}/(...)\$' gateway found in ${NGINX_CONF} (update this gate)."
		fail=1
		continue
	fi
	for h in ${handlers//|/ }; do
		routed+=$'\n'"Public/${scope}/${h}.php"
	done
done
while IFS= read -r r; do
	[ -n "$r" ] || continue
	if ! grep -qxF "$r" <<< "$manifest"; then
		echo "MISSING: ${NGINX_CONF} routes to '${r}' but the LB manifest does not ship it."
		fail=1
	fi
done <<< "$routed"

if [ "$fail" -ne 0 ]; then
	echo "FAIL: fix the Makefile LB_DIRS / LB_DIRS_TO_REMOVE / LB_FILES_TO_REMOVE lists or ${NGINX_CONF} (STALE: entry matches nothing; LEAK: privileged code ships; MISSING: a routed script is stripped)."
	exit 1
fi
echo "OK: LB manifest has no stale entries, excludes all privileged trees and ships every routed script ($(printf '%s\n' "$manifest" | grep -c . ) files shipped)."
