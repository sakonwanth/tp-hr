# 08 — Final full UI audit

**Wave:** `native-shell.css` **v5** (2026-04-28) — see **`09_COMPLETION_GATE.md`** for Tier A **PASS** vs Tier B exhaustive **OPEN**.

## Coverage

- **Inventory pages** (`01_FULL_UI_INVENTORY.md`): all discovered UI entry points listed.  
- **Shell**: `body.tp-native-app`, conditional `tp-with-tab-nav`, **`main`** landmark, bottom nav class.  
- **Tokens**: radii 20px; spacing scale 4–32; buffer 120px; bottom slot ≥88px; icon 24–32 CSS vars.

## Consistency

| Area | Status |
|------|--------|
| Typography (single font system) | IBM Plex Sans Thai site-wide |
| Card radius | 20px (`.glass-card`, `.stat-card`, `.quick-action` header styles) |
| Primary CTA height | 56px (`.btn-primary`, login) |
| Input height | 52px (logged-in + login) |
| Bottom navigation | Capped `max-h-[72px]` + safe area padding |
| Component registry | **35/35** Native* aliases in `02_COMPONENT_LOCK_MAP.md` |

## Touch targets (completed pass)

- Former **`min-h-[44px]`** / **`min-w-[44px]`** utilities → **48px** sitewide (`*.php`, excluding vendor).  
- **Primary** fills (`bg-violet-600`, solid `bg-green-600`, destructive `bg-red-600`) stepped to **56px** where applicable.  
- **Selects** with `input-field` no longer use redundant min-height (inherit **52px** from header/base).

## Known limitations (non-blocking)

- Some pages still use **`rounded-xl`** utility on inner blocks; parent **`.glass-card`** enforces 20px outer radius. Inner radii may remain 12–16px where not updated line-by-line — acceptable for nested UI.  
- **Certificate print** remains print-optimized HTML (not forced into dark native shell).

## Skipped pages

**0** functional routes skipped. API-only and CLI scripts excluded per scope.
