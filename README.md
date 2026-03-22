# AU Attendance Tracking System

A PHP + MySQL attendance tracking system for AU (Arellano University) SHS students.

---

## 📁 File Structure

```
ATS_1/
├── index.php                          ← Main login page (Student / Teacher / Admin)
├── login.php                          ← Redirects to index.php
├── logout.php                         ← Session destroy & redirect
├── attendance_management_system.sql   ← SQL schema — run this first!
│
├── config/
│   └── db.php                         ← Database connection config
│
├── student/
│   └── dashboard.php                  ← Student attendance portal
│
├── teacher/
│   └── dashboard.php                  ← Teacher approval dashboard
│
├── admin/
│   └── dashboard.php                  ← Admin management panel
│
└── assets/
    └── css/
        └── style.css                  ← Shared stylesheet
```

---

## 🚀 Setup Instructions

### 1. Database Setup
1. Open **phpMyAdmin** → `http://localhost/phpmyadmin`
2. Click **Import** → Choose `attendance_management_system.sql`
3. Click **Go** to run the SQL

### 2. Configure Database
Edit `config/db.php` if needed:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');          // your MySQL password
define('DB_NAME', 'attendance_management_system');
```

### 3. Access the System
Open: `http://localhost/ATS_1/`

---

## 👤 Default Admin Credentials

| Field    | Value        |
|----------|--------------|
| Username | `root`       |
| Password | `12345678`   |

> ⚠️ Change the admin password after first login via phpMyAdmin.

---

## 🔄 How It Works

### Student Flow
1. Student selects **Student** tab on login page
2. Enters their **Student ID** (format: `AUJS-SHS-25-XXXXXXXX`)
3. Enters **Gmail**, **Password**, selects **Section**, enters **Classroom Code**
4. First-time users must also enter their **Full Name**
5. Attendance is submitted as **PENDING**
6. Student dashboard auto-refreshes every 30 seconds

### Teacher Flow
1. Teacher selects **Teacher** tab
2. Enters name, grade, strand, section code, and creates/enters section password
3. Teacher dashboard shows **pending students** for approval
4. Teacher can **Verify** (approve) or **Reject** individual students
5. **Approve All** button approves all pending at once
6. Dashboard auto-refreshes every 20 seconds

### Admin Flow
1. Admin logs in with username/password
2. Can manage: **Approvals**, **Sections**, **Teachers**, **Students**, **Attendance Log**
3. Can approve/reject pending attendance directly from the Approvals panel
4. Can create sections and assign teachers
5. Can view full attendance history and reset records

---

## 🔐 Security Features
- Passwords hashed with `password_hash()` (bcrypt)
- Session-based authentication
- Student ID format validation (`AUJS-SHS-25-XXXXXXXX`)
- Classroom password required to submit attendance
- SQL injection prevention via prepared statements
- Unique attendance per student per section per day

---

## 📊 Database Tables

| Table              | Description                          |
|--------------------|--------------------------------------|
| `admins`           | Admin accounts                       |
| `teachers`         | Teacher accounts                     |
| `students`         | Student accounts                     |
| `sections`         | Classroom sections                   |
| `attendance`       | Attendance records (pending/approved/rejected) |
| `teacher_sections` | Teacher-to-section assignments       |
