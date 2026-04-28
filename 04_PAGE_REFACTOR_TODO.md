# 04 — Page refactor TODO (completed in this migration)

All tasks below were executed as **UI-only** changes (markup classes, shared CSS, typography, spacing tokens). No API, schema, route, or permission changes.

| Task ID | Page | Issue | Solution | Risk | Done |
|---------|------|-------|----------|------|------|
| HR-UI-001 | All header pages | No unified scroll buffer / tab overlap | Introduce `native-shell.css` + `main.tp-native-page` + conditional `tp-with-tab-nav` | Low | ✅ |
| HR-UI-002 | Employee routes | Bottom nav overlap | `padding-bottom` via tokens on `main.tp-native-page` | Low | ✅ |
| HR-UI-003 | HR routes | No tabs but need safe scroll end | `body:not(.tp-with-tab-nav) main` bottom padding | Low | ✅ |
| HR-UI-004 | Global | Input/button touch targets | Header CSS + `native-shell.css` | Low | ✅ |
| HR-UI-005 | Login | 48px CTAs | `56px` buttons, `52px` inputs, IBM Plex + `native-shell` link | Low | ✅ |
| HR-UI-006 | Verify document | Font / radius / touch | IBM Plex Thai, 20px card, 52/56 fields | Low | ✅ |

**Test checklist (manual):**  
Open employee page on 390px width: header clears notch; scroll last card above bottom nav; no horizontal scroll; FAB/sticky not required on dashboard for this release.

**Completion criteria:** All rows **✅**; shell CSS versioned `?v=1`.
