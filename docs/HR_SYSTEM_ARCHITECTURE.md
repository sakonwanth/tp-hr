# TP-HR System Architecture
## ระบบบริหารทรัพยากรบุคคล (Human Resource Management System)

**วันที่ออกแบบ:** 6 เมษายน 2026  
**เวอร์ชัน:** 1.0  
**ผู้ออกแบบ:** TP Development Team

---

## 📋 สารบัญ
1. [ภาพรวมระบบ](#1-ภาพรวมระบบ)
2. [โครงสร้างโมดูล HR Admin](#2-โครงสร้างโมดูล-hr-admin)
3. [โครงสร้างโมดูล Employee Self-Service](#3-โครงสร้างโมดูล-employee-self-service)
4. [การเชื่อมต่อกับ TP-CRM](#4-การเชื่อมต่อกับ-tp-crm)
5. [โครงสร้าง Database](#5-โครงสร้าง-database)
6. [โครงสร้างไฟล์](#6-โครงสร้างไฟล์)
7. [แผนการดำเนินงาน](#7-แผนการดำเนินงาน)

---

## 1. ภาพรวมระบบ

### 1.1 วัตถุประสงค์
ระบบ TP-HR ถูกออกแบบมาเพื่อ:
- จัดการข้อมูลพนักงานอย่างครบถ้วน
- อำนวยความสะดวกในกระบวนการ HR
- ให้พนักงานบริการตัวเองได้ (Self-Service)
- เชื่อมต่อกับระบบเงินเดือนจาก TP-CRM

### 1.2 ผู้ใช้งานหลัก

| ประเภทผู้ใช้ | สิทธิ์การเข้าถึง |
|-------------|----------------|
| **HR Admin** | จัดการข้อมูลพนักงานทั้งหมด, อนุมัติคำขอ, ออกเอกสาร |
| **HR Manager** | ดูรายงาน, อนุมัติคำขอ, กำหนดนโยบาย |
| **Department Manager** | อนุมัติการลาของทีม, ดูรายงานทีม |
| **Employee** | จัดการข้อมูลตัวเอง, ขอลา, ดาวน์โหลดเอกสาร |

### 1.3 Technology Stack
- **Backend:** PHP 8.2+ (เหมือน TP-CRM)
- **Database:** MySQL 8.0
- **Frontend:** HTML5, Tailwind CSS, Alpine.js
- **API:** RESTful API สำหรับเชื่อมต่อ TP-CRM
- **Authentication:** Shared session กับ TP-CRM

---

## 2. โครงสร้างโมดูล HR Admin

### 2.1 📦 Employee Management (บริหารจัดการพนักงาน)
```
├── พนักงานทั้งหมด (Employee List)
│   ├── เพิ่มพนักงานใหม่
│   ├── แก้ไขข้อมูลพนักงาน
│   ├── ดูประวัติพนักงาน
│   └── ปิดการใช้งาน/ลาออก
│
├── ข้อมูลส่วนตัว (Personal Information)
│   ├── ข้อมูลทั่วไป (ชื่อ, ที่อยู่, เบอร์โทร)
│   ├── ข้อมูลครอบครัว
│   ├── ผู้ติดต่อฉุกเฉิน
│   └── ข้อมูลบัญชีธนาคาร
│
├── ข้อมูลการจ้างงาน (Employment Details)
│   ├── ตำแหน่งงาน/แผนก
│   ├── ประเภทการจ้าง (ประจำ/สัญญาจ้าง/Part-time)
│   ├── วันที่เริ่มงาน
│   ├── หัวหน้างาน
│   └── ประวัติการเลื่อนตำแหน่ง
│
└── เอกสารพนักงาน (Employee Documents)
    ├── สำเนาบัตรประชาชน
    ├── สำเนาทะเบียนบ้าน
    ├── วุฒิการศึกษา
    ├── ใบรับรองการทำงาน
    └── สัญญาจ้าง
```

### 2.2 ⏰ Time & Attendance Management (บริหารเวลาเข้า-ออกงาน)
```
├── การตั้งค่ากะทำงาน (Work Shift Settings)
│   ├── กำหนดเวลาเข้า-ออก
│   ├── กำหนดเวลาพัก
│   ├── กะกลางวัน/กะกลางคืน
│   └── การทำงานล่วงเวลา (OT)
│
├── ตารางการทำงาน (Work Schedule)
│   ├── กำหนดตารางทำงานรายสัปดาห์
│   ├── ตารางวันหยุดประจำปี
│   └── ตารางหมุนเวียนกะ
│
├── รายงานการลงเวลา (Attendance Reports)
│   ├── รายงานรายวัน
│   ├── รายงานรายเดือน
│   ├── รายงานการมาสาย
│   ├── รายงานการขาดงาน
│   └── รายงาน OT
│
├── จุดลงเวลา (Check-in Points)
│   ├── ตั้งค่า GPS Location
│   ├── ตั้งค่า WiFi (SSID/MAC)
│   ├── QR Code สถานที่
│   └── เครื่อง Fingerprint/Face ID
│
└── การแก้ไขเวลา (Time Adjustment)
    ├── อนุมัติการแก้ไขเวลา
    ├── ประวัติการแก้ไข
    └── รายงานความผิดปกติ
```

### 2.3 🏖️ Leave Management (บริหารการลา)
```
├── ประเภทการลา (Leave Types)
│   ├── ลาป่วย (Sick Leave)
│   ├── ลากิจ (Personal Leave)
│   ├── ลาพักร้อน (Annual Leave)
│   ├── ลาคลอด (Maternity Leave)
│   ├── ลาบวช (Ordination Leave)
│   ├── ลาแต่งงาน (Marriage Leave)
│   ├── ลากรณีบุคคลในครอบครัวเสียชีวิต (Bereavement Leave)
│   └── ลาไม่รับค่าจ้าง (Leave Without Pay)
│
├── สิทธิ์การลา (Leave Entitlement)
│   ├── กำหนดโควต้าตามอายุงาน
│   ├── กำหนดตามประเภทพนักงาน
│   ├── การยกยอดวันลา
│   └── การคำนวณวันลาอัตโนมัติ
│
├── อนุมัติการลา (Leave Approval)
│   ├── Workflow อนุมัติหลายระดับ
│   ├── ผู้อนุมัติแทน
│   ├── แจ้งเตือนการอนุมัติ
│   └── ประวัติการอนุมัติ
│
└── รายงานการลา (Leave Reports)
    ├── สรุปวันลาคงเหลือ
    ├── รายงานการลาตามแผนก
    ├── แนวโน้มการลา
    └── รายงานการลาขาดมากผิดปกติ
```

### 2.4 💰 Payroll Integration (เชื่อมต่อระบบเงินเดือน TP-CRM)
```
├── ข้อมูลเงินเดือน (Salary Data)
│   ├── เงินเดือนพื้นฐาน (Base Salary) → ดึงจาก TP-CRM
│   ├── ค่าตำแหน่ง (Position Allowance)
│   ├── ค่าครองชีพ (Cost of Living)
│   └── เบี้ยเลี้ยง (Allowances)
│
├── รายการหักเงิน (Deductions)
│   ├── ประกันสังคม
│   ├── ภาษีหัก ณ ที่จ่าย
│   ├── กองทุนสำรองเลี้ยงชีพ
│   ├── เงินกู้/หนี้สิน
│   └── อื่นๆ
│
├── การคำนวณอัตโนมัติ (Auto Calculation)
│   ├── คำนวณจากวันทำงานจริง
│   ├── คำนวณวันลา
│   ├── คำนวณ OT
│   ├── คำนวณหักมาสาย
│   └── ส่งข้อมูลไป TP-CRM Payroll
│
└── รายงาน (Reports)
    ├── รายงานต้นทุนบุคลากร
    ├── รายงานเปรียบเทียบเงินเดือน
    └── รายงานภาษี
```

### 2.5 📄 Document Management (บริหารเอกสาร)
```
├── เทมเพลตเอกสาร (Document Templates)
│   ├── หนังสือรับรองการทำงาน
│   ├── หนังสือรับรองเงินเดือน
│   ├── สัญญาจ้างงาน
│   ├── ใบลาออก
│   └── เอกสารอื่นๆ
│
├── การออกเอกสาร (Document Issuance)
│   ├── ออกเอกสารอัตโนมัติ
│   ├── ลายเซ็นดิจิทัล
│   ├── QR Code ยืนยันความถูกต้อง
│   └── ประวัติการออกเอกสาร
│
└── การจัดเก็บเอกสาร (Document Storage)
    ├── จัดเก็บในระบบ
    ├── การเข้ารหัสเอกสาร
    └── นโยบายการเก็บรักษา
```

### 2.6 📊 Reports & Analytics (รายงานและวิเคราะห์)
```
├── Dashboard ภาพรวม
│   ├── จำนวนพนักงานทั้งหมด
│   ├── อัตราการลาออก (Turnover Rate)
│   ├── สถิติการลงเวลา
│   └── ค่าใช้จ่ายบุคลากร
│
├── รายงานบุคลากร
│   ├── รายงานโครงสร้างองค์กร
│   ├── รายงานตามแผนก/ตำแหน่ง
│   ├── รายงานอายุงานเฉลี่ย
│   └── รายงานความหลากหลาย
│
└── รายงานประจำงวด
    ├── รายงานรายวัน
    ├── รายงานรายสัปดาห์
    ├── รายงานรายเดือน
    └── รายงานรายปี
```

### 2.7 ⚙️ System Settings (ตั้งค่าระบบ)
```
├── โครงสร้างองค์กร (Organization Structure)
│   ├── จัดการแผนก/ฝ่าย
│   ├── จัดการตำแหน่งงาน
│   ├── ลำดับขั้นการอนุมัติ
│   └── Cost Center
│
├── นโยบายบริษัท (Company Policies)
│   ├── นโยบายการลา
│   ├── นโยบายการทำงาน
│   ├── กฎระเบียบบริษัท
│   └── วันหยุดประจำปี
│
├── การแจ้งเตือน (Notifications)
│   ├── Email Templates
│   ├── LINE Notify
│   ├── Push Notification
│   └── SMS (ถ้าต้องการ)
│
└── การจัดการผู้ใช้ (User Management)
    ├── สิทธิ์การเข้าถึง
    ├── กลุ่มผู้ใช้
    └── Audit Log
```

---

## 3. โครงสร้างโมดูล Employee Self-Service

### 3.1 🏠 Employee Dashboard (หน้าหลักพนักงาน)
```
├── ภาพรวมส่วนตัว
│   ├── ข้อมูลพนักงาน
│   ├── วันลาคงเหลือ
│   ├── การลงเวลาวันนี้
│   └── ประกาศ/ข่าวสาร
│
├── ทางลัดด่วน
│   ├── ลงเวลาเข้า-ออก
│   ├── ขอลา
│   ├── ดูสลิปเงินเดือน
│   └── ขอเอกสาร
│
└── ปฏิทินส่วนตัว
    ├── วันลา
    ├── วันหยุด
    └── กิจกรรมบริษัท
```

### 3.2 ⏰ Check-in/Check-out (ลงเวลาเข้า-ออกงาน)
```
├── วิธีการลงเวลา
│   ├── 📍 GPS Location Check-in
│   │   └── ตรวจสอบพิกัดที่ตั้ง
│   ├── 📷 Face Recognition (ถ้ามี)
│   │   └── ยืนยันตัวตนด้วยใบหน้า
│   ├── 📱 QR Code Scan
│   │   └── สแกน QR ที่สำนักงาน
│   └── 🔐 WiFi Verification
│       └── ตรวจสอบเชื่อมต่อ WiFi บริษัท
│
├── Selfie Check-in
│   ├── ถ่ายรูปยืนยันตัวตน
│   ├── บันทึกพิกัด GPS
│   └── บันทึกเวลาอัตโนมัติ
│
├── ประวัติการลงเวลา
│   ├── ดูประวัติรายวัน/รายเดือน
│   ├── สถานะ (ปกติ/สาย/ขาด)
│   └── ชั่วโมงทำงานรวม
│
└── ขอแก้ไขเวลา
    ├── ยื่นคำขอแก้ไข
    ├── แนบหลักฐาน
    └── ติดตามสถานะ
```

### 3.3 🏖️ Leave Request (ขอลา)
```
├── ยื่นคำขอลา
│   ├── เลือกประเภทการลา
│   ├── เลือกวันที่ลา
│   ├── ระบุเหตุผล
│   └── แนบเอกสาร (ใบรับรองแพทย์)
│
├── สิทธิ์การลา
│   ├── วันลาคงเหลือแต่ละประเภท
│   ├── ประวัติการใช้สิทธิ์
│   └── วันลาที่จะหมดอายุ
│
├── ติดตามคำขอ
│   ├── สถานะคำขอ (รออนุมัติ/อนุมัติ/ไม่อนุมัติ)
│   ├── ผู้อนุมัติ
│   └── หมายเหตุ
│
└── ยกเลิกคำขอ
    ├── ยกเลิกก่อนอนุมัติ
    └── ขอยกเลิกหลังอนุมัติ
```

### 3.4 💵 Payslip Download (ดาวน์โหลดสลิปเงินเดือน)
```
├── สลิปเงินเดือน
│   ├── ดูสลิปเดือนปัจจุบัน
│   ├── ดาวน์โหลด PDF
│   ├── ประวัติย้อนหลัง 12 เดือน
│   └── ค้นหาตามช่วงเวลา
│
├── รายละเอียดสลิป
│   ├── รายได้ทั้งหมด
│   ├── รายการหักทั้งหมด
│   ├── เงินได้สุทธิ
│   └── ภาษีสะสม (YTD)
│
└── ใบรับรองภาษี
    ├── หนังสือรับรองหักภาษี ณ ที่จ่าย (50 ทวิ)
    ├── ดาวน์โหลด PDF
    └── ส่งทาง Email
```

### 3.5 📜 Certificate Request (ขอใบรับรอง)
```
├── ประเภทใบรับรอง
│   ├── หนังสือรับรองการทำงาน (ภาษาไทย)
│   ├── หนังสือรับรองการทำงาน (ภาษาอังกฤษ)
│   ├── หนังสือรับรองเงินเดือน
│   ├── หนังสือรับรองเงินเดือน (สำหรับธนาคาร)
│   └── หนังสือรับรองอื่นๆ
│
├── ยื่นคำขอ
│   ├── เลือกประเภทเอกสาร
│   ├── ระบุวัตถุประสงค์
│   ├── ระบุจำนวน
│   └── เลือกวันรับ
│
├── ติดตามคำขอ
│   ├── สถานะการดำเนินการ
│   ├── วันที่จะได้รับ
│   └── ประวัติการขอ
│
└── ดาวน์โหลดเอกสาร
    ├── ดาวน์โหลด PDF พร้อมลายเซ็นดิจิทัล
    ├── QR Code ยืนยันความถูกต้อง
    └── ส่งทาง Email
```

### 3.6 👤 My Profile (ข้อมูลส่วนตัว)
```
├── ข้อมูลทั่วไป
│   ├── ดู/แก้ไขข้อมูลส่วนตัว
│   ├── รูปโปรไฟล์
│   ├── ข้อมูลติดต่อ
│   └── ที่อยู่
│
├── ข้อมูลการทำงาน (View Only)
│   ├── ตำแหน่งงาน
│   ├── แผนก
│   ├── วันที่เริ่มงาน
│   └── หัวหน้างาน
│
├── ข้อมูลเพิ่มเติม
│   ├── ข้อมูลครอบครัว
│   ├── ผู้ติดต่อฉุกเฉิน
│   ├── ข้อมูลบัญชีธนาคาร
│   └── ข้อมูลภาษี
│
└── เอกสารของฉัน
    ├── เอกสารที่อัปโหลด
    ├── สัญญาจ้างงาน
    └── เอกสารที่ออกให้
```

### 3.7 📢 Announcements & News (ประกาศและข่าวสาร)
```
├── ประกาศบริษัท
│   ├── ประกาศทั่วไป
│   ├── นโยบายใหม่
│   └── ประกาศด่วน
│
├── ข่าวสารภายใน
│   ├── กิจกรรมบริษัท
│   ├── การฝึกอบรม
│   └── สวัสดิการใหม่
│
└── ปฏิทินกิจกรรม
    ├── วันหยุดประจำปี
    ├── กิจกรรมบริษัท
    └── การฝึกอบรม
```

---

## 4. การเชื่อมต่อกับ TP-CRM

### 4.1 ข้อมูลที่แชร์ร่วมกัน

```
┌─────────────────┐              ┌─────────────────┐
│     TP-HR       │◄────────────►│    TP-CRM       │
│                 │              │                 │
│  • Time & Att   │   API/DB     │  • Payroll      │
│  • Leave Mgmt   │◄────────────►│  • Salary Data  │
│  • Employee     │   Shared     │  • Payslip      │
│    Self-Service │   Tables     │  • Users        │
└─────────────────┘              └─────────────────┘
```

### 4.2 ตารางที่แชร์กับ TP-CRM

| ตาราง | แหล่งข้อมูล | การใช้งาน |
|------|-----------|---------|
| `users` | TP-CRM | ข้อมูลพนักงาน, Authentication |
| `roles` | TP-CRM | สิทธิ์การเข้าถึง |
| `payroll_runs` | TP-CRM | รอบการจ่ายเงินเดือน |
| `payroll_slips` | TP-CRM | สลิปเงินเดือน |
| `employee_salaries` | TP-CRM | ฐานเงินเดือน |

### 4.3 ข้อมูลที่ส่งไป TP-CRM Payroll

```php
// ข้อมูลที่ส่งไปคำนวณเงินเดือน
$payroll_data = [
    'employee_id' => $user_id,
    'month' => '2026-04',
    'working_days' => 22,        // จากระบบ Time & Attendance
    'absent_days' => 0,          // จากระบบ Leave
    'late_count' => 2,           // จากระบบ Time & Attendance
    'late_minutes' => 45,        // จากระบบ Time & Attendance
    'ot_hours' => 10.5,          // จากระบบ Time & Attendance
    'leave_without_pay' => 0,    // จากระบบ Leave
];
```

---

## 5. โครงสร้าง Database

### 5.1 ตาราง HR เพิ่มเติม

```sql
-- =============================================
-- TP-HR Database Schema
-- =============================================

-- ตารางแผนก
CREATE TABLE hr_departments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    parent_id INT NULL,
    manager_id INT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES hr_departments(id),
    FOREIGN KEY (manager_id) REFERENCES users(id)
);

-- ตารางตำแหน่งงาน
CREATE TABLE hr_positions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    title VARCHAR(100) NOT NULL,
    department_id INT,
    level INT DEFAULT 1 COMMENT '1=Staff, 2=Senior, 3=Lead, 4=Manager, 5=Director',
    min_salary DECIMAL(12,2),
    max_salary DECIMAL(12,2),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES hr_departments(id)
);

-- ตารางกะทำงาน
CREATE TABLE hr_work_shifts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) UNIQUE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    break_start TIME,
    break_end TIME,
    break_minutes INT DEFAULT 60,
    work_hours_per_day DECIMAL(4,2) DEFAULT 8.00,
    is_overnight BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ตารางจุดลงเวลา
CREATE TABLE hr_checkin_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    address TEXT,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    radius_meters INT DEFAULT 100 COMMENT 'รัศมีที่อนุญาต',
    wifi_ssid VARCHAR(100) COMMENT 'ชื่อ WiFi',
    wifi_bssid VARCHAR(50) COMMENT 'MAC Address WiFi',
    qr_code VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ตารางลงเวลาเข้า-ออก
CREATE TABLE hr_attendances (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    shift_id INT,
    
    -- เวลาเข้า
    check_in_time DATETIME,
    check_in_type ENUM('GPS','WIFI','QR','FACE','MANUAL') DEFAULT 'GPS',
    check_in_latitude DECIMAL(10,8),
    check_in_longitude DECIMAL(11,8),
    check_in_location_id INT,
    check_in_photo VARCHAR(255),
    check_in_device_info TEXT,
    
    -- เวลาออก
    check_out_time DATETIME,
    check_out_type ENUM('GPS','WIFI','QR','FACE','MANUAL'),
    check_out_latitude DECIMAL(10,8),
    check_out_longitude DECIMAL(11,8),
    check_out_location_id INT,
    check_out_photo VARCHAR(255),
    check_out_device_info TEXT,
    
    -- สรุป
    work_minutes INT GENERATED ALWAYS AS (TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time)) STORED,
    late_minutes INT DEFAULT 0,
    early_leave_minutes INT DEFAULT 0,
    ot_minutes INT DEFAULT 0,
    status ENUM('PRESENT','LATE','ABSENT','LEAVE','HOLIDAY','HALF_DAY') DEFAULT 'PRESENT',
    
    remarks TEXT,
    approved_by INT,
    approved_at DATETIME,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (shift_id) REFERENCES hr_work_shifts(id),
    FOREIGN KEY (check_in_location_id) REFERENCES hr_checkin_locations(id),
    FOREIGN KEY (check_out_location_id) REFERENCES hr_checkin_locations(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    
    UNIQUE KEY uk_user_date (user_id, attendance_date),
    INDEX idx_attendance_date (attendance_date),
    INDEX idx_status (status)
);

-- ตารางประเภทการลา
CREATE TABLE hr_leave_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    name_en VARCHAR(100),
    description TEXT,
    default_days_per_year DECIMAL(5,2) DEFAULT 0,
    is_paid BOOLEAN DEFAULT TRUE,
    requires_document BOOLEAN DEFAULT FALSE,
    min_days_advance INT DEFAULT 0 COMMENT 'ต้องขอล่วงหน้ากี่วัน',
    max_consecutive_days INT COMMENT 'ลาติดต่อกันได้สูงสุดกี่วัน',
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ตารางสิทธิ์การลาของพนักงาน
CREATE TABLE hr_leave_entitlements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    year INT NOT NULL,
    entitled_days DECIMAL(5,2) NOT NULL DEFAULT 0,
    carried_over_days DECIMAL(5,2) DEFAULT 0,
    used_days DECIMAL(5,2) DEFAULT 0,
    pending_days DECIMAL(5,2) DEFAULT 0,
    remaining_days DECIMAL(5,2) GENERATED ALWAYS AS (entitled_days + carried_over_days - used_days - pending_days) STORED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (leave_type_id) REFERENCES hr_leave_types(id),
    UNIQUE KEY uk_user_type_year (user_id, leave_type_id, year)
);

-- ตารางคำขอลา
CREATE TABLE hr_leave_requests (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    start_half ENUM('FULL','MORNING','AFTERNOON') DEFAULT 'FULL',
    end_half ENUM('FULL','MORNING','AFTERNOON') DEFAULT 'FULL',
    total_days DECIMAL(5,2) NOT NULL,
    reason TEXT,
    document_path VARCHAR(255),
    
    status ENUM('DRAFT','PENDING','APPROVED','REJECTED','CANCELLED') DEFAULT 'PENDING',
    
    -- Level 1: หัวหน้างาน
    approver_1_id INT,
    approver_1_status ENUM('PENDING','APPROVED','REJECTED'),
    approver_1_date DATETIME,
    approver_1_remarks TEXT,
    
    -- Level 2: HR (ถ้าจำเป็น)
    approver_2_id INT,
    approver_2_status ENUM('PENDING','APPROVED','REJECTED'),
    approver_2_date DATETIME,
    approver_2_remarks TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    cancelled_at DATETIME,
    cancelled_by INT,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (leave_type_id) REFERENCES hr_leave_types(id),
    FOREIGN KEY (approver_1_id) REFERENCES users(id),
    FOREIGN KEY (approver_2_id) REFERENCES users(id),
    FOREIGN KEY (cancelled_by) REFERENCES users(id),
    
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date)
);

-- ตารางเทมเพลตเอกสาร
CREATE TABLE hr_document_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    name_en VARCHAR(100),
    template_content LONGTEXT COMMENT 'HTML template',
    template_type ENUM('CERTIFICATE','CONTRACT','LETTER','OTHER') DEFAULT 'CERTIFICATE',
    variables JSON COMMENT 'ตัวแปรที่ใช้ในเอกสาร',
    requires_approval BOOLEAN DEFAULT FALSE,
    auto_generate BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ตารางคำขอเอกสาร
CREATE TABLE hr_document_requests (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    template_id INT NOT NULL,
    purpose TEXT COMMENT 'วัตถุประสงค์',
    copies INT DEFAULT 1,
    language ENUM('TH','EN','BOTH') DEFAULT 'TH',
    
    status ENUM('PENDING','PROCESSING','READY','DELIVERED','CANCELLED') DEFAULT 'PENDING',
    
    processed_by INT,
    processed_at DATETIME,
    document_path VARCHAR(255) COMMENT 'ไฟล์ PDF ที่สร้าง',
    document_number VARCHAR(50) COMMENT 'เลขที่เอกสาร',
    qr_verification_code VARCHAR(100),
    
    pickup_date DATE,
    delivered_at DATETIME,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (template_id) REFERENCES hr_document_templates(id),
    FOREIGN KEY (processed_by) REFERENCES users(id),
    
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
);

-- ตารางวันหยุดประจำปี
CREATE TABLE hr_holidays (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date DATE NOT NULL,
    name VARCHAR(100) NOT NULL,
    name_en VARCHAR(100),
    type ENUM('PUBLIC','COMPANY','SPECIAL') DEFAULT 'PUBLIC',
    year INT GENERATED ALWAYS AS (YEAR(date)) STORED,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_date (date),
    INDEX idx_year (year)
);

-- ตารางประกาศ
CREATE TABLE hr_announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    content LONGTEXT,
    type ENUM('GENERAL','POLICY','URGENT','EVENT') DEFAULT 'GENERAL',
    target_departments JSON COMMENT 'แผนกที่เห็น, null = ทุกแผนก',
    publish_date DATETIME,
    expire_date DATETIME,
    is_pinned BOOLEAN DEFAULT FALSE,
    attachment_path VARCHAR(255),
    created_by INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_publish_date (publish_date),
    INDEX idx_is_active (is_active)
);

-- ตาราง Audit Log
CREATE TABLE hr_audit_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    table_name VARCHAR(100),
    record_id BIGINT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
);
```

---

## 6. โครงสร้างไฟล์

```
tp-hr/
├── 📁 api/                          # RESTful API
│   ├── attendances.php              # API ลงเวลา
│   ├── leaves.php                   # API การลา
│   ├── documents.php                # API เอกสาร
│   └── employees.php                # API พนักงาน
│
├── 📁 config/
│   ├── app.php                      # ค่าคงที่แอปพลิเคชัน
│   ├── database.php                 # การเชื่อมต่อ DB (ใช้ร่วมกับ TP-CRM)
│   └── auth.php                     # การตั้งค่า Authentication
│
├── 📁 core/
│   ├── Auth.php                     # Authentication class
│   ├── Database.php                 # Database connection
│   ├── Session.php                  # Session management
│   └── Helpers.php                  # Helper functions
│
├── 📁 modules/
│   ├── 📁 admin/                    # HR Admin Modules
│   │   ├── 📁 employees/            # จัดการพนักงาน
│   │   ├── 📁 attendance/           # จัดการเวลาทำงาน
│   │   ├── 📁 leaves/               # จัดการการลา
│   │   ├── 📁 documents/            # จัดการเอกสาร
│   │   ├── 📁 reports/              # รายงาน
│   │   └── 📁 settings/             # ตั้งค่าระบบ
│   │
│   └── 📁 employee/                 # Employee Self-Service
│       ├── 📁 checkin/              # ลงเวลาเข้า-ออก
│       ├── 📁 leaves/               # ขอลา
│       ├── 📁 payslips/             # สลิปเงินเดือน
│       ├── 📁 documents/            # ขอเอกสาร
│       └── 📁 profile/              # ข้อมูลส่วนตัว
│
├── 📁 public/                       # Public assets
│   ├── 📁 css/
│   ├── 📁 js/
│   └── 📁 images/
│
├── 📁 storage/
│   ├── 📁 documents/                # เอกสารที่สร้าง
│   ├── 📁 uploads/                  # ไฟล์อัปโหลด
│   └── 📁 temp/                     # ไฟล์ชั่วคราว
│
├── 📁 templates/
│   ├── header.php
│   ├── footer.php
│   ├── sidebar_admin.php
│   ├── sidebar_employee.php
│   └── 📁 documents/                # เทมเพลตเอกสาร
│       ├── certificate_work.php
│       ├── certificate_salary.php
│       └── contract_employment.php
│
├── 📁 database/
│   ├── schema.sql                   # Database schema
│   ├── seed.sql                     # Initial data
│   └── 📁 migrations/
│
├── 📁 docs/
│   └── HR_SYSTEM_ARCHITECTURE.md    # เอกสารนี้
│
├── index.php                        # หน้าแรก
├── login.php                        # Login (ใช้ร่วมกับ TP-CRM)
├── logout.php
├── bootstrap.php                    # Bootstrap file
├── .env                             # Environment config
├── .htaccess
└── README.md
```

---

## 7. แผนการดำเนินงาน

### Phase 1: Foundation (สัปดาห์ 1-2)
| งาน | รายละเอียด | ระยะเวลา |
|-----|----------|---------|
| Setup Project | สร้างโครงสร้างไฟล์, Config, Bootstrap | 2 วัน |
| Database Setup | สร้างตาราง, Migration | 2 วัน |
| Authentication | ใช้ร่วมกับ TP-CRM, SSO | 2 วัน |
| Base Templates | Header, Footer, Sidebar | 2 วัน |
| Role & Permission | สิทธิ์การเข้าถึง | 2 วัน |

### Phase 2: Time & Attendance (สัปดาห์ 3-4)
| งาน | รายละเอียด | ระยะเวลา |
|-----|----------|---------|
| Employee Check-in | GPS, Photo, QR | 3 วัน |
| Shift Management | กำหนดกะทำงาน | 2 วัน |
| Attendance Reports | รายงานการลงเวลา | 2 วัน |
| Time Adjustment | แก้ไขเวลา, อนุมัติ | 3 วัน |

### Phase 3: Leave Management (สัปดาห์ 5-6)
| งาน | รายละเอียด | ระยะเวลา |
|-----|----------|---------|
| Leave Types Setup | ประเภทการลา, สิทธิ์ | 2 วัน |
| Leave Request | ยื่นคำขอลา | 3 วัน |
| Approval Workflow | อนุมัติหลายระดับ | 3 วัน |
| Leave Calendar | ปฏิทินการลาทีม | 2 วัน |

### Phase 4: Payroll Integration (สัปดาห์ 7-8)
| งาน | รายละเอียด | ระยะเวลา |
|-----|----------|---------|
| TP-CRM API | เชื่อมต่อ API เงินเดือน | 3 วัน |
| Payslip Download | ดาวน์โหลดสลิป | 2 วัน |
| Auto Calculation | คำนวณเวลาทำงาน, OT | 3 วัน |
| Tax Reports | 50 ทวิ, YTD | 2 วัน |

### Phase 5: Document Management (สัปดาห์ 9-10)
| งาน | รายละเอียด | ระยะเวลา |
|-----|----------|---------|
| Document Templates | เทมเพลตเอกสาร | 3 วัน |
| Certificate Generator | สร้างใบรับรองอัตโนมัติ | 3 วัน |
| Digital Signature | ลายเซ็นดิจิทัล, QR | 2 วัน |
| Request Workflow | ขอเอกสาร, อนุมัติ | 2 วัน |

### Phase 6: Reports & Polish (สัปดาห์ 11-12)
| งาน | รายละเอียด | ระยะเวลา |
|-----|----------|---------|
| HR Dashboard | Dashboard ภาพรวม | 3 วัน |
| Employee Dashboard | หน้าหลักพนักงาน | 2 วัน |
| Reports | รายงานต่างๆ | 3 วัน |
| Testing & QA | ทดสอบระบบ | 2 วัน |

---

## 📞 ข้อมูลติดต่อ

**TP Development Team**  
Email: dev@tp-asset.com  
โทร: 02-XXX-XXXX

---

*เอกสารนี้เป็นเอกสาร Living Document และจะมีการปรับปรุงตามการพัฒนาระบบ*
