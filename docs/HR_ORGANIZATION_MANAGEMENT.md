# HR workplace and GPS management

## Scope correction (2026-11-05)

The user clarified that “ตำแหน่ง” means geographic location, not job title. The current HR menu is **สถานที่ทำงานและพิกัดลงเวลา**, with only workplaces and GPS points. Old job-title/assignment tab links redirect to workplaces; employee job titles remain editable in the employee form. Existing catalog tables and audit history are retained without deletion.

Workplace and point codes are generated on the server under the catalog transaction lock (`WP-` / `LOC-` plus a sequence). Users do not enter codes, submitted overrides are ignored, and edits retain existing codes. GPS revisions preserve the original attendance location IDs. No migration, payroll change, Check-in policy change, or temporary QA grant is needed.

The sections below document the earlier release and its historical verification; references to four tabs and central job-title assignment describe the superseded UI.


Implemented and deployed 2026-11-05. Runtime release: HR `013d06de49ffa87a5f8a9b959292df67f6eff494`, Check-in `90d14a7fd14015e4a4b0a32a9e0f97968202046d`. Production migration and read-only real-account verification passed. The initial release used read-only sessions. A subsequent authorized run created disposable TEST accounts and verified normal HR password login and successful HR attendance writes; see the end-to-end QA section below. Dedicated Check-in login still excludes TEST accounts, and SSO/provider login was not tested.

The scoped release used exact-commit fast-forward over SSH after CI passed, without common-library updates, dependency upgrades or unrelated finance reconciliation. Commits tagged `[scoped-deploy]` run validation but intentionally skip the general deploy job; its green workflow result is not deployment evidence by itself. Server revision verification and the migration log establish this release.

Production checks on 2026-11-05:
- The migration applied all six statements; schema verification passed; both public health endpoints returned HTTP 200.
- Temporary server-issued sessions exercised existing active OFFICE accounts: Chairman with HR management authority, and Staff. No active literal HR-role OFFICE account was available. Normal password/SSO authentication was not exercised. Temporary sessions were deleted after verification; existing sessions/passwords were untouched.
- All four HR catalog tabs opened; missing-CSRF writes were rejected. Staff opened Check-in, both history surfaces and today's API; catalog GET/POST and check-in/out POST without CSRF returned 403.
- Deployed writer mode is HR API. Read-only evaluation with real location data accepted the active point center and rejected distant GPS. Historical check-in/out foreign keys have no missing location rows.
- SHA-256 comparison before/after was identical for 982 past attendance records, business fields of 15 user records, all existing position rows and both location rows. Newly added workplace/assignment/audit tables remained empty. No business test rows were inserted.
- Domain error logs did not grow during deployment and these checks (HR 0 bytes; Check-in 84,915 bytes before and after).
- CI: HR https://github.com/sakonwanth/tp-hr/actions/runs/42002105019; Check-in https://github.com/sakonwanth/tp-checkin/actions/runs/42001971842.


## Entry points and ownership

- HR navigation (desktop/mobile): **ตำแหน่งและสถานที่ทำงาน** → `/hr/organization.php`.
- Tabs: positions, workplaces, check-in points, employee assignments.
- HR settings and employee edit also link to this page. This is outside the CEO-only settings gate so HR can use it.
- HR owns `hr_positions`, `hr_workplaces`, `hr_checkin_locations` and assignments. Check-in reads active `hr_checkin_locations` through existing GPS helpers in both the direct-write and HR-API client flows.
- `hr_location_workplaces` associates points with workplaces. Workplace assignment is personnel metadata; it does not restrict an employee to only their home workplace. All active points remain eligible under the existing policy.
- CRM/ERP and existing document/payroll consumers keep reading `users.position`. No role, salary, department, payroll rule or historic attendance is migrated or recalculated.

## Permissions and preservation

- Login plus HR dashboard access at the page; active HR/Admin/CEO/Chairman is rechecked against the database at every service mutation. An ACL-only custom role is not automatically granted catalog mutation rights.
- POST and CSRF required. Prepared SQL, allowlisted fields, escaped display, coordinate/radius validation, duplicate-code checks and optimistic version tokens apply.
- A transactional singleton lock serializes master changes and assignments, including concurrent attempts to close the final active point. Audit insertion is in the same transaction and cannot silently fail.
- No catalog deletion endpoint. Deactivation retains assignments and attendance references. A workplace with active linked points cannot be deactivated until those points are moved or closed.
- A location detail change creates a replacement ID and retires the old row, preserving the original name/coordinates even for an in-flight attendance submission. Old code is released to the replacement; its original value remains in audit. Retired versions cannot be reactivated.
- New assignments must use active entries; existing assignments to inactive entries can remain. Position changes explicitly update `users.position`; unbinding preserves its existing text. Renaming the catalog does not rewrite employees. The assignment form has an explicit option to refresh their displayed title.
- Employee form updates preserve a linked position at the SQL write boundary, including an older browser tab. Edit linked positions through organization assignments.
- Assignment audit includes the displayed title and workplace name at the time of the change. Existing historical records predating this feature are retained; missing historical snapshots cannot be reconstructed automatically.
- Check-in now fails closed when there are no active GPS points. Existing WFH and optional-location policy branches remain intact. HR API GPS submissions reject retired IDs with 409; Manual historical entry keeps its prior behavior.

## Verification completed

