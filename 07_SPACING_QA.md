# 07_SPACING_QA.md — TP-HR IOS26 spacing gate

Apply **per page** after refactor in **`06_IMPLEMENTATION_PROGRESS.md`**.

**Which pages?** Full route list (ESS · HRA · PUB) is in **`08_VISUAL_QA_AFTER.md`** row matrices — run **`07`** alongside **`08`** questions on the same pass when possible.

Target tokens: **`00_IOS26_DESIGN_DIRECTION.md`** + **`01_IOS26_COMPONENT_SYSTEM.md`**.

### Global PASS criteria (blocking any page)

| # | Check | Method |
|---|-------|--------|
| 1 | Page L/R inset **≥16px** mobile (**24px tablet**) | Inspect `main.tp-native-page*` |
| 2 | Header-to-first section **≥16px** | DevTools ruler |
| 3 | Between titled sections visually **≥24px** (**≥20 interim**) | screenshot |
| 4 | Card-to-card **≥16px** | markup gap tokens |
| 5 | Card inner pad **≥20px** mobile | replace `p-4` outliers |
| 6 | Field group spacing **≥18px** | `.tp-native-form-group` |
| 7 | Inputs **≥56px** tall target (**54 interim**) | ruler |
| 8 | Primary CTAs **≥58px target** (**56 interim**) | ruler |
| 9 | CTA above tab **`24px`** + **`--tp-bottom-nav-slot`** clearance | screenshot |
|10 | **`--tp-scroll-end-buffer`** so last line unobstructed — **≥160 target** (**144 interim**) — **FAIL if overlap** |
|11 | Tabs never collide with body | scroll end |
|12 | Last element readable | scroll full down |
|13 | Inputs not hidden behind keyboard (**forms only**) — test iOS Simulator / device |
|14 | No **cramped stacking** unrelated tokens | heuristic |
|15 | Columns align vertically on grid breakpoints | breakpoint QA |

Any **FAIL:** fix **`native-shell`** token first; then page-level classes.

---

### Page checklist (duplicate template per route)

```
Route: _________ Date: _____ Tester: _____ Device: _____
Criteria 01–15: [ ] PASS
Notes:

```
