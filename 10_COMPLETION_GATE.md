# 10_COMPLETION_GATE.md — Project completion criteria (TP-HR IOS26 UX)

Production UX sign-off (`tp-hr` skin) requires **simultaneous** satisfaction:

1. ✅ `03_MASTER_SCREEN_VISUAL_QA.md` passes on **`index.php`**.  
2. ✅ Every inventory route discovered (**`04` → `01_FULL_UI_INVENTORY`**).  
3. ✅ Every route mapped (**`05_PAGE_TO_IOS26_PATTERN_MAP.md`** kept current).  
4. ✅ **`06_IMPLEMENTATION_PROGRESS.md`** — all pages **COMPLETE** (`REFACTORED` uplift + regression OK).  
5. ✅ **`07_SPACING_QA.md`** PASS per route.  
6. ✅ **`08_VISUAL_QA_AFTER.md`** PASS per route cluster.  
7. ✅ PHPUnit / API regressions GREEN (baseline unchanged intentionally).  
8. Skipped pages **= 0**.  
9. Bottom nav overlaps **= 0**.  
10. CTA/tab collisions **= 0**.  
11. Overflow-x violations **= 0**.  
12. Rogue one-off spacing outside tokens trending **≈ 0** (engineering discretion).  
13. **No** screen still resembling legacy flat-dashboard-only stack without native tokens.

Failures roll back localized CSS before merge.
