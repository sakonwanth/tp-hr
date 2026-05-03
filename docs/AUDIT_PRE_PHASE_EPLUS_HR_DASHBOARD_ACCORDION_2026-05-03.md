# Pre-phase audit — HR dashboard secondary blocks (mobile accordion) — 2026-05-03

## 1. ขอบเขตงาน (ก่อนลงมือแก้โค้ด)

| รายการ | ค่า |
|--------|-----|
| **เป้าหมาย** | ลดความยาว scroll บนหน้าจอแคบ โดยพับ **บล็อกรอง** บนแดชบอร์ด HR คล้าย Wave C ของ tp-crm (รายการใน `UI_UX_STANDARD` §0.3 / `DEVELOPMENT_ROADMAP` Phase E+) |
| **ไฟล์ที่คาดว่าแตะ** | `hr/index.php`, `assets/css/native-shell.css`, `templates/header.php` (bump `?v=` cache), `login.php`, `verify_document.php`, `certificate_print.php` (cache-bust สัมพันธ์) |
| **ไม่แตะ** | พฤติกรรม approve/reject ลา, โมดัล, API, สิทธิ์ |

## 2. Preflight

- **คำสั่ง:** `php scripts/production_preflight.php --strict`  
- **ผล (ตัวอย่าง dev):** `1 failure(s), 5 warning(s), 67 ok` — เหมือนรอบก่อน (schema `offsite_*`)  
- **ข้อสรุป:** ไม่บล็อกงาน UI รอบนี้

## 3. Baseline (ก่อนเปลี่ยน)

| บล็อก | บทบาท | พฤติกรรมบนมือถือ |
|--------|--------|-------------------|
| Quick stats (5 การ์ด) | **Primary** | แสดงต่อเนื่อง — **คงไว้** |
| Grid 2 คอลัมน์: คำขอลารออนุมัติ + ลาวันนี้ | **Secondary** | ยาว — **พับใต้ `<details>` เมื่อ &lt; xl** |
| ทางลัด HR | **Primary** | **คงไว้** |
| คำขอเอกสาร (ถ้ามี) | **Secondary** | **พับใต้ `<details>` เมื่อ &lt; xl** |

**Breakpoint:** ใช้ **`xl` (1280px)** ให้สอดคล้องกับ `grid-cols-1 xl:grid-cols-2` เดิมของหน้า

## 4. แผนการทำ (สรุป)

1. ห่อบล็อกรองด้วย `<details class="hr-dashboard-secondary-accordion min-w-0 xl:contents group">`  
2. `<summary>` แสดงเฉพาะ `max-xl` (`xl:hidden`) — หัวข้อสั้น + ไอคอน chevron  
3. บน **`min-width: 1280px`** ตั้ง `details.open = true` ด้วยสคริปต์เล็ก (และที่ resize) เพื่อให้เลย์เอาต์ desktop เทียบเท่าเดิม  
4. สไตล์ chevron / ซ่อน marker รายการใน `native-shell.css` — เวอร์ชัน **v16**

## 5. ความเสี่ยง / rollback

- **ความเสี่ยง:** ผู้ใช้มือถือต้องแตะเปิดบล็อกรอง — สอดคล้องกับ CRM dashboard accordion  
- **Rollback:** revert commit เดียวบน `hr/index.php` + CSS + bump query

## 6. Post-change verification (manual)

- [ ] &lt; 1280px: บล็อกรองพับได้, summary แตะขยาย  
- [ ] ≥ 1280px: เลย์เอาต์ 2 คอลัมน์และคิวเอกสารเหมือนเดิม  
- [ ] อนุมัติ/ไม่อนุมัติลาจากรายการยังทำงาน  
