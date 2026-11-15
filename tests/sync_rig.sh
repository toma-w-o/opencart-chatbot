#!/usr/bin/env bash
# Keeps a rig built by setup_oc.sh in step with upload/ (the rig holds a copy, not a link):
# library, catalog (incl. view/javascript/aichat/**), admin/**. Safe to run while the servers run.
#
#   tests/sync_rig.sh /path/to/scratch
set -euo pipefail
WORK=${1:?usage: sync_rig.sh <scratch dir>}
REPO=$(cd "$(dirname "$0")/.." && pwd)
OC=$WORK/oc
[ -d "$OC/system" ] || { echo "no rig in $OC"; exit 1; }
if command -v rsync >/dev/null; then
	rsync -a "$REPO/upload/" "$OC/"
else
	cp -r "$REPO/upload/"* "$OC/"
fi
# prepend: test constants (the PHP servers read it on every request)
PRE=$WORK/tools/prepend_test.php
if [ -f "$PRE" ] && ! grep -q AICHAT_TELEGRAM_BASE "$PRE"; then
	PSVC=$(sed -n "s/.*AICHAT_SPEEDY_BASE', 'http:\/\/127.0.0.1:\([0-9]*\).*/\1/p" "$PRE")
	sed -i "/AICHAT_SPEEDY_BASE/a define('AICHAT_TELEGRAM_BASE', 'http://127.0.0.1:${PSVC:-8098}/tg');" "$PRE"
fi
echo "rig synced: $OC"
