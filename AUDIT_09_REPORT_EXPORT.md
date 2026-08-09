# AUDIT_09_REPORT_EXPORT.md

Date: 2026-04-30  
Mode: Read-only audit  
Project: `tp-hr`

## 1) Report/export surfaces discovered

1. `hr/reports.php` (CSV export)
2. `payslip.php` + `api/payslip.php` (payslip download/print)
3. `certificate_print.php` (print/PDF pathway)
4. `hr/employees.php` (export path guard text + in-page export behavior)

## 2) Source/query/filter audit

### `hr/reports.php`
- Data sources:
  - `hr_attendances`, `users`, `hr_leave_requests`, `hr_leave_types`, `hr_holidays`
- Filters:
  - `report`, `start_date`, `end_date`, `department`
- Security:
  - CEO-only gate
  - export allowed only by POST with CSRF token
  - GET `?export=csv` intentionally blocked and redirected
- Format:
  - UTF-8 BOM CSV (`fputcsv`)
  - filename sanitized via `hr_safe_content_disposition_filename()`

Assessment: strong export control and safe header handling.

### Payslip export/download
- Ownership enforced (`ps.user_id = current user`)
- Only approved/paid run statuses allowed
- Output is HTML attachment for print-to-PDF workflow
- Filename sanitized

Assessment: privacy control is correct; large-scale exports are not exposed to normal users.

### Certificate print/export
- Requires login and document access constraints
- CSRF required for POST print actions
- Document number and verification code handling present

Assessment: suitable for controlled document issuance flow.

## 3) Large data and performance risk

- `hr/reports.php` can aggregate across wide date ranges with joins/CASE aggregations.
- No explicit hard query-row cap in report page query itself.
- Mitigation: role restricted to CEO-level; still recommend maximum date-range guard for operational safety.

## 4) Accuracy checks against DB

Read-only consistency indicators:
- No orphan/duplicate anomalies in core tables used by reports (see `AUDIT_03_DATABASE_RELATIONSHIP.md`)
- Status enums and core settings pass preflight strict contract

## 5) Findings

| ID | Finding | Severity | Recommendation |
|---|---|---|---|
| REP-01 | Report queries permit broad date ranges without explicit max window | Medium | Add max range (e.g., 365 days) or async export for larger jobs |
| REP-02 | Payslip API names output as `.html` attachment (not true PDF generation) | Low | Clarify UI label or add server-side PDF renderer |

## 6) Verdict

- Report/export functions are permission-gated and operational.
- No blocker-level correctness issue found in audited report/export flows.

