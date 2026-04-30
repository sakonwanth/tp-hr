# AUDIT_04_VIEWPORT.md — TP-HR

**Method:** ในนี้ไม่รันเบราว์เซอร์ — ส่วน **CSS static** อ้างจาก `assets/css/native-shell.css` (v15 · cache **`?v=15`**) ร่วมกับ **รายการ manual** ใน **`10_BROWSER_VIEWPORT_QA.md`**.

**Owning docs:** rollout **`06_IMPLEMENTATION_PROGRESS.md`** · spacing **`07_SPACING_QA.md`** · per-route visual **`08_VISUAL_QA_AFTER.md`** · เมตริกซ์หน้าหลัก **`03_MASTER_SCREEN_VISUAL_QA.md`**.

---

## 1. Recommended width / height matrix (manual)

เทียบ **`10_BROWSER_VIEWPORT_QA.md`** — ขั้นต่ำ **375** · **390×844** (ESS) และ **768** (HRA ตาราง→การ์ด).

ขยายเต็มบรีฟ (ถ้าต้องการความครอบคลุมเดียวกับ check-in): **320, 360, 375, 390, 414, 430, 768, 820, 1024** × **667, 736, 812, 844, 896, 932** — บันทึกผลใน **`08`** / log ของ **`10`** เมื่อทดแล้ว.

| Status |
|--------|
| **Human viewport QA:** **PENDING** — รัน **`10_BROWSER_VIEWPORT_QA.md`** + เติมเมทริกซ์ใน **`08`** / **`03`** (`/`) เมื่อทดบนอุปกรณ์จริงหรือ DevTools |

---

## 2. Static CSS mitigations (evidence in `native-shell.css`)

| Risk | Mitigation |
|------|------------|
| Horizontal scroll (page) | **`html`**, **`body`** — `overflow-x: clip` (ช่วงหัวไฟล์) |
| Scroll-end vs bottom tab | **`main.tp-native-page`** — `padding-bottom` ใช้ **`max`** รวม **`--tp-scroll-end-buffer`** (**160px**) กับ **`--tp-bottom-nav-slot`** (+ ช่อง CTA หน้า home) |
| Slot + token | **`--tp-bottom-nav-slot`**, **`--tp-bottom-nav-max-h`**, **`--tp-scroll-end-buffer`** ที่ **`:root`** |
| Home CTA vs tabs | **`body.tp-with-tab-nav main.tp-native-page--home`** — สูตร `padding-bottom` รวม **`--tp-native-btn-min`** + gap + buffer |

*หมายเหตุ:* **`.tp-native-table-shell`** ใช้ **`overflow-x: auto`** เพื่อเลื่อนตารางในโซน (HRA) — manual ต้องยืนยันว่าหน้าทั้งหน้าไม่ลากแนวนอน (**`10`** ข้อ 1 / **`08`** Q4).

---

## 3. Checklist mapping (manual)

ใช้ **`10_BROWSER_VIEWPORT_QA.md`** (ESS / HRA / AUTH-PUB-print) พร้อม **`07`** criteria **9–12** (tab · scroll-end · อ่านบรรทัดสุดท้าย).

| # | Check | Where |
|---|-------|--------|
| 1 | ไม่เลื่อนแนวนอนทั้งจอ | ESS + HRA filters |
| 2 | เลื่อนสุดแล้วไม่ถูกแท็บทับ | ทุกหน้า ESS มีแท็บล่าง |
| 3 | Sticky CTA / slab เหนือแท็บ | **`/`** — **`03`** |
| 4 | Modal / print preview ไม่โดนตัด | check-in modals · **`certificate_print`** |
| 5 | คีย์บอร์ดไม่บัง input | `login` · ฟอร์มลา |

---

## 4. Result

| Gate | Verdict |
|------|---------|
| **Automated full viewport matrix** | **Not in repo** — ต้องทำ manual / Playwright ภายนอกชุดที่มีอยู่ |
| **CSS overflow / padding safeguards** | **PASS (static)** — ตามตารางข้อ 2 |
| **Human viewport + visual/spacing gates** | **PENDING** — **`07`** · **`08`** · **`10`** · **`03`** |

เมื่อ manual ผ่าน ให้ระบุวันที่ในแถว **`08`** / log **`10`** และ (ถ้าต้องการ) อัปเดตแถว **§1** ด้านบนเป็น **PASS**.
