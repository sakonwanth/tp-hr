# Attendance capture closeout — 2026-11-09

Normal capture belongs to HR and Check-in. CRM's retired capture routes remain
closed. Administrative attendance corrections use their existing separate HR
workflow; normal capture cannot supply an arbitrary timestamp or capture type.

Check-in must submit `latitude`, `longitude`, `gps_captured_at` (epoch
milliseconds), a detailed camera photo and optional GPS `accuracy`. Each
confirmation obtains a new GPS fix with `maximumAge: 0`. A permission failure
clears the previous fix. The server accepts fixes at most 120 seconds old with
30 seconds of forward tolerance, then rechecks mutable location/attendance
state under transaction locks. Outside-area captures require the existing
pending approval workflow. No global geofence or employee restrictions change.

The HR service API accepts a fresh, phase-specific photo path belonging to the
target user in Check-in storage. It validates the stored image again. Both
applications use HR's `AttendancePhotoEvidence` policy. Blank, uniform, tiny or
invalid images are rejected. This is image-detail validation, not identity or
biometric verification. GPS freshness is request evidence, not GPS attestation.

Capture timestamps, photo/device evidence and GPS capture/accuracy/receipt
metadata commit in one transaction. A rejected upstream HR request never falls
back to a local write. Unreferenced images are removed after rollback; uncertain
database outcomes retain the file for investigation. Historical rows are not
backfilled by the six-column additive migration.

Clock admission uses the root-owned monitor described in
[CLOCK_HEALTH_MONITOR.md](CLOCK_HEALTH_MONITOR.md). A critical, missing or stale
production clock result stops new capture and time-dependent HR jobs. The
monitor observes NTP and never adjusts time. Planned-late decisions use the
same canonical HR policy from the web and LINE adapter; finalized payroll
periods remain locked, and affected calculated periods must be recalculated.
Legacy service API plan/cancel routes return 410; no usage was found in the
90-day release preflight.

## Release order and rollback

1. Pass focused behavior, regression and repository CI checks.
2. Install and verify the external monitor before enabling admission guards.
3. Apply the nullable metadata migration with `ALGORITHM=INSTANT, LOCK=NONE`
   and a short metadata-lock timeout; stop if the instant operation cannot run.
4. Deploy the selected HR receiver files, then Check-in producer files, with
   expected-before hashes, staged PHP lint and protected file backups.
5. Verify real production endpoints using disposable TEST accounts only, with
   synthetic coordinates/images. Exclude these accounts from payroll and
   suppress LINE. Remove exact fixtures, photos, sessions and temporary grants.
6. Compare original business rows using their pre-migration columns and IDs,
   check health/deployed hashes, and inspect a fresh post-deploy log window.

Rollback restores the selected backed-up files, producer before receiver, after
checking no intervening file edits. Keep the nullable additive columns; dropping
them would discard evidence from captures made after release. Preserve all
committed attendance/history. An already-open old capture page may be asked to
refresh because it does not supply the new required GPS evidence.

The historical incident audit is read-only. Review candidates and hypothetical
offset scenarios do not prove individual records wrong and do not authorize
automatic changes to attendance or payroll. The drift onset and original host
cause remain unconfirmed without independent operational evidence.
