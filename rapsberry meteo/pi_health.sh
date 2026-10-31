#!/bin/bash
#
# Self-healing check for stazionemeteo, run every minute by meteo-health.timer.
#
# The hardware watchdog (systemd RuntimeWatchdogSec=15) already reboots a
# frozen kernel. What it cannot see is a Pi that is alive but useless: Wi-Fi
# gone, or a service dead and not coming back. This script covers that, one
# step at a time:
#
#   network: ping the default gateway (the router, not the internet, so an
#   internet outage never reboots the Pi)
#     2 failed minutes   -> Wi-Fi radio off/on
#     5 failed minutes   -> reload the Wi-Fi driver, restart NetworkManager
#     10 failed minutes  -> reboot
#   services: systemctl is-active
#     down 2 checks      -> restart it (again at 4)
#     down 6 checks      -> reboot
#   root filesystem read-only (SD card error) -> reboot
#
# Reboots are rate limited (MIN_REBOOT_GAP, MAX_REBOOTS_DAY), so a router that
# is switched off or a script that crash-loops cannot trap the Pi in a reboot
# loop. Every action goes to the journal (journalctl -t meteo-health) and to
# /var/lib/meteo-health/actions.log, which survives the reboot.
#
# To stop a service on purpose without it being restarted under you:
#   sudo touch /run/meteo-health.pause      (gone at the next boot)

set -u

SERVICES="meteo meteo-receiver meteo-alarms meteo-crono meteo-crono-remote meteo-battery mosquitto apache2 brickd tailscaled"
WIFI_IF="wlan0"

BOOT_GRACE=300          # seconds after boot before any check
MIN_REBOOT_GAP=3600     # seconds between two reboots done by this script
MAX_REBOOTS_DAY=4

STATE=/var/lib/meteo-health     # persistent: reboot history, action log
RUN=/run/meteo-health           # tmpfs: failure counters, reset at boot
PAUSE=/run/meteo-health.pause

mkdir -p "$STATE" "$RUN" 2>/dev/null

log() {
  logger -t meteo-health -- "$*"
  echo "$(date '+%F %T') $*" >> "$STATE/actions.log" 2>/dev/null || true
}

# Counters live in one file each under /run: read, bump, reset.
get()   { cat "$RUN/$1" 2>/dev/null || echo 0; }
bump()  { local n=$(( $(get "$1") + 1 )); echo "$n" > "$RUN/$1"; echo "$n"; }
reset() { rm -f "$RUN/$1"; }

reboot_now() {
  local reason="$1" now last count
  now=$(date +%s)
  last=$(tail -n1 "$STATE/reboots" 2>/dev/null | cut -d' ' -f1)
  count=$(awk -v t=$((now - 86400)) '$1 > t' "$STATE/reboots" 2>/dev/null | wc -l)
  if [ -n "$last" ] && [ $((now - last)) -lt $MIN_REBOOT_GAP ]; then
    logger -t meteo-health -- "reboot needed ($reason) but the last one was $(( (now - last) / 60 )) min ago: waiting"
    return
  fi
  if [ "$count" -ge $MAX_REBOOTS_DAY ]; then
    logger -t meteo-health -- "reboot needed ($reason) but already $count in 24h: giving up until tomorrow"
    return
  fi

  log "REBOOT: $reason"
  echo "$now $reason" >> "$STATE/reboots" 2>/dev/null
  sync
  timeout 30 systemctl reboot && exit 0
  # systemd is not answering: ask the kernel directly (sync, then reboot).
  echo s > /proc/sysrq-trigger; sleep 3
  echo b > /proc/sysrq-trigger
}

[ "$(cut -d. -f1 /proc/uptime)" -lt $BOOT_GRACE ] && exit 0

# Keep the persistent log short.
if [ -f "$STATE/actions.log" ] && [ "$(wc -l < "$STATE/actions.log")" -gt 2000 ]; then
  tail -n 1000 "$STATE/actions.log" > "$STATE/actions.log.tmp" && mv "$STATE/actions.log.tmp" "$STATE/actions.log"
fi

# --- root filesystem ------------------------------------------------------
# An SD card error makes the kernel remount / read-only; nothing writes after
# that and only a reboot (with fsck) brings it back.
if ! touch "$STATE/.rw-test" 2>/dev/null; then
  reboot_now "root filesystem is read-only"
fi

# --- network --------------------------------------------------------------
gw=$(ip -4 route show default | awk '{print $3; exit}')
if [ -n "$gw" ] && ping -c 3 -W 2 -q "$gw" > /dev/null 2>&1; then
  [ "$(get net)" -gt 0 ] && log "network back (gateway $gw) after $(get net) min"
  reset net
else
  n=$(bump net)
  case $n in
    2)
      log "gateway ${gw:-<no default route>} unreachable for $n min: Wi-Fi radio off/on"
      nmcli radio wifi off; sleep 3; nmcli radio wifi on
      ;;
    5)
      log "gateway ${gw:-<no default route>} unreachable for $n min: reloading Wi-Fi driver"
      modprobe -r brcmfmac_wcc brcmfmac; sleep 2; modprobe brcmfmac
      systemctl restart NetworkManager
      ;;
  esac
  [ "$n" -ge 10 ] && reboot_now "network down for $n min"
fi

# --- services -------------------------------------------------------------
[ -e "$PAUSE" ] && exit 0

for u in $SERVICES; do
  # Skip anything not installed on this Pi instead of rebooting over it.
  systemctl cat "$u.service" > /dev/null 2>&1 || continue
  state=$(systemctl is-active "$u")
  if [ "$state" = active ]; then
    [ "$(get "svc-$u")" -gt 1 ] && log "$u is running again"
    reset "svc-$u"
    continue
  fi
  # One bad check is tolerated: a unit restarting itself (Restart=always) or
  # restarted by deploy_pi.sh is briefly "activating".
  n=$(bump "svc-$u")
  case $n in
    2|4)
      log "$u is $state ($n checks): restarting"
      systemctl reset-failed "$u" 2>/dev/null
      timeout 90 systemctl restart "$u" || log "$u: restart failed"
      ;;
  esac
  [ "$n" -ge 6 ] && reboot_now "$u still $state after 2 restarts"
done

exit 0
