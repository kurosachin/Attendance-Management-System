<?php
require_once '../config/db.php';

// Auth guard
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header('Location: ../index.php');
    exit;
}

$teacher_id   = $_SESSION['teacher_id'];
$teacher_name = $_SESSION['teacher_name'];
$section_id   = $_SESSION['section_id'];
$section_name = $_SESSION['section_name'];

$conn = getDBConnection();
$today = date('Y-m-d');
$message = '';
$msg_type = '';

// ── HANDLE ACTIONS ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $att_id = (int)($_POST['attendance_id'] ?? 0);
        $stmt = $conn->prepare("
            UPDATE attendance
            SET status = 'approved', approved_by = ?, approved_at = NOW()
            WHERE id = ? AND section_id = ?
        ");
        $stmt->bind_param("iii", $teacher_id, $att_id, $section_id);
        $stmt->execute();
        $stmt->close();
        $message = 'Student attendance approved successfully.';
        $msg_type = 'success';
    }

    elseif ($action === 'reject') {
        $att_id = (int)($_POST['attendance_id'] ?? 0);
        $stmt = $conn->prepare("
            UPDATE attendance
            SET status = 'rejected', approved_by = ?, approved_at = NOW()
            WHERE id = ? AND section_id = ?
        ");
        $stmt->bind_param("iii", $teacher_id, $att_id, $section_id);
        $stmt->execute();
        $stmt->close();
        $message = 'Student attendance rejected.';
        $msg_type = 'error';
    }

    elseif ($action === 'approve_all') {
        $stmt = $conn->prepare("
            UPDATE attendance
            SET status = 'approved', approved_by = ?, approved_at = NOW()
            WHERE section_id = ? AND status = 'pending' AND attendance_date = ?
        ");
        $stmt->bind_param("iis", $teacher_id, $section_id, $today);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        $message = "Approved all $affected pending students.";
        $msg_type = 'success';
    }
}

// ── FETCH DATA ────────────────────────────────────────────────

// Pending students today
$stmt = $conn->prepare("
    SELECT a.id as att_id, s.student_id, s.full_name, s.email, a.time_in
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE a.section_id = ? AND a.status = 'pending' AND a.attendance_date = ?
    ORDER BY a.time_in ASC
");
$stmt->bind_param("is", $section_id, $today);
$stmt->execute();
$pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Approved students today
$stmt = $conn->prepare("
    SELECT a.id as att_id, s.student_id, s.full_name, s.email, a.time_in, a.approved_at
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE a.section_id = ? AND a.status = 'approved' AND a.attendance_date = ?
    ORDER BY a.approved_at ASC
");
$stmt->bind_param("is", $section_id, $today);
$stmt->execute();
$approved = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Rejected students today
$stmt = $conn->prepare("
    SELECT a.id as att_id, s.student_id, s.full_name, a.time_in
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE a.section_id = ? AND a.status = 'rejected' AND a.attendance_date = ?
    ORDER BY a.time_in ASC
");
$stmt->bind_param("is", $section_id, $today);
$stmt->execute();
$rejected = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Section info
$stmt = $conn->prepare("SELECT * FROM sections WHERE id = ?");
$stmt->bind_param("i", $section_id);
$stmt->execute();
$section_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Attendance history (past 7 days)
$stmt = $conn->prepare("
    SELECT a.attendance_date,
           COUNT(CASE WHEN a.status = 'approved' THEN 1 END) as present,
           COUNT(CASE WHEN a.status = 'pending'  THEN 1 END) as pending,
           COUNT(CASE WHEN a.status = 'rejected' THEN 1 END) as rejected
    FROM attendance a
    WHERE a.section_id = ?
    GROUP BY a.attendance_date
    ORDER BY a.attendance_date DESC
    LIMIT 7
");
$stmt->bind_param("i", $section_id);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Portal | AU Attendance</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .action-btn {
            border: none;
            padding: 5px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            font-family: 'Inter', sans-serif;
            transition: 0.2s;
        }
        .action-btn:hover { opacity: 0.85; }
        .approve-btn { background: #e6f4ea; color: #137333; }
        .reject-btn  { background: #fce8e6; color: #d93025; }
        .tab-nav { display: flex; gap: 5px; margin-bottom: 15px; flex-wrap: wrap; }
        .tab-nav-btn {
            padding: 8px 16px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            transition: 0.2s;
        }
        .tab-nav-btn.active { background: var(--teacher); color: white; border-color: var(--teacher); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .count-badge {
            display: inline-block;
            background: var(--danger);
            color: white;
            border-radius: 10px;
            padding: 1px 7px;
            font-size: 11px;
            margin-left: 5px;
        }
    </style>
</head>
<body>

<nav class="navbar">
    <div>
        <div class="navbar-brand" style="color: var(--teacher);">
            <i class="fas fa-chalkboard-teacher"></i>
            Teacher Portal
        </div>
        <div style="font-size: 12px; color: #666; margin-top: 2px;">
            <?= htmlspecialchars($teacher_name) ?> &nbsp;|&nbsp;
            <b><?= htmlspecialchars($section_name) ?></b>
        </div>
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

<div class="container">

    <?php if ($message): ?>
        <div class="form-<?= $msg_type === 'success' ? 'success' : 'error' ?>" style="margin-bottom: 15px;">
            <i class="fas fa-<?= $msg_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Stats Row -->
    <div class="card-stats">
        <div class="stat-box">
            <div class="stat-number" style="color: var(--warning);"><?= count($pending) ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-box">
            <div class="stat-number" style="color: var(--success);"><?= count($approved) ?></div>
            <div class="stat-label">Approved</div>
        </div>
        <div class="stat-box">
            <div class="stat-number" style="color: var(--danger);"><?= count($rejected) ?></div>
            <div class="stat-label">Rejected</div>
        </div>
        <div class="stat-box">
            <div class="stat-number" style="color: var(--primary);"><?= count($pending) + count($approved) + count($rejected) ?></div>
            <div class="stat-label">Total Today</div>
        </div>
    </div>

    <!-- Main Content Tabs -->
    <div class="card">
        <div class="tab-nav">
            <button class="tab-nav-btn active" onclick="showTab('pending-tab', this)">
                <i class="fas fa-user-clock"></i> Pending
                <?php if (count($pending) > 0): ?>
                    <span class="count-badge"><?= count($pending) ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-nav-btn" onclick="showTab('approved-tab', this)">
                <i class="fas fa-user-check"></i> Approved
            </button>
            <button class="tab-nav-btn" onclick="showTab('rejected-tab', this)">
                <i class="fas fa-user-times"></i> Rejected
            </button>
            <button class="tab-nav-btn" onclick="showTab('history-tab', this)">
                <i class="fas fa-history"></i> History
            </button>
        </div>

        <!-- PENDING TAB -->
        <div id="pending-tab" class="tab-content active">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h3 style="margin: 0;"><i class="fas fa-user-shield"></i> Security Approval Queue</h3>
                <?php if (count($pending) > 0): ?>
                <form method="POST" style="margin: 0;" onsubmit="return confirm('Approve all <?= count($pending) ?> pending students?')">
                    <input type="hidden" name="action" value="approve_all">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="fas fa-check-double"></i> Approve All (<?= count($pending) ?>)
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Time Submitted</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pending)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted" style="padding: 30px;">
                                <i class="fas fa-check-circle" style="font-size: 24px; color: #ccc;"></i><br>
                                No pending security verifications
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($pending as $s): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($s['student_id']) ?></code></td>
                            <td><b><?= htmlspecialchars($s['full_name']) ?></b></td>
                            <td><?= htmlspecialchars($s['email']) ?></td>
                            <td><?= date('h:i A', strtotime($s['time_in'])) ?></td>
                            <td style="display: flex; gap: 6px;">
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="attendance_id" value="<?= $s['att_id'] ?>">
                                    <button type="submit" class="action-btn approve-btn">
                                        <i class="fas fa-check"></i> VERIFY
                                    </button>
                                </form>
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('Reject this student?')">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="attendance_id" value="<?= $s['att_id'] ?>">
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

        <!-- APPROVED TAB -->
        <div id="approved-tab" class="tab-content">
            <h3><i class="fas fa-list-ul"></i> Verified Attendance Ledger</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Full Name</th>
                            <th>Student ID</th>
                            <th>Time In</th>
                            <th>Approved At</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($approved)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted" style="padding: 30px;">
                                No students approved yet today
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($approved as $i => $s): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><b><?= htmlspecialchars($s['full_name']) ?></b></td>
                            <td><code><?= htmlspecialchars($s['student_id']) ?></code></td>
                            <td><?= date('h:i A', strtotime($s['time_in'])) ?></td>
                            <td><?= date('h:i A', strtotime($s['approved_at'])) ?></td>
                            <td><span style="color: var(--success);">● SIGNED IN</span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- REJECTED TAB -->
        <div id="rejected-tab" class="tab-content">
            <h3><i class="fas fa-user-times"></i> Rejected Attendance</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Time Submitted</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rejected)): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted" style="padding: 30px;">
                                No rejected attendance records today
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($rejected as $s): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($s['student_id']) ?></code></td>
                            <td><?= htmlspecialchars($s['full_name']) ?></td>
                            <td><?= date('h:i A', strtotime($s['time_in'])) ?></td>
                            <td><span class="badge badge-rejected">REJECTED</span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- HISTORY TAB -->
        <div id="history-tab" class="tab-content">
            <h3><i class="fas fa-calendar-alt"></i> Attendance History (Last 7 Days)</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Present</th>
                            <th>Pending</th>
                            <th>Rejected</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($history)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted" style="padding: 30px;">
                                No attendance history yet
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($history as $h): ?>
                        <tr>
                            <td><?= date('l, M j, Y', strtotime($h['attendance_date'])) ?></td>
                            <td><span style="color: var(--success); font-weight: 600;"><?= $h['present'] ?></span></td>
                            <td><span style="color: var(--warning); font-weight: 600;"><?= $h['pending'] ?></span></td>
                            <td><span style="color: var(--danger); font-weight: 600;"><?= $h['rejected'] ?></span></td>
                            <td><b><?= $h['present'] + $h['pending'] + $h['rejected'] ?></b></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Section Info Card -->
    <div class="card">
        <h3><i class="fas fa-info-circle"></i> Section Information</h3>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 14px;">
            <div><b>Section:</b> <?= htmlspecialchars($section_name) ?></div>
            <div><b>Grade:</b> <?= htmlspecialchars($section_info['grade_level'] ?? '') ?></div>
            <div><b>Strand:</b> <?= htmlspecialchars($section_info['strand'] ?? '') ?></div>
            <div><b>Code:</b> <?= htmlspecialchars($section_info['section_code'] ?? '') ?></div>
        </div>
    </div>

</div>

<script>
    function showTab(tabId, btn) {
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-nav-btn').forEach(b => b.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        btn.classList.add('active');
    }

    // Auto-refresh every 20 seconds to catch new pending students
    setTimeout(() => location.reload(), 20000);
</script>

</body>
</html>
