# HR organization management

Implemented and deployed 2026-11-05. Runtime release: HR `013d06de49ffa87a5f8a9b959292df67f6eff494`, Check-in `90d14a7fd14015e4a4b0a32a9e0f97968202046d`. Production migration and read-only real-account verification passed. A successful live attendance write and normal password/SSO login have NOT been tested; the user confirmed no dedicated test account exists, and real employee attendance must not be changed.

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

## Release procedure and remaining acceptance

1. Review the HR and Check-in changes together, leaving unrelated working-tree changes separate.
2. Deploy the Check-in empty-catalog guard; verify at least one usable active point exists before enforcing GPS attendance.
3. Apply `2026_11_05_hr_organization.sql` using the existing migration runner, then run `scripts/verify_organization_schema.php`. The HR deploy workflow includes both steps. This migration only adds tables and the singleton lock row.
4. Deploy HR. Verify with real HR/Admin and employee accounts: menu visibility, direct URL/POST denial, add/edit/deactivate, duplicate/stale requests, and assignment/legacy employee form behavior.
5. Verify real Check-in/out inside/outside the revised radius in the deployed integration mode, WFH, outside approval, and old history labels. Confirm CRM/ERP employee display and payroll/report results remain correct. No employee notification should be sent merely for catalog editing.
6. Capture deployed commit identifiers, fresh logs and acceptance evidence. API-only checks or this fixture are not sufficient production acceptance.

Rollback: revert application changes if needed but retain additive tables, audit, all location versions and attendance foreign keys. Do not delete replacement points that have attendance references, drop audit tables, or bulk rewrite employee titles. Investigate current active points before changing a retired version or reversing an assignment.
