#!/usr/bin/env bash
# PROPOSED replacement for /root/scripts/scraper-token-rotate.sh — NOT INSTALLED.
# Install only AFTER the staging code (crm:nerby-service-token-check, --revoke-operator-except,
# EnsureNerbyServiceCaller) is live on the backend that /var/lib/vss-scraper/current points at.
# Installing it earlier makes the daily check fail against the old backend (commands don't exist).
#
# Rotate the Zerem scraper's NERBY service token on live, or check it.
#
#   scraper-token-rotate.sh             rotate (new 720-hour = 30-day token)
#   scraper-token-rotate.sh --hours 336 rotate with another lifetime (1..720)
#   scraper-token-rotate.sh --check     read-only: AUTHORIZATION check + live probe
#                                       (exit 1 when < WARN_DAYS left, 2 when not authorized / probe fails / check broken)
#
# WHAT CHANGED vs the user-21 version:
#  - identity: the org-bound, route-confined NERBY service account (crm:nerby-service-token), not
#    scraper-service@crm.local with a wildcard token;
#  - the check validates AUTHORIZATION (crm:nerby-service-token-check: identity, least-privilege role,
#    one-org scope, CRM entitlement, expiry) AND probes a service-only, read-only callback
#    (GET /website-enrichment/queue?limit=1). `GET /auth/me` proved only that a login was valid: it
#    passed the old token that the callbacks refuse, and it refuses a correct NERBY token by design;
#  - old tokens are revoked with --revoke-operator-except=<new id>, which never touches the short-lived
#    tokens of discovery runs / enrichment ticks in flight (never --revoke-all here).
# The token is never printed, never on a command line: it moves by stdin and 0600 files only.
set -uo pipefail
ORG=1; HOURS=720; CHECK=0; WARN_DAYS=7
ENVF=/var/lib/vss-scraper/.env; LOCK=/var/lib/vss-scraper/.discovery.lock
LOG=/var/lib/vss-scraper/logs/token-rotation.log; BASE=https://system.viteseo.ph
PROBE="$BASE/api/v1/crm/website-enrichment/queue?limit=1"
while [ $# -gt 0 ]; do case "$1" in
  --hours) HOURS=${2:?--hours needs a number}; shift 2;;
  --check) CHECK=1; shift;;
  -h|--help) sed -n '6,24p' "$0"; exit 0;;
  *) echo "unknown option: $1" >&2; exit 64;; esac; done
