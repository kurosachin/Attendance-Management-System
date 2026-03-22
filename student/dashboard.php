<?php
require_once '../config/db.php';

// Auth guard
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ../index.php');
    exit;
}

$student_id  = $_SESSION['student_id'];
$student_sid = $_SESSION['student_sid'];
$student_name= $_SESSION['student_name'];
$section_id  = $_SESSION['section_id'];

$conn = getDBConnection();

// Get section info
$stmt = $conn->prepare("SELECT section_name FROM sections WHERE id = ?");
$stmt->bind_param("i", $section_id);
$stmt->execute();
$section = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get today's attendance status
$today = date('Y-m-d');
$stmt = $conn->prepare("
    SELECT a.status, a.time_in, t.full_name as approved_by_name
    FROM attendance a
    LEFT JOIN teachers t ON a.approved_by = t.id
    WHERE a.student_id = ? AND a.section_id = ? AND a.attendance_date = ?
");
$stmt->bind_param("iis", $student_id, $section_id, $today);
$stmt->execute();
$attendance = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get attendance history (last 30 days)
$stmt = $conn->prepare("
    SELECT a.attendance_date, a.status, a.time_in, s.section_name
    FROM attendance a
    JOIN sections s ON a.section_id = s.id
    WHERE a.student_id = ?
    ORDER BY a.attendance_date DESC
    LIMIT 30
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Count stats
$total_present = 0;
$total_pending = 0;
foreach ($history as $h) {
    if ($h['status'] === 'approved') $total_present++;
    if ($h['status'] === 'pending')  $total_pending++;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal | AU Attendance</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .refresh-btn {
            background: none;
            border: 1px solid #ddd;
            padding: 6px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            color: #555;
            font-family: 'Inter', sans-serif;
        }
        .refresh-btn:hover { background: #f5f5f5; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #666; font-weight: 600; font-size: 12px; text-transform: uppercase; }
        .info-value { font-weight: 600; }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-brand">
        <i class="fas fa-user-graduate" style="color: var(--primary);"></i>
        Student Portal
    </div>
    <div style="display: flex; align-items: center; gap: 12px;">
        <button class="refresh-btn" onclick="location.reload()">
            <i class="fas fa-sync-alt"></i> Refresh
        </button>
        <a href="../logout.php" class="btn-logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</nav>

<div class="container-sm">

    <!-- Welcome Card -->
    <div class="card" style="text-align: center;">
        <div style="font-size: 48px; margin-bottom: 10px;">👤</div>
        <h2 style="margin: 0 0 5px; color: #333;">
            <?= htmlspecialchars($student_name) ?>
        </h2>
        <p class="text-muted" style="margin: 0;">
            <code><?= htmlspecialchars($student_sid) ?></code>
        </p>
    </div>

    <!-- Today's Status Card -->
    <div class="card">
        <h3><i class="fas fa-calendar-check"></i> Today's Attendance</h3>
        <p class="text-muted" style="margin-top: 0;">
            <?= date('l, F j, Y') ?>
        </p>

        <?php if ($attendance): ?>
            <?php
            $status_class = match($attendance['status']) {
                'approved' => 'status-approved',
                'rejected' => 'status-rejected',
                default    => 'status-pending'
            };
            $status_icon = match($attendance['status']) {
                'approved' => '✅',
                'rejected' => '❌',
                default    => '⏳'
            };
            $status_text = match($attendance['status']) {
                'approved' => 'ATTENDANCE CONFIRMED',
                'rejected' => 'ATTENDANCE REJECTED',
                default    => 'PENDING TEACHER APPROVAL'
            };
            ?>
            <div class="status-box <?= $status_class ?>">
                <?= $status_icon ?> <?= $status_text ?>
            </div>

            <?php if ($attendance['status'] === 'approved'): ?>
                <p style="font-size: 13px; color: #137333; text-align: center;">
                    <i class="fas fa-check-circle"></i>
                    Approved by <?= htmlspecialchars($attendance['approved_by_name'] ?? 'Teacher') ?>
                    at <?= date('h:i A', strtotime($attendance['time_in'])) ?>
                </p>
            <?php elseif ($attendance['status'] === 'pending'): ?>
                <p style="font-size: 13px; color: #856404; text-align: center;">
                    <i class="fas fa-clock"></i>
                    Waiting for teacher to verify your attendance.
                    <br><small>Time submitted: <?= date('h:i A', strtotime($attendance['time_in'])) ?></small>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <div class="status-box status-pending">
                ⚠️ NO ATTENDANCE RECORD TODAY
            </div>
            <p style="font-size: 13px; color: #666; text-align: center;">
                You have not signed in today. Please log in again to submit attendance.
            </p>
        <?php endif; ?>
    </div>

    <!-- Student Info Card -->
    <div class="card">
        <h3><i class="fas fa-id-card"></i> Student Information</h3>
        <div class="info-row">
            <span class="info-label">Student ID</span>
            <span class="info-value"><code><?= htmlspecialchars($student_sid) ?></code></span>
        </div>
        <div class="info-row">
            <span class="info-label">Full Name</span>
            <span class="info-value"><?= htmlspecialchars($student_name) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Classroom / Section</span>
            <span class="info-value"><?= htmlspecialchars($section['section_name'] ?? 'N/A') ?></span>
        </div>
    </div>

    <!-- Stats Card -->
    <div class="card-stats">
        <div class="stat-box">
            <div class="stat-number" style="color: var(--success);"><?= $total_present ?></div>
            <div class="stat-label">Days Present</div>
        </div>
        <div class="stat-box">
            <div class="stat-number" style="color: var(--warning);"><?= $total_pending ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-box">
            <div class="stat-number" style="color: #333;"><?= count($history) ?></div>
            <div class="stat-label">Total Records</div>
        </div>
    </div>

    <!-- Attendance History -->
    <?php if (!empty($history)): ?>
    <div class="card">
        <h3><i class="fas fa-history"></i> Attendance History</h3>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Section</th>
                        <th>Time In</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?= date('M j, Y', strtotime($h['attendance_date'])) ?></td>
                        <td><?= htmlspecialchars($h['section_name']) ?></td>
                        <td><?= date('h:i A', strtotime($h['time_in'])) ?></td>
                        <td>
                            <span class="badge badge-<?= $h['status'] ?>">
                                <?= strtoupper($h['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
    // Auto-refresh every 30 seconds to check approval status
    <?php if ($attendance && $attendance['status'] === 'pending'): ?>
    setTimeout(() => location.reload(), 30000);
    <?php endif; ?>
</script>

</body>
</html>
