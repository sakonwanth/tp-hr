# TP-HR External API (v1)

Base URL: `https://hr.tp-asset.com/api/v1`

## Authentication
```
Authorization: Bearer tphr_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```
สร้างคีย์ที่ `/hr/api_keys.php` (CEO+)

## Scopes

| Scope | ใช้กับ |
|---|---|
| `employees.read` | `/employees` |
| `attendance.read` | `GET /attendance` |
| `attendance.write` | `POST /attendance/checkin`, `/checkout` |
| `leave.read` | `GET /leave` |
| `leave.write` | `POST /leave`, `POST /leave/{id}/cancel` |
| `leave.approve` | `POST /leave/{id}/approve|reject` |
| `dayoff.read/write/approve` | `/dayoff-requests` |
| `overtime.read/write/approve` | `/overtime` |
| `outside.read/approve` | `/outside-attendance` |
| `adjustments.read/approve` | `/attendance-adjustments` |
| `payroll.read` | `/payroll-runs`, `/payslips` (เฉพาะ approved/paid) |
| `hr.read` | `/departments`, `/positions`, `/holidays`, `/leave-types`, `/employee-schedules`, `/announcements`, `/leave-entitlements` |
| `*` | ทั้งหมด |

## Endpoints

### Public
- `GET /ping`

### Employees (`employees.read`)
- `GET /employees?page=&per_page=&include_inactive=`
- `GET /employees/{id}`

### Attendance
- `GET /attendance?date=YYYY-MM-DD[&user_id=]` or `?from=&to=[&user_id=]` (≤90d) — `attendance.read`
- `POST /attendance/checkin` — `attendance.write`
  ```json
  { "user_id": 5, "time": "2026-04-21 09:00:00", "type": "GPS", "latitude": 13.75, "longitude": 100.5 }
  ```
- `POST /attendance/checkout` — same body

### Leave (`leave.*`)
- `GET /leave[?status=&from=&to=&user_id=]`
- `GET /leave/{id}`
- `POST /leave` — `leave.write`
  ```json
  { "user_id": 5, "leave_type_id": 1, "start_date": "2026-05-01", "end_date": "2026-05-02",
    "start_period": "FULL", "end_period": "FULL", "total_days": 2, "reason": "..." }
  ```
- `POST /leave/{id}/approve` — `leave.approve` — `{ "approver_level": 1, "approver_id": 3 }`
- `POST /leave/{id}/reject` — `{ "approver_level": 1, "approver_id": 3, "remarks": "..." }`
- `POST /leave/{id}/cancel` — `leave.write` — `{ "user_id": 5 }`

### Day-off requests (`dayoff.*`)
- `GET /dayoff-requests[?status=&from=&to=&user_id=]`
- `POST /dayoff-requests` — `{ user_id, week_start, week_end, original_day_off, requested_day_off, reason }`
- `POST /dayoff-requests/{id}/approve|reject` — `{ reviewer_id, note }`

### Overtime (`overtime.*`)
- `GET /overtime[?status=pending|approved|rejected|cancelled&from=&to=&user_id=]`
- `POST /overtime` — `{ user_id, work_date, planned_start, planned_end, ot_type, rate_multiplier, reason }`
- `POST /overtime/{id}/approve` — `{ approver_id, actual_hours }`
- `POST /overtime/{id}/reject` — `{ approver_id, reason }`

### Outside attendance (`outside.*`)
- `GET /outside-attendance[?status=&from=&to=&user_id=]`
- `POST /outside-attendance/{id}/approve|reject` — `{ reviewer_id, remarks }`

### Attendance adjustments (`adjustments.*`)
- `GET /attendance-adjustments[?status=&from=&to=&user_id=]`
- `POST /attendance-adjustments/{id}/approve|reject` — `{ reviewer_id, remarks }`
  - Approval actor must be CEO-level (`Admin`, `Chairman`, `CEO`).
  - On approve, writes `requested_check_in/out` back to `hr_attendances`.

### Payroll (`payroll.read`, approved/paid only)
- `GET /payroll-runs`
- `GET /payroll-runs/{id}`
- `GET /payroll-runs/{id}/slips`
- `GET /payslips?month=YYYY-MM[&user_id=]`
- `GET /payslips/{id}`

### HR metadata (`hr.read`)
- `GET /departments`, `GET /positions`, `GET /positions`, `GET /holidays?year=YYYY`,
  `GET /leave-types`, `GET /employee-schedules[?user_id=]`,
  `GET /announcements`, `GET /leave-entitlements?year=YYYY[&user_id=]`

## Response

Success: `{ "success": true, "data": ..., "meta": ... }`
Error: `{ "success": false, "error": "..." }`

Status codes: 200, 201, 400, 401, 403, 404, 405, 409 (conflict), 429, 500

## Rate limit
Per key. Headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`, `Retry-After` on 429.

Counters are stored under `storage/api_ratelimit/` with a fallback directory under the server temp dir if that path is not writable. If neither is usable, the API responds with **503** `Rate limit store unavailable` unless the host sets **`HR_API_RATELIMIT_FAIL_OPEN=1`** (legacy fail-open: allow the request without counting).

## Example

```bash
curl -sS -X POST https://hr.tp-asset.com/api/v1/attendance/checkin \
  -H "Authorization: Bearer tphr_xxx" \
  -H "Content-Type: application/json" \
  -d '{"user_id":5,"type":"GPS","latitude":13.75,"longitude":100.5}'
```