[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
[[ "$HOURS" =~ ^[0-9]+$ ]] && [ "$HOURS" -ge 1 ] && [ "$HOURS" -le 720 ] || { echo "--hours must be 1..720" >&2; exit 64; }

LIVE="$(dirname "$(readlink -f /var/lib/vss-scraper/current)")/backend"
[ -f "$LIVE/artisan" ] || { echo "no live backend at $LIVE" >&2; exit 1; }

# Secrets live in a ROOT-owned 0700 dir. artisan runs as www-data but never touches it: its stdin/stdout
# are redirected by this (root) shell, and its HOME is /tmp as for vss-queue.
umask 077
W=$(mktemp -d /root/.scrtok.XXXXXX); chmod 700 "$W"; HDR="$W/hdr"
cleanup() { for f in "$W"/*; do [ -f "$f" ] && shred -u "$f"; done; rm -rf "$W"; }; trap cleanup EXIT
artisan() { (cd "$LIVE" && sudo -u www-data env HOME=/tmp XDG_CONFIG_HOME=/tmp php8.4 artisan "$@"); }
probe() { printf 'Authorization: Bearer %s\n' "$1" > "$HDR"; chmod 600 "$HDR"; curl -s -o /dev/null -w '%{http_code}' -m 15 -H @"$HDR" -H 'Accept: application/json' "$PROBE"; }
# crm:nerby-service-token-check exit codes: 0 authorized, 10 expiring, 11 NOT authorized; anything else = the check
# itself failed (e.g. 1 = exception / command missing on an old backend) and is treated as a failure, never as "expiring".
authz() { printf '%s\n' "$1" | artisan crm:nerby-service-token-check --warn-days="$2"; }   # prints id/org/expiry/verdict only
current_token() { grep '^CRM_TOKEN=' "$ENVF" | cut -d= -f2-; }
note() { echo "$1"; mkdir -p "$(dirname "$LOG")"; echo "$(date -u '+%F %T UTC') $1" >> "$LOG"; }

# ── --check ──────────────────────────────────────────────────────────────────
if [ "$CHECK" = 1 ]; then
  CUR=$(current_token)
  VERDICT=$(authz "$CUR" "$WARN_DAYS" 2>&1); RC=$?
  CODE=$(probe "$CUR")
  echo "$VERDICT; live probe → $CODE"
  case "$RC" in 0|10) ;; 11) echo "  ✗ the token in $ENVF is NOT authorized for the scraper callbacks"; exit 2;;
    *) echo "  ✗ the authorization check itself failed (rc=$RC)"; exit 2;; esac
  [ "$CODE" = 200 ] || { echo "  ✗ the live callback probe answered $CODE"; exit 2; }
  [ "$RC" = 10 ] && { echo "  ⚠ rotate soon: $0"; exit 1; }
  echo "  ✓ ok"; exit 0
fi

# ── rotate ───────────────────────────────────────────────────────────────────
exec 9>"$LOCK"; flock -n 9 || { echo "the scraper is running right now (lock held) — try again later" >&2; exit 1; }
BK=/root/backups/vss-scraper-env-$(date +%Y%m%d-%H%M%S); cp -p "$ENVF" "$BK"; chmod 600 "$BK"; echo "backup: $BK"

artisan crm:nerby-service-token "$ORG" --hours="$HOURS" > "$W/out" 2>&1 || { grep -v '^[0-9]\+|' "$W/out"; echo "issue failed — nothing changed"; exit 1; }
NEW=$(grep -E '^[0-9]+\|' "$W/out" | tail -1); NEWID=${NEW%%|*}
[[ "$NEWID" =~ ^[0-9]+$ ]] || { echo "could not read the new token — nothing changed"; exit 1; }
DB=teamora   # the live backend's DB_DATABASE (verify at install time)
undo_token() { mysql -e "DELETE FROM $DB.personal_access_tokens WHERE id=$NEWID AND name='nerby:operator'"; }
echo "new token #$NEWID"

V1=$(authz "$NEW" 0 2>&1); R1=$?; C1=$(probe "$NEW"); echo "  new token → $V1; probe $C1"
{ [ "$R1" = 0 ] && [ "$C1" = 200 ]; } || { undo_token; note "rotation FAILED: new token #$NEWID rc=$R1 probe=$C1; removed it, .env untouched"; exit 1; }

printf '%s' "$NEW" > "$W/tok"; chmod 600 "$W/tok"
python3 - "$ENVF" "$W/tok" <<'PY' || { undo_token; echo "could not write .env — new token removed, .env untouched"; exit 1; }
import os, sys, tempfile
envf, tokf = sys.argv[1], sys.argv[2]
tok = open(tokf).read().strip(); lines = open(envf).read().split("\n")
hits = [i for i, l in enumerate(lines) if l.startswith("CRM_TOKEN=")]
assert len(hits) == 1, f"{len(hits)} CRM_TOKEN lines"
lines[hits[0]] = "CRM_TOKEN=" + tok
st = os.stat(envf); fd, tmp = tempfile.mkstemp(dir=os.path.dirname(envf), prefix=".env.")
with os.fdopen(fd, "w") as f: f.write("\n".join(lines))
os.chmod(tmp, st.st_mode & 0o777); os.chown(tmp, st.st_uid, st.st_gid); os.replace(tmp, envf)
PY
C2=$(probe "$(current_token)"); echo "  token read back from .env → probe $C2"
if [ "$C2" != 200 ]; then cp -p "$BK" "$ENVF"; undo_token; note "rotation FAILED: .env copy probe $C2; restored .env, removed #$NEWID"; exit 1; fi

artisan crm:nerby-service-token --revoke-operator-except="$NEWID" > "$W/rev" 2>&1; RV=$?; sed 's/^/  /' "$W/rev"
[ "$RV" = 0 ] || note "WARNING: revoking the old operator tokens failed (rc=$RV) — new token #$NEWID is installed; revoke the old ones by hand"
C3=$(probe "$(current_token)"); echo "  after revoking → probe $C3"
note "rotated: NERBY operator token #$NEWID for org $ORG (${HOURS}h); backup $BK"
[ "$C3" = 200 ] || { echo "  ✗ unexpected: the new token stopped working — check $ENVF (backup $BK)"; exit 1; }
echo "✓ done — the daily check warns $WARN_DAYS days before expiry"
