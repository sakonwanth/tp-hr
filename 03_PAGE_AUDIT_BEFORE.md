# 03 — Full page audit (before → after snapshot)

Status legend: **PASS** (already mobile-capable), **REFACTOR** (needed token/shell alignment).

## Global shell

| Check | Before | After |
|-------|--------|--------|
| Main landmark | `<div class="content-area p-4 sm:p-6">` | `<main id="tp-hr-main" class="content-area tp-native-page">` |
| Scroll / bottom overlap | Mixed `88px` bottom padding + inconsistent buffer | `native-shell.css`: buffer ≥120px; tab slot ≥88px; HR routes use non-tab bottom padding rule |
| Buttons / inputs | Mixed 44px / 16px radii | Primary **56px**, secondary **48px**, inputs **52px**, cards **20px** |
| Body | No token hook | `tp-native-app` + `tp-with-tab-nav` when not `hr-*` |

## Per-page (representative)

| Page | Layout | Before status | After status |
|------|--------|---------------|--------------|
| Dashboard | Hero + grids + cards | NEEDS_REFACTOR (padding / tokens) | PASS (shell + radius + button heights) |
| Checkin | Multi-card, forms | NEEDS_REFACTOR | PASS |
| Leave | Grid + forms | NEEDS_REFACTOR | PASS |
| Payslip / certificate / profile | Complex | NEEDS_REFACTOR | PASS |
| HR admin list/detail | Tables + filters | NEEDS_REFACTOR | PASS |
| Login | Self-contained | NEEDS_REFACTOR | PASS |
| Verify document | Light theme | NEEDS_REFACTOR | PASS |

No **BLOCKED** routes: business logic, auth, and URLs unchanged.
