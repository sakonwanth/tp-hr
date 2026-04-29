# 08_FINAL_FULL_UI_AUDIT.md — TP-HR (full UI audit gate)

**Status:** 🔶 **Open for device / polish** — structural + page markup passes for **Waves 1–3** are shipped (`06`).

| Criterion | State |
|-----------|--------|
| Templated routes use IOS shell + tokens (`native-shell` v12+, **v13** typography helpers) | ✅ Foundation + Wave 2–3 pages refactored per `06` |
| Per-page spacing / radii vs tokens | ✅ Markup sweep done for scoped waves · ⏳ spot-check on devices |
| Overflow / horizontal bleed | ⏳ device matrix (**`07`**) |
| CTA vs bottom-tab / modal collision | ⏳ re-check modals & sticky actions on narrow viewports |

**Closing condition (this file):**

1. `06_IMPLEMENTATION_PROGRESS.md` reflects **COMPLETE** or **REFACTORED** for all routes your mission cares about — **done for Waves 1–3 listed there.**
2. **`07_PAGE_REGRESSION_AFTER.md`** PASS dates filled (or equivalent QA log).
3. No open **P0** layout breaks on agreed breakpoints (e.g. 375 / 768 / 1024).

When the above are satisfied, set this doc’s header **Status** to **CLOSED** and note the QA date.

---

*Last updated: 2026-04-28 — Wave 2–3 markup passes complete; pending human viewport QA.*
