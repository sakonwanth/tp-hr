# 03_PAGE_AUDIT_BEFORE.md — TP-HR baseline (before per-page refactor pass)

**Rule:** Until a screen is ported to strict IOS26 markup (see **`05_IOS26_COMPONENT_SYSTEM.md`**), classify as **`NEEDS_REFACTOR`**.

Cross-cutting gaps (CSS + shells):

| Area | Observation | Severity |
|------|-------------|----------|
| Token split | Spacing/visual tokens duplicated between **`header.php` `<style>`** and **`native-shell.css`** | High |
| Glass usage | Older pages still use **`glass-card` heavily on content rows** vs control layers | Medium |
| Bottom tab | Prior to **native-shell v12**, active tab chips were **not full grid width** → fixed globally | Resolved (CSS) |
| Scroll buffer | Token **`--tp-scroll-end-buffer`** bumped to **144px**, tab slot **`≥96px`** | Improved |
| HR tables | Wide tables rely on **`overflow-x-auto`** — card migration pending per **`04_PAGE_REFACTOR_TODO`** | Medium |

Screen matrix (compact):

| Screen file | PASS / NEEDS_REFACTOR |
|-------------|------------------------|
| `index.php` | NEEDS_REFACTOR · hero + quick-actions OK structurally · tighten typography scale |
| `checkin.php` | NEEDS_REFACTOR · camera/GPS UX · confirm sticky actions |
| `leave.php`, `leave_history.php`, `modules/.../request_form.php` | NEEDS_REFACTOR · form grouping/inset sections |
| `attendance_history.php` | NEEDS_REFACTOR · timeline density |
| `payslip.php`, `certificate.php`, `dayoff_schedule.php` | NEEDS_REFACTOR · list→card readability |
| `profile.php` | NEEDS_REFACTOR · grouped fields |
| `hr/*.php` (all templated routes) | NEEDS_REFACTOR · dashboards + admin tables pattern |
| `login.php`, `verify_document.php`, `certificate_print.php` | NEEDS_REFACTOR · unify brand + radii |

**Blocked:** none (no blocker on logic discovered during static audit).
