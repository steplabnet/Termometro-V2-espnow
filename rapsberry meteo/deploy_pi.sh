#!/usr/bin/env bash
#
# Deploy the station scripts to the Raspberry Pi (stazionemeteo).
#
#   ./deploy_pi.sh                # everything
#   ./deploy_pi.sh meteo.py       # only the files named
#
# Credentials are hardcoded on purpose: this is a LAN test platform, the Pi is
# not reachable from outside the house.
#
# The two Python scripts do NOT live in the same directory — meteo.py runs from
# the Desktop copy, everything else from the web root. Copying meteo.py to
# /var/www/html looks right and silently leaves the old one running, so the
# destinations below are explicit and the script greps the deployed file
# afterwards to prove the new version is the one in place.
#
# Safe to re-run: each target is backed up to <file>.bak-<stamp> before it is
# overwritten, and the services are restarted.

set -euo pipefail

PI_HOST="stazionemeteo.local"
PI_USER="admin"
PI_PASS="78f25d_78"
PUTTY_DIR="${PUTTY_DIR:-/c/Program Files/PuTTY}"

PLINK="$PUTTY_DIR/plink"

HERE="$(cd "$(dirname "$0")" && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"

# file | destination directory | systemd unit to restart (empty = none)
TARGETS=(
  "mqtt_receiver.py|/var/www/html|meteo-receiver.service"
  "meteo.py|/home/admin/Desktop/meteo|meteo.service"
  "alarm_watcher.py|/var/www/html|meteo-alarms.service"
  "crono_watcher.py|/var/www/html|meteo-crono.service"
  "alarms.php|/var/www/html|"
  "bots.php|/var/www/html|"
  "cronotermostato.php|/var/www/html|"
  "index.php|/var/www/html|"
  "batteria.php|/var/www/html|"
  "carichi.php|/var/www/html|"
  "battery_bridge.py|/var/www/html|meteo-battery.service"
  "check_energy.py|/var/www/html|"
)

sh_remote() { "$PLINK" -ssh -batch -pw "$PI_PASS" "$PI_USER@$PI_HOST" "$@"; }

# Files go over the SSH exec channel, base64-encoded, not through pscp: the Pi
# refuses pscp's connection, and base64 keeps Windows line endings and any
# binary content from being mangled on the way. Written to a temp file and
# moved into place so a half-transferred script is never left runnable.
put() {
  base64 -w0 < "$1" | sh_remote "base64 -d > '$2.new' && chmod --reference='$2' '$2.new' 2>/dev/null; mv '$2.new' '$2'"
}

# With no arguments deploy every file; otherwise only the ones named.
wanted() {
  [ $# -eq 0 ] && return 0
  local f="$1"; shift
  for a in "$@"; do [ "$a" = "$f" ] && return 0; done
  return 1
}

units=()
deployed=()

for entry in "${TARGETS[@]}"; do
  IFS='|' read -r file dest unit <<< "$entry"
  wanted "$file" "$@" || continue
  [ -f "$HERE/$file" ] || { echo "missing locally: $file" >&2; exit 1; }

  echo "--> $file  ->  $dest/"
  # Keep one backup per run, but only of a file that is actually there.
  sh_remote "[ -f '$dest/$file' ] && cp -p '$dest/$file' '$dest/$file.bak-$STAMP' || true"
  put "$HERE/$file" "$dest/$file"

  deployed+=("$dest/$file")
  [ -n "$unit" ] && units+=("$unit")
done

if [ ${#deployed[@]} -eq 0 ]; then
  echo "nothing to deploy (unknown file name?)" >&2
  exit 1
fi

# Restart each unit once, even if several of its files changed. A unit that is
# not installed on the Pi yet (the battery bridge, until its service file is
# put in place) is skipped instead of aborting the whole deploy.
if [ ${#units[@]} -gt 0 ]; then
  uniq_units=$(printf '%s\n' "${units[@]}" | sort -u | tr '\n' ' ')
  for u in $uniq_units; do
    if sh_remote "systemctl list-unit-files '$u' --no-legend" | grep -q .; then
      present="${present:-} $u"
    else
      echo "--> skipping restart: $u is not installed on the Pi"
    fi
  done
  if [ -n "${present:-}" ]; then
    echo
    echo "--> restarting:$present"
    sh_remote "sudo systemctl restart$present"
  fi
fi

echo
echo "--> verify"
# md5 of the deployed copy against the local one: the only check that says the
# right bytes are in the right place, whatever the file is.
for path in "${deployed[@]}"; do
  file="$(basename "$path")"
  local_md5=$(md5sum "$HERE/$file" | cut -d' ' -f1)
  remote_md5=$(sh_remote "md5sum '$path' | cut -d' ' -f1" | tr -d '\r')
  if [ "$local_md5" = "$remote_md5" ]; then
    echo "  OK   $path"
  else
    echo "  FAIL $path (local $local_md5 != remote $remote_md5)"
  fi
done

if [ -n "${present:-}" ]; then
  echo
  sh_remote "systemctl --no-pager --property=Id,ActiveState,SubState show$present | grep -v '^$'" || true
  sh_remote "pgrep -af 'meteo.py|mqtt_receiver.py|alarm_watcher.py|crono_watcher.py|battery_bridge.py'"
fi

echo
echo "--> chain check (check_energy.py)"
sh_remote "python3 /var/www/html/check_energy.py" || true
