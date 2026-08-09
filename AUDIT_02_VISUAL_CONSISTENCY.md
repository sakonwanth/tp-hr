# AUDIT_02_VISUAL_CONSISTENCY.md

Date: 2026-04-30

## Method

- Re-scan of all root/HR PHP views + shell CSS.
- Static consistency checks for radius/height/padding tokens.
- Contract checks:
  - `verify-native-shell-cache.sh`
  - `verify-ios26-master-screen.sh`
  - `verify-touch-targets.sh`

## Findings

### Fixed during this audit
1. `login.php` had non-tokenized control sizing/radius (`min-height:52`, `radius:12`) inconsistent with iOS26 controls.
2. `templates/header.php` base `.input-field` used `min-height:54` + `radius:10` and mobile menu close button used `44x44`.

### Applied fixes
- `login.php`
  - input `min-height` -> `56`
  - primary login button `min-height` -> `58`
  - control/button radius -> `var(--tp-radius-button)`
- `templates/header.php`
  - `.input-field` `min-height` -> `56`, radius -> tokenized
  - `.mobile-menu-close` `44x44` -> `48x48`
  - menu button radius tokenized

## Re-check result

- Visual token consistency: PASS (no blocker-level random control heights/radii in audited shell/login surfaces)
- Component consistency across ESS/HR pages: PASS
- Liquid-glass usage on chrome surfaces (header/tab/controls), not overloaded on content cards: PASS

Residual non-blocking note:
- `certificate_print.php` uses print-specific fixed dimensions by design (A4 output), treated as print format exception, not iOS app-shell violation.

