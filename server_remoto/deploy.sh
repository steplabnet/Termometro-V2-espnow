#!/usr/bin/env bash
#
# Deploy the dashboard + MQTT ingest bridge to the web host.
#
#   ./deploy.sh user@cesana.steplab.net [docroot]
#
# docroot defaults to the cesana.steplab.net vhost. Needs SSH with sudo for the
# service part; pass --no-service to copy the PHP only.
#
# Files are streamed over the SSH exec channel rather than scp/sftp: the SFTP
# subsystem times out on this host, while plain command execution works.
#
# Safe to re-run: files are overwritten, the service is restarted.

set -euo pipefail

TARGET="${1:-}"
DOCROOT="${2:-/var/www/vhosts/steplab.net/public_html/meteo}"
INSTALL_SERVICE=1

for arg in "$@"; do
  [ "$arg" = "--no-service" ] && INSTALL_SERVICE=0
done

if [ -z "$TARGET" ] || [ "$TARGET" = "--no-service" ]; then
  echo "usage: $0 user@host [docroot] [--no-service]" >&2
  exit 1
fi

HERE="$(cd "$(dirname "$0")" && pwd)"

# The dashboard and the ingest path. ingest_secret.php is included: the bridge
# is useless without it, and it must match the unit file's INGEST_TOKEN.
PHP_FILES=(
  index.php
  live.php
  instant_lib.php
  instant_store.php
  store_lib.php
  ingest.php
  ingest_secret.php
  carica_dati.php
)

# Stream one local file to a remote path, then verify it arrived intact.
push() {
  local src="$1" dest="$2"
  base64 -w0 "$src" | ssh "$TARGET" "base64 -d > '$dest'"
  local want have
  want=$(md5sum < "$src" | cut -d" " -f1)
  have=$(ssh "$TARGET" "md5sum < '$dest'" | cut -d" " -f1)
  [ "$want" = "$have" ] || { echo "checksum mismatch for $dest" >&2; exit 1; }
}

echo "==> copying PHP to $TARGET:$DOCROOT"
for f in "${PHP_FILES[@]}"; do
  [ -f "$HERE/$f" ] || { echo "missing: $f" >&2; exit 1; }
done
for f in "${PHP_FILES[@]}"; do
  printf '    %-20s' "$f"
  push "$HERE/$f" "$DOCROOT/$f"
  echo "ok"
done

# Match the ownership of the files already in the vhost, and keep the shared
# secret away from the group the other site files are readable by.
ssh "$TARGET" "cd '$DOCROOT' && chown steplab:psacln ${PHP_FILES[*]} && chmod 644 ${PHP_FILES[*]} && chmod 640 ingest_secret.php"

echo "==> linting with the vhost's PHP"
ssh "$TARGET" "cd '$DOCROOT' && for f in ${PHP_FILES[*]}; do /opt/plesk/php/8.3/bin/php -l \$f || exit 1; done"

# /dev/shm/thermo_data is created on demand by the PHP, but only if the web
# user may write there; doing it here makes a permissions problem visible now
# rather than as a silently stale dashboard.
echo "==> checking the RAM snapshot directory"
ssh "$TARGET" 'mkdir -p /dev/shm/thermo_data && chmod 775 /dev/shm/thermo_data && ls -ld /dev/shm/thermo_data'

if [ "$INSTALL_SERVICE" -eq 0 ]; then
  echo "==> skipping the service (--no-service)"
  exit 0
fi

echo "==> installing the MQTT bridge"
ssh "$TARGET" "mkdir -p /opt/mqtt-ingest"
push "$HERE/mqtt_ingest.py" "/opt/mqtt-ingest/mqtt_ingest.py"
push "$HERE/mqtt-ingest.service" "/etc/systemd/system/mqtt-ingest.service"

ssh -t "$TARGET" 'bash -se' <<'REMOTE'
set -euo pipefail

# paho-mqtt is the only dependency, but 2.x needs Python >= 3.7 and AlmaLinux 8
# ships 3.6 as python3 -- hence python3.11, which the unit file also names.
if ! python3.11 -c 'import paho.mqtt.client' 2>/dev/null; then
  echo "--> installing python3.11 + paho-mqtt"
  sudo dnf install -y python3.11 python3.11-pip
  sudo python3.11 -m pip install --quiet --upgrade paho-mqtt
fi

sudo chmod 755 /opt/mqtt-ingest/mqtt_ingest.py
# The unit carries the broker password and the ingest token.
sudo chmod 600 /etc/systemd/system/mqtt-ingest.service

sudo systemctl daemon-reload
sudo systemctl enable mqtt-ingest
sudo systemctl restart mqtt-ingest
sleep 3
sudo systemctl --no-pager --lines=20 status mqtt-ingest
REMOTE

echo
echo "==> done. Follow it with:"
echo "    ssh $TARGET 'journalctl -u mqtt-ingest -f'"
