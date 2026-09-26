#!/usr/bin/env bash
#
# The LB settings allowlist (cluster plan, section 9, R1 `settings`): the
# settings the load-balancer build reads, never a secret. Builds the LB file
# manifest as the Makefile does (tools/ci/verify-lb-archive.sh) and hands it
# to tools/ci/lb_settings_keys.php.
#
#   bash tools/ci/lb-settings-keys.sh           check (make gates)
#   bash tools/ci/lb-settings-keys.sh --write   regenerate src/Core/Cluster/lb_settings_keys.php
set -euo pipefail
cd "$(dirname "$0")/../.."

LB_DIRS=$(make -s print-LB_DIRS)
RM_DIRS=$(make -s print-LB_DIRS_TO_REMOVE)
RM_FILES=$(make -s print-LB_FILES_TO_REMOVE)

manifest=$(for d in $LB_DIRS; do git ls-files "src/$d" 2>/dev/null; done | sed 's#^src/##')
if [ -n "${RM_DIRS// }" ]; then
	rm_re=$(printf '%s' "$RM_DIRS" | tr -s ' ' '|')
	manifest=$(printf '%s\n' "$manifest" | grep -Ev "^(${rm_re})/" || true)
fi
for f in $RM_FILES; do
	manifest=$(printf '%s\n' "$manifest" | grep -vxF "$f" || true)
done

printf '%s\n' "$manifest" | php tools/ci/lb_settings_keys.php "$@"
