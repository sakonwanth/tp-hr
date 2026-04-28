# 07 — Page regression QA (after refactor)

## Automated / static checks

| Check | Result |
|-------|--------|
| Routes unchanged | Pass (no PHP route edits) |
| `Auth::requireLogin()` pages unchanged | Pass |
| CSRF / session | Pass (no bootstrap edits) |

## Layout QA (employee + tablet viewports)

| Check | Result |
|-------|--------|
| Bottom navigation overlap on last content | Mitigated via `main.tp-native-page` bottom padding + `--tp-scroll-end-buffer` |
| CTA visibility | Primary actions remain in content; hero CTA on dashboard unchanged |
| Safe area top | Existing `mobile-app-header-bar` uses `env(safe-area-inset-top)` |
| Safe area bottom | Tab nav + `padding-bottom: env(safe-area-inset-bottom)` on nav |
| Horizontal scroll | `html, body { overflow-x: clip }` in `native-shell.css` |

## Manual smoke (recommended)

1. Log in → Home: scroll to bottom; confirm last card clears bottom nav.  
2. Open Check-in: primary actions tappable; no clipped inputs.  
3. HR user: open `/hr/employees.php`; confirm no double horizontal padding on desktop.  
4. Log out → Login: submit with 52px+ fields and 56px buttons (visual).

## Failures

None recorded for this migration.
