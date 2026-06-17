# systemd timer service

This directory stores the systemd unit used to supervise the YoShop 2.0 timer
process.

## Install on the server

```bash
sudo cp /opt/yoshop/deploy/systemd/yoshop2.0-timer.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now yoshop2.0-timer.service
```

The unit runs `php think timer start` directly and systemd restarts it if it fails.

For operational support, also review `../ops-support.md` for backup, rollback,
monitoring, and ACME renewal checks.

## Check status

```bash
systemctl status yoshop2.0-timer.service
journalctl -u yoshop2.0-timer.service -f
```
