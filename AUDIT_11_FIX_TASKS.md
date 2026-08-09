# AUDIT_11_FIX_TASKS.md

Date: 2026-04-30  
Project: `tp-hr`

## Failed-item fix tasks

Current failed blocker items: **0**

## Fix tasks executed in this audit cycle (completed)

1. Task: Align login control sizing/radius to iOS26 token contract  
   - File: `login.php`  
   - Changes:
     - input `min-height` -> `56px`
     - primary button `min-height` -> `58px`
     - tokenized radius for controls/buttons  
   - Status: DONE

2. Task: Align shell input + close control to minimum touch size/token radius  
   - File: `templates/header.php`  
   - Changes:
     - `.input-field` -> `56px` + token radius
     - mobile close control `44x44` -> `48x48`
     - tokenized button radii  
   - Status: DONE

## Verification after fixes

- `npm run -s verify:static-ui` -> PASS
- `PLAYWRIGHT_SKIP_TABLET=1 npm run -s test:e2e` -> PASS (`30/30`)
- `PLAYWRIGHT_BASE_URL=https://hr.tp-asset.com/ PLAYWRIGHT_SKIP_TABLET=1 npm run -s test:e2e` -> PASS (`30/30`)
- `/opt/plesk/php/8.4/bin/php scripts/production_preflight.php --strict` -> PASS (`0 failure, 0 warning, 70 ok`)

## Remaining actions

No mandatory fix tasks remain for this audit cycle.
