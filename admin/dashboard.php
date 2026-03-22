<?php
require_once '../config/db.php';

// Auth guard
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$admin_name = $_SESSION['admin_name'];
$conn = getDBConnection();
$message = '';
$msg_type = '';

// ── HANDLE ACTIONS ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add Teacher
    if ($action === 'add_teacher') {
        $full_name  = trim($_POST['full_name'] ?? '');
        $email      = strtolower(trim($_POST['email'] ?? ''));
        $password   = $_POST['password'] ?? '';
        $emp_id     = strtoupper(trim($_POST['employee_id'] ?? ''));

        if (empty($full_name) || empty($password)) {
            $message = 'Full name and password are required.';
            $msg_type = 'error';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO teachers (full_name, email, password, employee_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $full_name, $email, $hashed, $emp_id);
            if ($stmt->execute()) {
                $message = "Teacher '$full_name' added successfully.";
                $msg_type = 'success';
            } else {
                $message = 'Failed to add teacher. Email or Employee ID may already exist.';
                $msg_type = 'error';
            }
            $stmt->close();
        }
    }

    // Delete Teacher
    elseif ($action === 'delete_teacher') {
        $tid = (int)($_POST['teacher_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM teachers WHERE id = ?");
        $stmt->bind_param("i", $tid);
        $stmt->execute();
        $stmt->close();
        $message = 'Teacher removed.';
        $msg_type = 'success';
    }

    // Add Section
    elseif ($action === 'add_section') {
        $grade      = $_POST['grade'] ?? '';
        $strand     = $_POST['strand'] ?? '';
        $sec_code   = trim($_POST['section_code'] ?? '');
        $password   = $_POST['section_password'] ?? '';
        $teacher_id = (int)($_POST['teacher_id'] ?? 0);

        if (empty($grade) || empty($strand) || empty($sec_code) || empty($password)) {
            $message = 'All section fields are required.';
            $msg_type = 'error';
        } else {
            $full_sec = "$grade - $strand ($sec_code)";
            $hashed   = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO sections (section_name, grade_level, strand, section_code, section_password, teacher_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssi", $full_sec, $grade, $strand, $sec_code, $hashed, $teacher_id ?: null);
            if ($stmt->execute()) {
                $sec_id = $conn->insert_id;
                if ($teacher_id) {
                    $stmt2 = $conn->prepare("INSERT IGNORE INTO teacher_sections (teacher_id, section_id) VALUES (?, ?)");
                    $stmt2->bind_param("ii", $teacher_id, $sec_id);
                    $stmt2->execute();
                    $stmt2->close();
                }
                $message = "Section '$full_sec' created successfully.";
                $msg_type = 'success';
            } else {
                $message = 'Failed to create section. It may already exist.';
                $msg_type = 'error';
            }
            $stmt->close();
        }
    }

    // Delete Section
    elseif ($action === 'delete_section') {
        $sid = (int)($_POST['section_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM sections WHERE id = ?");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $stmt->close();
        $message = 'Section deleted.';
        $msg_type = 'success';
    }

    // Toggle Section Active
    elseif ($action === 'toggle_section') {
        $sid = (int)($_POST['section_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE sections SET is_active = NOT is_active WHERE id = ?");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $stmt->close();
        $message = 'Section status updated.';
        $msg_type = 'success';
    }

    // Delete Student
    elseif ($action === 'delete_student') {
        $stid = (int)($_POST['student_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
        $stmt->bind_param("i", $stid);
        $stmt->execute();
        $stmt->close();
        $message = 'Student removed.';
        $msg_type = 'success';
    }

    // Admin Approve Attendance
    elseif ($action === 'admin_approve') {
        $att_id = (int)($_POST['attendance_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE attendance SET status = 'approved', approved_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $att_id);
        $stmt->execute();
        $stmt->close();
        $message = 'Attendance approved.';
        $msg_type = 'success';
    }

    // Admin Reject Attendance
    elseif ($action === 'admin_reject') {
        $att_id = (int)($_POST['attendance_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE attendance SET status = 'rejected', approved_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $att_id);
        $stmt->execute();
        $stmt->close();
        $message = 'Attendance rejected.';
        $msg_type = 'success';
    }

    // Admin Approve All Pending
    elseif ($action === 'admin_approve_all') {
        $today_date = date('Y-m-d');
        $stmt = $conn->prepare("UPDATE attendance SET status = 'approved', approved_at = NOW() WHERE status = 'pending' AND attendance_date = ?");
        $stmt->bind_param("s", $today_date);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        $message = "Approved all $affected pending attendance records.";
        $msg_type = 'success';
    }

    // Reset Attendance (clear today's for a section)
    elseif ($action === 'reset_attendance') {
        $sid  = (int)($_POST['section_id'] ?? 0);
        $date = $_POST['att_date'] ?? date('Y-m-d');
        $stmt = $conn->prepare("DELETE FROM attendance WHERE section_id = ? AND attendance_date = ?");
        $stmt->bind_param("is", $sid, $date);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        $message = "Cleared $affected attendance records for that date.";
        $msg_type = 'success';
    }
}

// ── FETCH DATA ────────────────────────────────────────────────
$today = date('Y-m-d');

// Summary stats
$stats = [];
$stats['total_students'] = $conn->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'];
$stats['total_teachers'] = $conn->query("SELECT COUNT(*) as c FROM teachers")->fetch_assoc()['c'];
$stats['total_sections'] = $conn->query("SELECT COUNT(*) as c FROM sections")->fetch_assoc()['c'];
$stats['today_present']  = $conn->query("SELECT COUNT(*) as c FROM attendance WHERE status='approved' AND attendance_date='$today'")->fetch_assoc()['c'];
$stats['today_pending']  = $conn->query("SELECT COUNT(*) as c FROM attendance WHERE status='pending'  AND attendance_date='$today'")->fetch_assoc()['c'];

// All teachers
$teachers = $conn->query("SELECT * FROM teachers ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// All sections with teacher name
$sections = $conn->query("
    SELECT s.*, t.full_name as teacher_name,
           (SELECT COUNT(*) FROM attendance a WHERE a.section_id = s.id AND a.attendance_date = '$today' AND a.status = 'approved') as today_present,
           (SELECT COUNT(*) FROM attendance a WHERE a.section_id = s.id AND a.attendance_date = '$today' AND a.status = 'pending')  as today_pending
    FROM sections s
    LEFT JOIN teachers t ON s.teacher_id = t.id
    ORDER BY s.section_name
")->fetch_all(MYSQLI_ASSOC);

// All students
$students = $conn->query("
    SELECT s.*,
           (SELECT COUNT(*) FROM attendance a WHERE a.student_id = s.id AND a.status = 'approved') as total_present
    FROM students s
    ORDER BY s.full_name
")->fetch_all(MYSQLI_ASSOC);

// Pending attendance (all sections, today)
$pending_att = $conn->query("
    SELECT a.id as att_id, a.time_in, s.full_name as student_name, s.student_id as sid,
           s.email, sec.section_name, sec.id as section_id
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    JOIN sections sec ON a.section_id = sec.id
    WHERE a.status = 'pending' AND a.attendance_date = '$today'
    ORDER BY a.time_in ASC
")->fetch_all(MYSQLI_ASSOC);

// Recent attendance (last 50)
$recent_att = $conn->query("
    SELECT a.*, s.full_name as student_name, s.student_id as sid,
           sec.section_name, t.full_name as teacher_name
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    JOIN sections sec ON a.section_id = sec.id
    LEFT JOIN teachers t ON a.approved_by = t.id
    ORDER BY a.time_in DESC
    LIMIT 50
")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel | AU Attendance</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .sidebar {
            width: 220px;
            background: white;
            border-right: 1px solid #ddd;
            min-height: calc(100vh - 57px);
            position: fixed;
            top: 57px;
            left: 0;
            padding: 20px 0;
        }
        .sidebar-link {
            display: block;
            padding: 12px 20px;
            color: #555;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: 0.2s;
            border-left: 3px solid transparent;
        }
        .sidebar-link:hover, .sidebar-link.active {
            background: #fff3e0;
            color: var(--admin);
            border-left-color: var(--admin);
        }
        .sidebar-link i { width: 20px; margin-right: 8px; }
        .main-content { margin-left: 220px; padding: 20px; }
        .page-section { display: none; }
        .page-section.active { display: block; }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: white;
            padding: 30px;
            border-radius: 12px;
            width: 100%;
            max-width: 450px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-box h3 { margin-top: 0; }
        .action-btn {
            border: none;
            padding: 5px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            font-family: 'Inter', sans-serif;
        }
        .del-btn { background: #fce8e6; color: #d93025; }
        .toggle-btn { background: #e8f0fe; color: #1a73e8; }
        .approve-btn { background: #e6f4ea; color: #137333; }
        .reject-btn  { background: #fce8e6; color: #d93025; }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-brand" style="color: var(--admin);">
        <i class="fas fa-user-shield"></i> Admin Panel
        <span style="font-size: 12px; color: #666; font-weight: 400; margin-left: 10px;">
            <?= htmlspecialchars($admin_name) ?>
        </span>
    </div>
    <div style="display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 12px; color: #666;">
            <i class="fas fa-calendar"></i> <?= date('M j, Y') ?>
        </span>
        <a href="../logout.php" class="btn-logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</nav>

<!-- Sidebar -->
<div class="sidebar">
    <a href="#" class="sidebar-link active" onclick="showSection('dashboard', this)">
        <i class="fas fa-tachometer-alt"></i> Dashboard
    </a>
    <a href="#" class="sidebar-link" onclick="showSection('approvals', this)" id="approvals-link">
        <i class="fas fa-user-check"></i> Approvals
        <?php if (count($pending_att) > 0): ?>
        <span style="background:var(--danger);color:white;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;"><?= count($pending_att) ?></span>
        <?php endif; ?>
    </a>
    <a href="#" class="sidebar-link" onclick="showSection('sections', this)">
        <i class="fas fa-door-open"></i> Sections
    </a>
    <a href="#" class="sidebar-link" onclick="showSection('teachers', this)">
        <i class="fas fa-chalkboard-teacher"></i> Teachers
    </a>
    <a href="#" class="sidebar-link" onclick="showSection('students', this)">
        <i class="fas fa-user-graduate"></i> Students
    </a>
    <a href="#" class="sidebar-link" onclick="showSection('attendance', this)">
        <i class="fas fa-clipboard-list"></i> Attendance Log
    </a>
</div>

<!-- Main Content -->
<div class="main-content">

    <?php if ($message): ?>
        <div class="form-<?= $msg_type === 'success' ? 'success' : 'error' ?>" style="margin-bottom: 15px;">
            <i class="fas fa-<?= $msg_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- ===== DASHBOARD ===== -->
    <div id="section-dashboard" class="page-section active">
        <h2><i class="fas fa-tachometer-alt"></i> Dashboard Overview</h2>
        <p class="text-muted">Today: <?= date('l, F j, Y') ?></p>

        <div class="card-stats">
            <div class="stat-box">
                <div class="stat-number" style="color: var(--primary);"><?= $stats['total_students'] ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" style="color: var(--teacher);"><?= $stats['total_teachers'] ?></div>
                <div class="stat-label">Teachers</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" style="color: var(--admin);"><?= $stats['total_sections'] ?></div>
                <div class="stat-label">Sections</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" style="color: var(--success);"><?= $stats['today_present'] ?></div>
                <div class="stat-label">Present Today</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" style="color: var(--warning);"><?= $stats['today_pending'] ?></div>
                <div class="stat-label">Pending Today</div>
            </div>
        </div>

        <!-- Today's Attendance by Section -->
        <div class="card">
            <h3><i class="fas fa-chart-bar"></i> Today's Attendance by Section</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th>Teacher</th>
                            <th>Present</th>
                            <th>Pending</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sections as $sec): ?>
                        <tr>
                            <td><b><?= htmlspecialchars($sec['section_name']) ?></b></td>
                            <td><?= htmlspecialchars($sec['teacher_name'] ?? '—') ?></td>
                            <td><span style="color: var(--success); font-weight: 600;"><?= $sec['today_present'] ?></span></td>
                            <td><span style="color: var(--warning); font-weight: 600;"><?= $sec['today_pending'] ?></span></td>
                            <td>
                                <span class="badge <?= $sec['is_active'] ? 'badge-approved' : 'badge-rejected' ?>">
                                    <?= $sec['is_active'] ? 'ACTIVE' : 'INACTIVE' ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sections)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No sections created yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===== APPROVALS ===== -->
    <div id="section-approvals" class="page-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2><i class="fas fa-user-check"></i> Pending Attendance Approvals</h2>
            <?php if (count($pending_att) > 0): ?>
            <form method="POST" style="margin:0;" onsubmit="return confirm('Approve all <?= count($pending_att) ?> pending students?')">
                <input type="hidden" name="action" value="admin_approve_all">
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="fas fa-check-double"></i> Approve All (<?= count($pending_att) ?>)
                </button>
            </form>
            <?php endif; ?>
        </div>
        <p class="text-muted">Today: <?= date('l, F j, Y') ?></p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Section</th>
                            <th>Time Submitted</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pending_att)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted" style="padding: 30px;">
                                <i class="fas fa-check-circle" style="font-size: 24px; color: #ccc;"></i><br>
                                No pending attendance records today
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($pending_att as $p): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($p['sid']) ?></code></td>
                            <td><b><?= htmlspecialchars($p['student_name']) ?></b></td>
                            <td><?= htmlspecialchars($p['email']) ?></td>
                            <td><?= htmlspecialchars($p['section_name']) ?></td>
                            <td><?= date('h:i A', strtotime($p['time_in'])) ?></td>
                            <td style="display: flex; gap: 6px;">
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="admin_approve">
                                    <input type="hidden" name="attendance_id" value="<?= $p['att_id'] ?>">
                                    <button type="submit" class="action-btn approve-btn">
                                        <i class="fas fa-check"></i> APPROVE
                                    </button>
                                </form>
                                <form method="POST" style="margin:0;" onsubmit="return confirm('Reject this student?')">
                                    <input type="hidden" name="action" value="admin_reject">
                                    <input type="hidden" name="attendance_id" value="<?= $p['att_id'] ?>">
                                    <button type="submit" class="action-btn reject-btn">
                                        <i class="fas fa-times"></i> REJECT
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===== SECTIONS ===== -->
    <div id="section-sections" class="page-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2><i class="fas fa-door-open"></i> Manage Sections</h2>
            <button class="btn btn-admin btn-sm" onclick="openModal('add-section-modal')">
                <i class="fas fa-plus"></i> Add Section
            </button>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Section Name</th>
                            <th>Grade</th>
                            <th>Strand</th>
                            <th>Teacher</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sections as $sec): ?>
                        <tr>
                            <td><b><?= htmlspecialchars($sec['section_name']) ?></b></td>
                            <td><?= htmlspecialchars($sec['grade_level']) ?></td>
                            <td><?= htmlspecialchars($sec['strand']) ?></td>
                            <td><?= htmlspecialchars($sec['teacher_name'] ?? '—') ?></td>
                            <td>
                                <span class="badge <?= $sec['is_active'] ? 'badge-approved' : 'badge-rejected' ?>">
                                    <?= $sec['is_active'] ? 'ACTIVE' : 'INACTIVE' ?>
                                </span>
                            </td>
                            <td style="display: flex; gap: 5px; flex-wrap: wrap;">
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="toggle_section">
                                    <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
                                    <button type="submit" class="action-btn toggle-btn">
                                        <?= $sec['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                                <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this section and all its attendance records?')">
                                    <input type="hidden" name="action" value="delete_section">
                                    <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
                                    <button type="submit" class="action-btn del-btn">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sections)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No sections yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===== TEACHERS ===== -->
    <div id="section-teachers" class="page-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2><i class="fas fa-chalkboard-teacher"></i> Manage Teachers</h2>
            <button class="btn btn-teacher btn-sm" onclick="openModal('add-teacher-modal')">
                <i class="fas fa-plus"></i> Add Teacher
            </button>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teachers as $t): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($t['employee_id'] ?? '—') ?></code></td>
                            <td><b><?= htmlspecialchars($t['full_name']) ?></b></td>
                            <td><?= htmlspecialchars($t['email'] ?? '—') ?></td>
                            <td><?= date('M j, Y', strtotime($t['created_at'])) ?></td>
                            <td>
                                <form method="POST" style="margin:0;" onsubmit="return confirm('Remove this teacher?')">
                                    <input type="hidden" name="action" value="delete_teacher">
                                    <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                                    <button type="submit" class="action-btn del-btn">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($teachers)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No teachers yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===== STUDENTS ===== -->
    <div id="section-students" class="page-section">
        <h2><i class="fas fa-user-graduate"></i> Manage Students</h2>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Days Present</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($s['student_id']) ?></code></td>
                            <td><b><?= htmlspecialchars($s['full_name']) ?></b></td>
                            <td><?= htmlspecialchars($s['email']) ?></td>
                            <td><span style="color: var(--success); font-weight: 600;"><?= $s['total_present'] ?></span></td>
                            <td><?= date('M j, Y', strtotime($s['created_at'])) ?></td>
                            <td>
                                <form method="POST" style="margin:0;" onsubmit="return confirm('Remove this student and all their records?')">
                                    <input type="hidden" name="action" value="delete_student">
                                    <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="action-btn del-btn">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($students)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No students registered yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===== ATTENDANCE LOG ===== -->
    <div id="section-attendance" class="page-section">
        <h2><i class="fas fa-clipboard-list"></i> Attendance Log</h2>

        <!-- Reset Attendance Tool -->
        <div class="card">
            <h3><i class="fas fa-trash-alt" style="color: var(--danger);"></i> Reset Attendance</h3>
            <form method="POST" onsubmit="return confirm('This will permanently delete attendance records. Continue?')">
                <input type="hidden" name="action" value="reset_attendance">
                <div class="grid-2">
                    <div class="input-group" style="margin: 0;">
                        <label>Section</label>
                        <select name="section_id" required>
                            <option value="">-- Select Section --</option>
                            <?php foreach ($sections as $sec): ?>
                            <option value="<?= $sec['id'] ?>"><?= htmlspecialchars($sec['section_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group" style="margin: 0;">
                        <label>Date</label>
                        <input type="date" name="att_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-danger btn-sm" style="margin-top: 10px;">
                    <i class="fas fa-trash"></i> Clear Attendance Records
                </button>
            </form>
        </div>

        <!-- Recent Attendance -->
        <div class="card">
            <h3><i class="fas fa-list"></i> Recent Attendance (Last 50)</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Student ID</th>
                            <th>Section</th>
                            <th>Time In</th>
                            <th>Status</th>
                            <th>Approved By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_att as $a): ?>
                        <tr>
                            <td><?= date('M j', strtotime($a['attendance_date'])) ?></td>
                            <td><b><?= htmlspecialchars($a['student_name']) ?></b></td>
                            <td><code><?= htmlspecialchars($a['sid']) ?></code></td>
                            <td><?= htmlspecialchars($a['section_name']) ?></td>
                            <td><?= date('h:i A', strtotime($a['time_in'])) ?></td>
                            <td><span class="badge badge-<?= $a['status'] ?>"><?= strtoupper($a['status']) ?></span></td>
                            <td><?= htmlspecialchars($a['teacher_name'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_att)): ?>
                        <tr><td colspan="7" class="text-center text-muted">No attendance records yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- ===== MODAL: Add Teacher ===== -->
<div id="add-teacher-modal" class="modal-overlay">
    <div class="modal-box">
        <h3><i class="fas fa-chalkboard-teacher"></i> Add New Teacher</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_teacher">
            <div class="input-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" placeholder="Last Name, First Name M.I." required>
            </div>
            <div class="input-group">
                <label>Email</label>
                <input type="email" name="email" placeholder="teacher@au.edu.ph">
            </div>
            <div class="input-group">
                <label>Employee ID</label>
                <input type="text" name="employee_id" placeholder="TCH-XXXXXXXX">
            </div>
            <div class="input-group">
                <label>Password *</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 15px;">
                <button type="submit" class="btn btn-teacher" style="flex:1;">Add Teacher</button>
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('add-teacher-modal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== MODAL: Add Section ===== -->
<div id="add-section-modal" class="modal-overlay">
    <div class="modal-box">
        <h3><i class="fas fa-door-open"></i> Create New Section</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_section">
            <div class="grid-2">
                <div class="input-group">
                    <label>Grade Level *</label>
                    <select name="grade" required>
                        <option value="Grade 11">Grade 11</option>
                        <option value="Grade 12">Grade 12</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Strand *</label>
                    <select name="strand" required>
                        <?php foreach (['SAD','ICT','GAS','HUMSS','STEM','HE'] as $s): ?>
                        <option value="<?= $s ?>"><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="input-group">
                <label>Section Code *</label>
                <input type="text" name="section_code" placeholder="e.g., A1, B2" required>
            </div>
            <div class="input-group">
                <label>Section Password *</label>
                <input type="password" name="section_password" placeholder="Classroom code for students" required>
            </div>
            <div class="input-group">
                <label>Assign Teacher (Optional)</label>
                <select name="teacher_id">
                    <option value="">-- No Teacher Assigned --</option>
                    <?php foreach ($teachers as $t): ?>
                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 15px;">
                <button type="submit" class="btn btn-admin" style="flex:1;">Create Section</button>
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('add-section-modal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showSection(id, link) {
        document.querySelectorAll('.page-section').forEach(s => s.classList.remove('active'));
        document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
        document.getElementById('section-' + id).classList.add('active');
        link.classList.add('active');
    }

    function openModal(id) {
        document.getElementById(id).classList.add('open');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
    }

    // Close modal on overlay click
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('open');
        });
    });
</script>

</body>
</html>
