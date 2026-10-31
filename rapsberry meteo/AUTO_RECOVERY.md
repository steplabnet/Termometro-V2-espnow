# Automatic recovery (stazionemeteo.local)

Installed 2026-10-31. The goal is that the Pi recovers by itself instead of needing someone to unplug it.

## Layers

| Problem | What handles it |
|---|---|
| Kernel or systemd frozen | Hardware watchdog: systemd `RuntimeWatchdogSec=15` in `/etc/systemd/system.conf` (it was already set). If a reboot hangs, `RebootWatchdogSec=10min` forces it. |
| Wi-Fi down, Pi alive | `pi_health.sh`: Wi-Fi radio off/on after 2 min, driver reload and NetworkManager restart after 5 min, reboot after 10 min. |
| Service dead | Units with `Restart=always` restart themselves. `pi_health.sh` restarts any service that is still down after 2 checks, again after 4, and reboots after 6. |
| SD card error, `/` read-only | `pi_health.sh` reboots. |

`pi_health.sh` runs every minute through `meteo-health.timer`, starting 5 minutes after boot. It checks these services: `meteo meteo-receiver meteo-alarms meteo-crono meteo-crono-remote meteo-battery mosquitto apache2 brickd tailscaled`. A service that is not installed is skipped.

For the network check it pings the router (the default gateway), not the internet. An internet outage never reboots the Pi.

Reboots are rate limited: at least 1 h apart and at most 4 in 24 h. If the router is switched off or a script crash-loops, the Pi does not reboot over and over.

The `watchdog` package daemon also runs. It cannot open `/dev/watchdog` because systemd holds it (`errno 16` in its log), so it adds nothing. It was left as it was.

### Also changed the same day
- **Wi-Fi power save off.** This is a frequent cause of a Pi 4 that is up but unreachable. Set with `nmcli con mod preconfigured 802-11-wireless.powersave 2`, applied live with `iw dev wlan0 set power_save off`.
- **Persistent journal.** It used to be `Storage=volatile`, so every reboot erased the log of the hang. Set in `/etc/systemd/journald.conf.d/50-persistent.conf` (`Storage=persistent`, `SystemMaxUse=64M`).

`meteo.db` is in `/dev/shm` and is lost on any reboot, automatic or manual. This was already the case.

## What happened
```sh
cat /var/lib/meteo-health/actions.log     # every action taken, survives reboots
cat /var/lib/meteo-health/reboots         # epoch + reason of each automatic reboot
journalctl -t meteo-health                # same, plus rate-limit messages
journalctl -b -1 -e                       # end of the previous boot: what led to the hang
last -x reboot | head
```

## Stopping a service on purpose
The script would restart it within 2 minutes. To prevent that:
```sh
sudo touch /run/meteo-health.pause        # service checks off until the next boot
sudo rm /run/meteo-health.pause
```
The network and filesystem checks still run while paused.

## Install / update
```sh
./deploy_pi.sh pi_health.sh meteo-health.service meteo-health.timer
# first time only, on the Pi:
sudo cp /var/www/html/meteo-health.{service,timer} /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now meteo-health.timer
```
Keep LF line endings in `pi_health.sh`.

## Undo
```sh
sudo systemctl disable --now meteo-health.timer
sudo rm /etc/systemd/system/meteo-health.{service,timer}; sudo systemctl daemon-reload
sudo nmcli con mod preconfigured 802-11-wireless.powersave 3   # Wi-Fi power save back on
sudo rm /etc/systemd/journald.conf.d/50-persistent.conf && sudo systemctl restart systemd-journald
```
