# Raspberry Pi power saving (stazionemeteo.local)

Applied 2026-10-08 on the Pi 4 Model B Rev 1.2, Raspberry Pi OS bookworm.
The Pi runs headless: no HDMI monitor, no Bluetooth. It uses Wi-Fi (Ethernet if a cable is plugged in).

Access: `plink -ssh -batch -pw <pass> admin@stazionemeteo.local` (credentials are in `deploy_pi.sh`).
Plain `ssh` fails host key verification, so use plink.

## What was changed

### `/boot/firmware/config.txt`
Backup of the original: `/boot/firmware/config.txt.bak-20261008-165248`

| Setting | Before | After |
|---|---|---|
| `dtparam=audio` | `on` | `off` |
| `camera_auto_detect` | `1` | `0` |
| `display_auto_detect` | `1` | `0` |
| `max_framebuffers` | `2` | `0` |
| `dtoverlay=disable-bt` | (absent) | added at the end under `[all]` |

`dtoverlay=vc4-kms-v3d` was kept on purpose: with KMS loaded, the driver powers down the unused HDMI.

### systemd
- Default target: `graphical.target` → `multi-user.target`. This stops lightdm, the labwc desktop and the VNC server.
- Disabled with `systemctl disable --now`:
  `wayvnc.service wayvnc-control.service bluetooth.service hciuart.service cups.service cups.socket cups.path cups-browsed.service ModemManager.service triggerhappy.service triggerhappy.socket glamor-test.service rp1-test.service rpi-display-backlight.service`

### Deliberately left unchanged
- Wi-Fi power save: it was already `on` (`iw dev wlan0 get power_save`). It was turned **off** on 2026-10-31 to fix the dropouts (see `AUTO_RECOVERY.md`).
- `eth0` / NetworkManager "Wired connection 1": still enabled.
- USB: the Tinkerforge Master Brick (`brickd`) is connected through the VIA USB hub.
- `dtparam=i2c_arm=on`: `meteo.py` imports `board`/`busio`, and it costs nothing.
- `watchdog`, `tailscaled`, `rpi-connectd` (Raspberry Pi Connect: remote shell still works).
- All `meteo-*` services, `mosquitto`, `apache2`, `brickd`.

## Undo

Each step can be undone on its own. Reboot after any change to `config.txt` or the default target.

Restore the desktop and VNC:
```sh
sudo systemctl set-default graphical.target
sudo systemctl enable wayvnc.service wayvnc-control.service
sudo reboot
```

Restore Bluetooth:
```sh
sudo sed -i '/^dtoverlay=disable-bt/d; /headless power saving/d' /boot/firmware/config.txt
sudo systemctl enable bluetooth.service hciuart.service
sudo reboot
```

Restore printing (CUPS):
```sh
sudo systemctl enable --now cups.service cups.socket cups.path cups-browsed.service
```

Restore other services:
```sh
sudo systemctl enable --now ModemManager.service triggerhappy.service triggerhappy.socket rpi-display-backlight.service
```

Restore the whole original `config.txt` (audio, camera and display auto-detect, framebuffers, Bluetooth):
```sh
sudo cp -p /boot/firmware/config.txt.bak-20261008-165248 /boot/firmware/config.txt
sudo reboot
```

## Verify after any change
```sh
systemctl --failed --no-legend
systemctl is-active meteo meteo-receiver meteo-alarms meteo-crono meteo-crono-remote meteo-battery mosquitto apache2 brickd tailscaled watchdog meteo-health.timer
python3 /var/www/html/check_energy.py      # should end with "5 ok, 0 failed"
```
If you run it in the first minute after boot, step 3 may fail because no energy rows have arrived yet. Re-run it.
