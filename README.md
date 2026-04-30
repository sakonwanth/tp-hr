# TP-HR: Human Resource Management System

ระบบบริหารทรัพยากรบุคคลสำหรับ TP Asset Development
เชื่อมต่อกับระบบเงินเดือน TP-CRM

## 📋 คุณสมบัติหลัก

### สำหรับ HR Admin
- ✅ จัดการข้อมูลพนักงาน
- ✅ บริหารเวลาเข้า-ออกงาน
- ✅ จัดการการลาและอนุมัติ
- ✅ ออกเอกสาร/ใบรับรอง
- ✅ รายงานและสถิติ
- ✅ ตั้งค่าระบบ

### สำหรับพนักงาน (Self-Service)
- ✅ ลงเวลาเข้า-ออกงาน (GPS/Photo)
- ✅ ยื่นคำขอลา
- ✅ ดาวน์โหลดสลิปเงินเดือน
- ✅ ขอใบรับรองต่างๆ
- ✅ แก้ไขข้อมูลส่วนตัว

## 🚀 เริ่มต้นใช้งาน

### ความต้องการ
- PHP 8.1+
- MySQL 8.0+
- XAMPP / Apache
- Composer

### การติดตั้ง

1. Clone หรือ Copy โปรเจคไปยัง `htdocs/tp-hr`

2. Copy ไฟล์ Environment:
```bash
cp .env.example .env
```

3. แก้ไขการตั้งค่าใน `.env`:
```env
DB_HOST=localhost
DB_NAME=tp_crm
DB_USER=root
DB_PASS=your_password
```

4. สร้างตาราง Database:
```bash
mysql -u root -p tp_crm < database/schema.sql
mysql -u root -p tp_crm < database/seed.sql
```

5. เข้าใช้งาน:
```
http://localhost/tp-hr
```

## 📁 โครงสร้างโปรเจค

```
tp-hr/
├── api/                 # RESTful API endpoints
├── config/              # Configuration files
├── core/                # Core classes (Auth, Database, Helpers)
├── database/            # SQL schemas and migrations
├── docs/                # Documentation
├── modules/
│   ├── admin/           # HR Admin modules
│   └── employee/        # Employee self-service modules
├── public/              # Public assets (CSS, JS, Images)
├── storage/             # File storage
├── templates/           # Shared templates
├── bootstrap.php        # Application bootstrap
├── index.php            # Entry point
└── login.php            # Authentication
```

## 🔗 การเชื่อมต่อกับ TP-CRM

ระบบใช้ฐานข้อมูลเดียวกับ TP-CRM (`tp_crm`) และเชื่อมต่อกับ:
- **Users table**: ข้อมูลพนักงาน
- **Roles table**: สิทธิ์การเข้าถึง
- **Payroll module**: สลิปเงินเดือน

ข้อมูลที่ส่งไป Payroll:
- วันทำงานจริง
- วันลา
- การมาสาย
- ชั่วโมง OT

## 📖 เอกสารเพิ่มเติม

- [HR System Architecture](docs/HR_SYSTEM_ARCHITECTURE.md) - โครงสร้างระบบทั้งหมด
- [Playwright E2E](docs/E2E_PLAYWRIGHT.md) — สเปก + ตัวแปร env; ดัชนีสเปกใน [`tests/e2e/README.md`](tests/e2e/README.md)

### Deploy และ IOS26 QA

- **[DEPLOY_CHECKLIST.md](DEPLOY_CHECKLIST.md)** — เช็คก่อน/หลัง deploy (cache-bust **`native-shell.css`** · smoke ESS/HRA/login/verify · ชี้ไป **`06` / `07` / `08` / `03`**)

## 🛠️ Development

### รันในโหมด Development
```bash
# เปิด Apache & MySQL ผ่าน XAMPP
# เข้า http://localhost/tp-hr
```

### Database Migrations
```bash
# รันไฟล์ migration ใหม่
mysql -u root -p tp_crm < database/migrations/xxxx_xx_xx_migration_name.sql
```

## 📝 License

Copyright © 2026 TP Asset Development Co., Ltd.
