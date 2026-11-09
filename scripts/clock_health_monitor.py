#!/usr/bin/env python3
"""Read-only time checks. This program never steps time or restarts NTP.

Sources: RFC5905 time-offset semantics; NTPsec ntpq peer offset is milliseconds.
Independent check uses ntpdate -q (query only), never ntpdate adjustment mode.
"""
import argparse
import datetime
import fcntl
import grp
import json
import math
import os
from pathlib import Path
import re
import subprocess
import secrets
import stat
import time


def command(args, timeout=3):
    try:
        p = subprocess.run(args, text=True, capture_output=True, timeout=timeout, check=False)
        return p.returncode, p.stdout.strip()
    except (OSError, subprocess.TimeoutExpired):
        return -1, ""


def peer_status(text):
    for line in text.splitlines():
        if not line.startswith(("*", "o")):
            continue
        parts = line.split()
        try:
            if len(parts) >= 10 and 0 < int(parts[2]) < 16 and int(parts[6], 8) > 0:
                offset = float(parts[-2])
                if math.isfinite(offset):
                    return {"synchronized": True, "peer_offset_ms": offset}
        except (ValueError, IndexError):
            pass
    return {"synchronized": False, "peer_offset_ms": None}


def independent_status(text):
    matches = re.findall(r"([+-][0-9]+(?:\.[0-9]+)?)\s+\+/-\s+([0-9]+(?:\.[0-9]+)?)", text)
    if not matches:
        return {"independent_offset_ms": None, "independent_uncertainty_ms": None}
    offset, uncertainty = map(float, matches[-1])
    return {"independent_offset_ms": offset * 1000, "independent_uncertainty_ms": uncertainty * 1000}


def classify(active, peer, independent, warning_ms=500.0, critical_ms=2000.0):
    reasons = []
    status = "healthy"
    offsets = [v for v in (peer.get("peer_offset_ms"), independent.get("independent_offset_ms")) if isinstance(v, (int, float)) and math.isfinite(v)]
    offset = max(offsets, key=abs) if offsets else None
    if not active or not peer.get("synchronized"):
        status = "critical";reasons.append("ntp_unsynchronized")
    if offset is None or abs(offset) > critical_ms:
        status = "critical";reasons.append("offset_unknown" if offset is None else "clock_offset")
    if status != "critical" and (abs(offset) > warning_ms or independent.get("independent_offset_ms") is None or (independent.get("independent_uncertainty_ms") or 0) > warning_ms):
        status = "warning";reasons.append("independent_unavailable" if independent.get("independent_offset_ms") is None else "clock_offset_warning")
    return {"status": status, "reason_codes": reasons, "offset_ms": offset, "ntp_active": bool(active), "synchronized": bool(peer.get("synchronized")), "critical_threshold_ms": critical_ms, "warning_threshold_ms": warning_ms, **peer, **independent}


def validate_status_directory_fd(fd, expected_uid=None):
    meta = os.fstat(fd)
    owner = os.geteuid() if expected_uid is None else expected_uid
    if not stat.S_ISDIR(meta.st_mode) or meta.st_uid != owner or meta.st_mode & 0o022:
        raise PermissionError("Clock status directory is not operator-owned and protected")


def open_status_directory(path, expected_uid=None):
    """Open a preprovisioned directory without following its final symlink.

    No path-based mkdir/chown/chmod is permitted here: the vhost parent belongs
    to the app user and can be renamed while this root process is running.
    """
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    fd = os.open(os.fspath(path), flags)
    try:
        validate_status_directory_fd(fd, expected_uid)
        return fd
    except BaseException:
        os.close(fd)
        raise


def open_lock(directory_fd):
    validate_status_directory_fd(directory_fd)
    flags = os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK
    fd = os.open("monitor.lock", flags, 0o600, dir_fd=directory_fd)
    try:
        meta = os.fstat(fd)
        if not stat.S_ISREG(meta.st_mode) or meta.st_uid != os.geteuid() or meta.st_nlink != 1 or meta.st_mode & 0o022:
            raise PermissionError("Untrusted clock monitor lock")
        return fd
    except BaseException:
        os.close(fd)
        raise


def write_status(directory_fd, result, group_id, filename="status.json"):
    """Atomic dirfd-relative replacement; never follows a replacement parent."""
    validate_status_directory_fd(directory_fd)
    if not re.fullmatch(r"[A-Za-z0-9_.-]+", filename) or filename in (".", "..", "monitor.lock"):
        raise ValueError("Invalid clock status filename")
    temporary = ".clock-" + secrets.token_hex(12) + ".tmp"
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC
    fd = os.open(temporary, flags, 0o600, dir_fd=directory_fd)
    try:
        # These operations apply only to our newly created, exclusive file FD.
        os.fchmod(fd, 0o640)
        os.fchown(fd, -1, group_id)
        with os.fdopen(fd, "w") as handle:
            fd = None
            json.dump(result, handle, indent=2, allow_nan=False)
            handle.write("\n");handle.flush();os.fsync(handle.fileno())
        os.replace(temporary, filename, src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
        os.fsync(directory_fd)
    finally:
        if fd is not None:os.close(fd)
        try:os.unlink(temporary, dir_fd=directory_fd)
        except FileNotFoundError:pass


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", "--status-file", dest="output", default="/var/www/vhosts/tp-asset.com/.clock-health/status.json")
    parser.add_argument("--group", default="psacln")
    parser.add_argument("--ntp-host", default="time.google.com")
    parser.add_argument("--warning-ms", type=float, default=500.0)
    parser.add_argument("--critical-ms", type=float, default=2000.0)
    opts = parser.parse_args()
    if os.geteuid() != 0:parser.error("Run as the root operator or systemd service")
    if not re.fullmatch(r"[A-Za-z0-9.-]+", opts.ntp_host) or not 0 < opts.warning_ms < opts.critical_ms <= 60000:parser.error("Invalid source or thresholds")
    group_id = grp.getgrnam(opts.group).gr_gid
    output = Path(opts.output)
    directory_fd = open_status_directory(output.parent)
    try:
        lock_fd = open_lock(directory_fd)
        with os.fdopen(lock_fd, "a") as lock:
            try:fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:return 0
            active = command(["systemctl", "is-active", "ntpsec"])[1] == "active"
            peer = peer_status(command(["ntpq", "-pn"])[1])
            code, query = command(["ntpdate", "-q", opts.ntp_host], timeout=5)
            independent = independent_status(query) if code == 0 else independent_status("")
            state = classify(active, peer, independent, opts.warning_ms, opts.critical_ms)
            state["checked_at"] = int(time.time())
            state["checked_monotonic_seconds"] = time.monotonic()
            state["checked_at_utc"] = datetime.datetime.now(datetime.timezone.utc).isoformat()
            try:state["checked_boot_seconds"] = float(Path("/proc/uptime").read_text().split()[0])
            except (OSError, ValueError):pass
            write_status(directory_fd, state, group_id, output.name)
            # systemd records this locally in the journal; no email/LINE calls.
            print(json.dumps({k: state[k] for k in ("status", "checked_at", "offset_ms", "reason_codes")}, allow_nan=False))
            return 2 if state["status"] == "critical" else 0
    finally:
        os.close(directory_fd)


if __name__ == "__main__":
    raise SystemExit(main())
