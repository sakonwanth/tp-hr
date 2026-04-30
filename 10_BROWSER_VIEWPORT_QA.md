# 10_BROWSER_VIEWPORT_QA.md — TP-HR viewport & scroll QA (manual)

**Purpose:** Repeatable **device / DevTools** pass after **`native-shell.css?v=15`** (Liquid Glass tab strip, **`--tp-bottom-nav-slot`**, **`--tp-scroll-end-buffer`**, safe-area on **`main`**).

**Related**

- Spacing criteria: **`07_SPACING_QA.md`**
- Per-route visual matrices: **`08_VISUAL_QA_AFTER.md`**
- Dashboard master gate: **`03_MASTER_SCREEN_VISUAL_QA.md`**
- Completion narrative: **`09_COMPLETION_GATE.md`** · deploy: **`DEPLOY_CHECKLIST.md`**

**Shell CSS:** `assets/css/native-shell.css`

---

## Devices / widths (minimum)

| Logical width | Typical profile |
|---------------|-----------------|
| 375 | iPhone SE height class |
| 390–430 | iPhone 14 / 15 class |
| 768+ | Tablet (HRA tables → cards) |

Cover at least **375** and **390×844** for ESS; add **768** once for any **`/hr/*`** page with tables.

---

## Checks — ESS (routes with **bottom tab bar** + **`index.php`**)

Use the same run as **`08`** / **`07`** questions where possible.

1. **No horizontal scroll** — body/content does not require sideways pan to read primary UI.
2. **Bottom tabs** — four tabs readable; current route visually selected (**`aria-current="page"`** where implemented).
3. **Scroll to end** — last line / last card not hidden under the **floating glass tab pill**; **`--tp-scroll-end-buffer`** (~160px target) feels generous.
4. **`/` dashboard** — primary **ลงเวลา** sticky slab sits **above** tabs with ~**24px** perceived gap (see **`03`**).
5. **Safe area** — on notched devices or simulators, content does not collide with home indicator / notch on **`main`**.
6. **Glass hierarchy** — blur/saturation on **chrome** (tabs, header, sticky slab); content wells stay readable (see **`08`** Q1–3).

**Log:** PASS / FAIL per route + width · optional screenshot folder.

---

## Checks — HRA (**`/hr/`**, usually no bottom tabs)

On **375** and **768**:

1. **Tables vs cards** — dense tables collapse to card rows on XS without orphan borders (**`08`** Q6).
2. **`tp-ios-master-screen`** rhythm — large title block + section gaps feel consistent with ESS (**`gap-6`** · **`mb-6`** patterns).
3. Horizontal overflow — filters / action rows wrap or scroll internally, not whole page.

---

## Checks — AUTH · PUB · print

| Route | Focus |
|-------|--------|
| **`/login.php`** | Inputs **≥16px** font (no iOS zoom trap); glass card not clipped. |
| **`/verify_document.php`** | Public card + controls; no stray horizontal scroll. |
| **`certificate_print.php`** | Screen preview: toolbar + stack; **Print** uses **`@media print`** / Sarabun on paper (Wave 9). |

---

## Minimal smoke (pairs with **`DEPLOY_CHECKLIST.md`** post-deploy)

| Route | Action |
|-------|--------|
| **`/`** | Scroll full height; tap primary CTA zone (cancel if destructive). |
| **`/checkin.php`** | Open any modal / GPS UI if shown; dismiss. |
| **`/leave.php`** | One field focus + scroll form end. |
| **`/hr/index.php`** | Scroll + tap one nav card/link. |
| **`/login.php`** | Focus username field. |

---

## Viewport QA log (paste rows here or in tracker)

```markdown
| Date | Device / DevTools size | Tester | ESS `/` | ESS tab pages | HRA sample | Login | Notes |
```

Example row:

```markdown
| YYYY-MM-DD | Chrome DevTools 390×844 | — | PASS | PASS | PASS | PASS | — |
```

---

## Static checks (no browser)

From repo root:

```bash
chmod +x scripts/verify-native-shell-cache.sh
./scripts/verify-native-shell-cache.sh
```

Expect **`OK`** — every **`native-shell.css`** loader uses **`?v=15`** (override with **`NATIVE_SHELL_CACHE=…`** when you intentionally bump). The same check runs in **GitHub Actions** (`.github/workflows/ci.yml`) after **`npm ci`**.