- PHP syntax and `git diff --check` for changed files.
- `tests/organization_service_test.php`: isolated SQLite lifecycle, permissions, validation, revision/history, stale form rejection, assignment compatibility, audit rollback and actual sibling Check-in helper behavior.
- `tests/organization_mysql_test.php`: separate temporary socket-only MariaDB, actual baseline schema, migration applied twice, same lifecycle tests and two concurrent point deactivations (one succeeds, one is blocked).
- Attendance service, daily attendance policy, payroll service and outside attendance lifecycle regression tests pass.
- Existing role source checks pass (8); existing static UI checks pass. These are not real-session acceptance.
- Browser exercised the real organization page and real HR header/footer with isolated fixture authentication/database: position creation, employee assignment, missing-CSRF rejection, employee GET/POST 403, and no horizontal overflow at 375/768/1024 pixels. The fixture does not prove SSO, live role configuration or real GPS/camera behavior. Fixture-only favicon/service-worker requests returned 404.
- CI runs the SQLite lifecycle test. Cross-repository Check-in checks explicitly skip when the sibling checkout is absent; run locally with both repositories for full integration coverage.

Commands (from tp-hr):

```sh
/Applications/XAMPP/xamppfiles/bin/php tests/organization_service_test.php
# Only a disposable QA socket matching this path is accepted; never a company DB:
/Applications/XAMPP/xamppfiles/bin/php tests/organization_mysql_test.php /tmp/tp-hr-org-qa.XXXXXX/mysql.sock
npm run verify:static-ui
/Applications/XAMPP/xamppfiles/bin/php scripts/qa_role_regression.php --no-http
```


## Production end-to-end QA — 2026-11-05

After explicit authorization to create and remove test accounts, two isolated roles (HR and Staff) were created with `account_type=TEST`, `is_test_account=1`, `QAE-` employee codes, zero salary and no LINE identity. The actual payroll/person filters excluded both accounts before any test requests.

The first run exposed a history display defect: the HR history calendar stopped at the last closed day and hid today's recorded punches. Both fixture accounts and all associated test data were cleaned immediately. Commit `802751f323f5ab9c6b11dbc758ff0d7709fe458d` fixes display only: recorded activity today is visible, while unrecorded/open days and payroll/absence calculations retain the original cutoff. Focused tests, CI and deployment passed.

The repeat run at 17:49:48–17:49:58 Asia/Bangkok passed 33 assertions through real HTTPS forms/APIs and real password verification:
- HR/Staff local password login; Staff denied access to catalog management.
- Create/edit positions and workplaces; create a GPS point; assign position/workplace to the Staff test account.
- Successful HR check-in and checkout, and duplicate-entry/exit rejection, with synthetic coordinates at the isolated test point. This tests server persistence and location rules, not a physical phone GPS sensor or camera.
- Edit the point between entry and exit; the recorded entry retained the original point ID/name/coordinates, while exit referenced the replacement. History displayed today's recorded times and catalog change history.
- Deactivate test points/workplace/position while retaining the test attendance, then clean up.
- Actual dedicated Check-in password login rejected the TEST account as designed. Successful end-to-end attendance was tested through HR, not through the separate Check-in UI/HR-API writer path. No production-person classification or login restriction was weakened to force a pass.
- No payroll slips were created and no LINE notification-log entries were added during the test. No leave/offsite/planned-late requests or notification-producing flows were invoked.

Both runs were cleaned by exact fixture IDs with TEST-identity checks, a transaction and an unexpected-foreign-key guard. Test sessions/cookie jars were removed; passwords were generated in memory and not retained. A separate read-only verification found zero remaining test users, attendance, assignment, audit, catalog, location-link or payroll rows, and no remaining user foreign-key references. Auto-increment counters were not reset.

Before/after hashes matched for business fields of 15 original users, 982 past attendance records, original locations and all organization catalogs/assignments. Evidence includes assertion results, test audit-event metadata, cleanup verification and the first failed run (already cleaned), so the initial failure is not hidden.

CI for the history fix: https://github.com/sakonwanth/tp-hr/actions/runs/42013815473.

## Release procedure and remaining acceptance

1. Review the HR and Check-in changes together, leaving unrelated working-tree changes separate.
2. Deploy the Check-in empty-catalog guard; verify at least one usable active point exists before enforcing GPS attendance.
3. Apply `2026_11_05_hr_organization.sql` using the existing migration runner, then run `scripts/verify_organization_schema.php`. The HR deploy workflow includes both steps. This migration only adds tables and the singleton lock row.
4. Deploy HR. Verify with real HR/Admin and employee accounts: menu visibility, direct URL/POST denial, add/edit/deactivate, duplicate/stale requests, and assignment/legacy employee form behavior.
5. Verify real Check-in/out inside/outside the revised radius in the deployed integration mode, WFH, outside approval, and old history labels. Confirm CRM/ERP employee display and payroll/report results remain correct. No employee notification should be sent merely for catalog editing.
6. Capture deployed commit identifiers, fresh logs and acceptance evidence. API-only checks or this fixture are not sufficient production acceptance.

Rollback: revert application changes if needed but retain additive tables, audit, all location versions and attendance foreign keys. Do not delete replacement points that have attendance references, drop audit tables, or bulk rewrite employee titles. Investigate current active points before changing a retired version or reversing an assignment.
