# UI cascade bugfix — ค.ศ. 2026 (TP-HR)

## อาการ

- เนื้อหาเฟลชขอบซ้าย/ขวา หรือเลื่อนสุดแล้วทับแถบเมนู  
- พฤติกรรมต่างจากก่อน migration

## สาเหตุ (ราก)

ลำดับ CSS ใน `templates/header.php` เป็น:

1. `link` → `native-shell.css` กำหนด `main.content-area.tp-native-page { padding-left/right/bottom … }`
2. `<style>` ในไฟล์เดียวกัน **อยู่ท้ายกว่าไฟล์ลิงก์**
3. Block เดิม:

```css
body.tp-native-app .content-area.tp-native-page {
    padding-left: 0;
    padding-right: 0;
    padding-bottom: 0;
}
```

ทับ padding จาก `native-shell.css` → **ความหนาเลย์เอาต์ของ main เป็นศูนย์** ทั้งซ้าย/ขวา/ล่าง (ยกเว้นถ้าโดน override เฉพาะคุณสมบัติ)

## การแก้

1. ลบ block ที่รีเซ็ต padding เป็นศูนย์ข้างต้น  
2. ใต้ `max-width: 1279px` ให้กำหนดเฉพาะ **`padding-top`** บน `main.content-area.tp-native-page` เพื่อเว้นที่ใต้แถบมือถือ  
3. ขอบซ้าย/ขวา/ล่าง + scroll buffer ให้ **`native-shell.css` เป็นของแต่เพียงผู้เดียว**  
4. `native-shell.css` v4: ใช้ `max(var(--tp-page-pad-*), env(safe-area-inset-*))` สำหรับขอบข้าง  
5. ลบ `max-h-[72px]` ออกจาก `<nav>` แท็บล่าง — กันตัดป้าย/ไอคอน  
6. `.tp-native-stack--page` — ไม่ใช้ `flex` + `gap` แล้ว (เหลือ `min-width: 0`) เพื่อไม่ให้ซ้ำ spacing ของแต่ละหน้า

## ไฟล์ที่แตะ

- `templates/header.php`
- `templates/footer.php`
- `assets/css/native-shell.css`
- `login.php` (คิวรีสตริง `?v=4`)
