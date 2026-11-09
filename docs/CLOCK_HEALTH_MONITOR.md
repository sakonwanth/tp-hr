# Production clock health

The clock monitor observes time; it never runs a time adjustment, restarts NTP,
or disables its panic protection. The cause of the original clock drift is
still unconfirmed. NTP being healthy now does not establish its historical cause.

The systemd timer runs every 60 seconds, with a 20-second service timeout.
It checks `ntpsec`, a selected reachable `ntpq -pn` peer, and an independent
`ntpdate -q time.google.com` sample (query only, five-second timeout). NTP peer
offset is in milliseconds; the independent query reports seconds and is
converted to milliseconds. The worse absolute observed offset is retained.

Default warning threshold: 500 ms. Critical threshold: 2,000 ms. Operators may
set thresholds with service command-line arguments after reviewing the policy.
Inactive/unsynchronized NTP or a critical offset produces a critical result.
A missing independent response while local NTP is synchronized produces a
warning; it does not itself stop capture. No email, LINE or push is sent.
Failures are recorded in the local journal and an `OnFailure` logger service.

## Install receiver before capture guards

Install the repository's monitor into a root-owned executable directory outside
all application trees. Never execute the app-user-writable repository copy as
root from systemd.

```sh
install -d -o root -g root -m 0755 /usr/local/lib/tp-clock-health
install -o root -g root -m 0755 scripts/clock_health_monitor.py /usr/local/lib/tp-clock-health/clock_health_monitor.py
# Provision/verify the directory without following a replaceable child symlink.
python3 - <<'PYSETUP'
import grp, os, stat
flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
parent = os.open('/var/www/vhosts/tp-asset.com', flags)
fd = None
try:
    try: os.mkdir('.clock-health', 0o750, dir_fd=parent)
    except FileExistsError: pass
    fd = os.open('.clock-health', flags, dir_fd=parent)
    meta = os.fstat(fd)
    if meta.st_uid != 0 or meta.st_mode & 0o022:
        raise RuntimeError('Untrusted pre-existing clock directory; inspect manually')
    os.fchown(fd, 0, grp.getgrnam('psacln').gr_gid)
    os.fchmod(fd, 0o750)
    current = os.stat('.clock-health', dir_fd=parent, follow_symlinks=False)
    if (current.st_dev, current.st_ino) != (meta.st_dev, meta.st_ino):
        raise RuntimeError('Clock directory changed during setup')
finally:
    if fd is not None: os.close(fd)
    os.close(parent)
PYSETUP
install -o root -g root -m 0644 deploy/systemd/tp-clock-health.service /etc/systemd/system/
install -o root -g root -m 0644 deploy/systemd/tp-clock-health.timer /etc/systemd/system/
install -o root -g root -m 0644 deploy/systemd/tp-clock-health-failure.service /etc/systemd/system/
systemctl daemon-reload
systemctl start tp-clock-health.service
systemctl enable --now tp-clock-health.timer
```

Inspect the first status and permissions before deploying consumers.
`/var/www/vhosts/tp-asset.com/.clock-health/status.json` must be a regular file,
root-owned and mode `0640` with group `psacln`; its directory is `0750`.
The file is outside webroots and can be read under the existing Plesk vhost
filesystem scope. PHP opens it once, validates ownership/mode/type/size with
`fstat`, and reads the same file handle, preventing a path-swap race between
trust checks and JSON reads. `/proc/uptime` is optional when Plesk forbids it. The monitor never creates directories or applies path-based ownership/permission
changes. It opens the preprovisioned directory with `O_DIRECTORY|O_NOFOLLOW`,
checks owner and write bits, and performs lock, exclusive temp-file creation and
replacement relative to that directory FD. Renaming the parent cannot redirect
writes or permission changes to a symlink target. A swapped/untrusted directory
fails closed on the next run. Writes are atomic. The monitor does not include application
credentials, employee data or raw request data.

## Consumers

`ClockHealth::read()` reports `healthy`, `warning`, `critical` or `disabled`.
Production missing/unreadable/invalid status, status older than 180 seconds,
or a future status is critical. A wall-time versus boot-elapsed mismatch also
identifies clock steps when `/proc/uptime` is available. Explicit fixture paths
and times support isolated tests; normal requests use the protected default.

`ClockHealth::assertSafe()` throws a user-safe `DomainException` only for critical
status, before new attendance writes. Local development without a monitor is
disabled. Existing attendance history, reporting and approved payroll are never
rewritten by this mechanism. HR administrators see a persistent status warning,
and `/api/health.php` reports a clock check (HTTP 503 for critical status).

If the monitor fails, inspect `journalctl -u tp-clock-health.service` and
`journalctl -u ntpsec` plus the protected status. Correct the underlying issue
under an operator-controlled maintenance procedure; do not replay scheduled
notifications or alter historical timestamps as an automatic repair.

## Verification

```sh
php tests/clock_health_test.php
python3 tests/clock_health_monitor_test.py
```

These cover fresh/stale/future status, missing/invalid/untrusted files, both
offset directions, local versus independent drift, clock steps, warning behavior,
atomic writes, permissions, symlink/owner/write-bit rejection, parent-rename races,
and Plesk restrictions on `/proc/uptime`. They never query or change the production clock.
