<?php
require_once 'config/db.php';

// Redirect if already logged in
if (isset($_SESSION['user_type'])) {
    switch ($_SESSION['user_type']) {
        case 'student': header('Location: student/dashboard.php'); exit;
        case 'teacher': header('Location: teacher/dashboard.php'); exit;
        case 'admin':   header('Location: admin/dashboard.php');   exit;
    }
}

$error = '';
$success = '';

// Handle POST login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── STUDENT LOGIN / REGISTER ──────────────────────────────
    if ($action === 'student_login') {
        $sid       = strtoupper(trim($_POST['student_id'] ?? ''));
        $email     = strtolower(trim($_POST['email'] ?? ''));
        $password  = $_POST['password'] ?? '';
        $section   = $_POST['section'] ?? '';
        $sec_pass  = $_POST['section_password'] ?? '';
        $full_name = trim($_POST['full_name'] ?? '');

        // Validate ID format
        if (!preg_match('/^AUJS-SHS-25-[A-Z0-9]{8}$/', $sid)) {
            $error = 'Access Denied: Invalid Student ID format. Must be AUJS-SHS-25-XXXXXXXX';
        } elseif (empty($email) || empty($password) || empty($section) || empty($sec_pass)) {
            $error = 'Please fill in all required fields.';
        } else {
            $conn = getDBConnection();

            // Verify section password
            $stmt = $conn->prepare("SELECT id, section_password FROM sections WHERE id = ? AND is_active = 1");
            $stmt->bind_param("i", $section);
            $stmt->execute();
            $sec_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$sec_row) {
                $error = 'Invalid section selected.';
            } elseif (!password_verify($sec_pass, $sec_row['section_password'])) {
                $error = 'Hacking Attempt Detected: Invalid Classroom Code!';
            } else {
                // Check if student exists
                $stmt = $conn->prepare("SELECT * FROM students WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $student = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$student) {
                    // New registration
                    if (empty($full_name)) {
                        $error = 'NEW_USER_NEEDS_NAME';
                    } else {
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("INSERT INTO students (student_id, full_name, email, password) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("ssss", $sid, $full_name, $email, $hashed);
                        if ($stmt->execute()) {
                            $student_db_id = $conn->insert_id;
                            $student = ['id' => $student_db_id, 'student_id' => $sid, 'full_name' => $full_name, 'email' => $email];
                        } else {
                            $error = 'Registration failed. Student ID or Email may already be in use.';
                        }
                        $stmt->close();
                    }
                } else {
                    // Existing student — verify credentials
                    if (!password_verify($password, $student['password']) || $student['student_id'] !== $sid) {
                        $error = 'Security Conflict: ID or Password does not match our records!';
                        $student = null;
                    }
                }

                if ($student && empty($error)) {
                    $section_id = $sec_row['id'];
                    $today = date('Y-m-d');

                    // Check existing attendance today
                    $stmt = $conn->prepare("SELECT id, status FROM attendance WHERE student_id = ? AND section_id = ? AND attendance_date = ?");
                    $stmt->bind_param("iis", $student['id'], $section_id, $today);
                    $stmt->execute();
                    $att = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$att) {
                        // Insert pending attendance
                        $stmt = $conn->prepare("INSERT INTO attendance (student_id, section_id, status, attendance_date) VALUES (?, ?, 'pending', ?)");
                        $stmt->bind_param("iis", $student['id'], $section_id, $today);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // Set session
                    $_SESSION['user_type']   = 'student';
                    $_SESSION['student_id']  = $student['id'];
                    $_SESSION['student_sid'] = $student['student_id'];
                    $_SESSION['student_name']= $student['full_name'];
                    $_SESSION['section_id']  = $section_id;

                    $conn->close();
                    header('Location: student/dashboard.php');
                    exit;
                }
            }
            $conn->close();
        }
    }

    // ── TEACHER LOGIN / CREATE SECTION ───────────────────────
    elseif ($action === 'teacher_login') {
        $name      = trim($_POST['teacher_name'] ?? '');
        $grade     = $_POST['grade'] ?? '';
        $strand    = $_POST['strand'] ?? '';
        $sec_code  = trim($_POST['section_code'] ?? '');
        $password  = $_POST['teacher_password'] ?? '';

        if (empty($name) || empty($grade) || empty($strand) || empty($sec_code) || empty($password)) {
            $error = 'Please fill in all teacher fields.';
        } else {
            $conn = getDBConnection();
            $full_sec = "$grade - $strand ($sec_code)";

            // Check if section exists
            $stmt = $conn->prepare("SELECT s.*, t.full_name as teacher_name FROM sections s LEFT JOIN teachers t ON s.teacher_id = t.id WHERE s.section_name = ?");
            $stmt->bind_param("s", $full_sec);
            $stmt->execute();
            $sec_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$sec_row) {
                // Create new section + teacher account
                $hashed_pass = password_hash($password, PASSWORD_DEFAULT);

                // Check if teacher exists by name
                $stmt = $conn->prepare("SELECT id FROM teachers WHERE full_name = ?");
                $stmt->bind_param("s", $name);
                $stmt->execute();
                $teacher = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$teacher) {
                    $emp_id = 'TCH-' . strtoupper(substr(md5($name . time()), 0, 8));
                    $stmt = $conn->prepare("INSERT INTO teachers (full_name, password, employee_id) VALUES (?, ?, ?)");
                    $stmt->bind_param("sss", $name, $hashed_pass, $emp_id);
                    $stmt->execute();
                    $teacher_id = $conn->insert_id;
                    $stmt->close();
                } else {
                    $teacher_id = $teacher['id'];
                }

                // Create section
                $stmt = $conn->prepare("INSERT INTO sections (section_name, grade_level, strand, section_code, section_password, teacher_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssi", $full_sec, $grade, $strand, $sec_code, $hashed_pass, $teacher_id);
                $stmt->execute();
                $section_id = $conn->insert_id;
                $stmt->close();

                // Assign teacher to section
                $stmt = $conn->prepare("INSERT IGNORE INTO teacher_sections (teacher_id, section_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $teacher_id, $section_id);
                $stmt->execute();
                $stmt->close();

            } else {
                // Section exists — verify password
                if (!password_verify($password, $sec_row['section_password'])) {
                    $error = 'Security Block: Incorrect Section Password.';
                    $conn->close();
                    goto render;
                }
                $teacher_id = $sec_row['teacher_id'];
                $section_id = $sec_row['id'];
            }

            $_SESSION['user_type']    = 'teacher';
            $_SESSION['teacher_id']   = $teacher_id;
            $_SESSION['teacher_name'] = $name;
            $_SESSION['section_id']   = $section_id;
            $_SESSION['section_name'] = $full_sec;

            $conn->close();
            header('Location: teacher/dashboard.php');
            exit;
        }
    }

    // ── ADMIN LOGIN ───────────────────────────────────────────
    elseif ($action === 'admin_login') {
        $username = trim($_POST['admin_username'] ?? '');
        $password = $_POST['admin_password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please enter admin credentials.';
        } else {
            $conn = getDBConnection();
            $stmt = $conn->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $admin = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $conn->close();

            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['user_type']  = 'admin';
                $_SESSION['admin_id']   = $admin['id'];
                $_SESSION['admin_name'] = $admin['full_name'];
                header('Location: admin/dashboard.php');
                exit;
            } else {
                $error = 'Invalid admin credentials.';
            }
        }
    }
}

render:
// Fetch sections for dropdown
$sections = [];
try {
    $conn = getDBConnection();
    $result = $conn->query("SELECT id, section_name FROM sections WHERE is_active = 1 ORDER BY section_name");
    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }
    $conn->close();
} catch (Exception $e) {
    // DB not set up yet
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AU Attendance Tracking System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="auth-container">
    <div class="auth-box">
        <div class="auth-logo">🎓</div>
        <h2>AU Attendance Tracking System</h2>

        <?php if (!empty($error) && $error !== 'NEW_USER_NEEDS_NAME'): ?>
            <div class="form-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- TAB SWITCHER -->
        <div class="tab-group">
            <button onclick="setMode('student')" id="std-tab" class="tab-btn btn-primary">
                <i class="fas fa-user-graduate"></i> Student
            </button>
            <button onclick="setMode('teacher')" id="tch-tab" class="tab-btn btn-secondary">
                <i class="fas fa-chalkboard-teacher"></i> Teacher
            </button>
            <button onclick="setMode('admin')" id="adm-tab" class="tab-btn btn-secondary">
                <i class="fas fa-user-shield"></i> Admin
            </button>
        </div>

        <!-- ===== STUDENT FORM ===== -->
        <div id="student-form">
            <form method="POST" action="index.php" id="student-login-form">
                <input type="hidden" name="action" value="student_login">

                <div class="input-group">
                    <label>Student ID Number</label>
                    <input type="text" name="student_id" id="std-login-id"
                           placeholder="AUJS-SHS-25-XXXXXXXX"
                           value="<?= htmlspecialchars($_POST['student_id'] ?? '') ?>"
                           required>
                    <div class="id-hint"><i class="fas fa-info-circle"></i> Format: AUJS-SHS-25-XXXXXXXX</div>
                </div>

                <div class="input-group">
                    <label>Gmail Address</label>
                    <input type="email" name="email" id="std-email"
                           placeholder="yourname@gmail.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           required>
                </div>

                <div class="input-group">
                    <label>Account Password</label>
                    <input type="password" name="password" id="std-pass" placeholder="••••••••" required>
                    <i class="fas fa-eye toggle-eye" onclick="togglePass('std-pass', this)"></i>
                </div>

                <!-- Registration fields (shown for new users) -->
                <div id="reg-fields" class="<?= ($error === 'NEW_USER_NEEDS_NAME') ? '' : 'hidden' ?>">
                    <div class="form-info">
                        <b>NEW ACCOUNT REGISTRATION</b><br>
                        Please provide your full name to complete registration.
                    </div>
                    <div class="input-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" id="std-fullname"
                               placeholder="Last Name, First Name M.I."
                               value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="input-group">
                    <label>Section Selection</label>
                    <select name="section" id="std-section-select" required>
                        <option value="">-- Select Your Section --</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?= $sec['id'] ?>"
                                <?= (isset($_POST['section']) && $_POST['section'] == $sec['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sec['section_name']) ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (empty($sections)): ?>
                            <option value="" disabled>No sections available yet</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="input-group">
                    <label>Classroom Password</label>
                    <input type="password" name="section_password" id="std-sec-pass"
                           placeholder="Enter Classroom Code" required>
                    <i class="fas fa-eye toggle-eye" onclick="togglePass('std-sec-pass', this)"></i>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-fingerprint"></i> Verify Identity & Sign In
                </button>
            </form>
        </div>

        <!-- ===== TEACHER FORM ===== -->
        <div id="teacher-form" class="hidden">
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="teacher_login">

                <div class="input-group">
                    <label>Full Name</label>
                    <input type="text" name="teacher_name" placeholder="Last Name, First Name M.I."
                           value="<?= htmlspecialchars($_POST['teacher_name'] ?? '') ?>">
                </div>

                <div class="grid-2 mt-10">
                    <div class="input-group" style="margin:0">
                        <label>Grade Level</label>
                        <select name="grade">
                            <option value="Grade 11" <?= (($_POST['grade'] ?? '') === 'Grade 11') ? 'selected' : '' ?>>Grade 11</option>
                            <option value="Grade 12" <?= (($_POST['grade'] ?? '') === 'Grade 12') ? 'selected' : '' ?>>Grade 12</option>
                        </select>
                    </div>
                    <div class="input-group" style="margin:0">
                        <label>Strand</label>
                        <select name="strand">
                            <?php foreach (['SAD','ICT','GAS','HUMSS','STEM','HE'] as $s): ?>
                                <option value="<?= $s ?>" <?= (($_POST['strand'] ?? '') === $s) ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="input-group">
                    <label>Section Code</label>
                    <input type="text" name="section_code" placeholder="e.g., A1, B2"
                           value="<?= htmlspecialchars($_POST['section_code'] ?? '') ?>">
                </div>

                <div class="input-group">
                    <label>Section Password (Create or Enter Existing)</label>
                    <input type="password" name="teacher_password" id="tch-pass" placeholder="••••••••">
                    <i class="fas fa-eye toggle-eye" onclick="togglePass('tch-pass', this)"></i>
                </div>

                <button type="submit" class="btn btn-teacher">
                    <i class="fas fa-door-open"></i> Open Secure Section
                </button>
            </form>
        </div>

        <!-- ===== ADMIN FORM ===== -->
        <div id="admin-form" class="hidden">
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="admin_login">

                <div class="input-group">
                    <label>Admin Username</label>
                    <input type="text" name="admin_username" placeholder="Enter admin username"
                           value="<?= htmlspecialchars($_POST['admin_username'] ?? '') ?>">
                </div>

                <div class="input-group">
                    <label>Admin Password</label>
                    <input type="password" name="admin_password" id="adm-pass" placeholder="••••••••">
                    <i class="fas fa-eye toggle-eye" onclick="togglePass('adm-pass', this)"></i>
                </div>

                <button type="submit" class="btn btn-admin">
                    <i class="fas fa-sign-in-alt"></i> Admin Login
                </button>
            </form>
        </div>

    </div>
</div>

<script>
    // Determine which tab was active on page load (after POST error)
    const lastAction = '<?= htmlspecialchars($_POST['action'] ?? '') ?>';
    const hasNewUserError = <?= ($error === 'NEW_USER_NEEDS_NAME') ? 'true' : 'false' ?>;

    function setMode(m) {
        document.getElementById('student-form').classList.toggle('hidden', m !== 'student');
        document.getElementById('teacher-form').classList.toggle('hidden', m !== 'teacher');
        document.getElementById('admin-form').classList.toggle('hidden', m !== 'admin');

        document.getElementById('std-tab').className = 'tab-btn ' + (m === 'student' ? 'btn-primary' : 'btn-secondary');
        document.getElementById('tch-tab').className = 'tab-btn ' + (m === 'teacher' ? 'btn-teacher' : 'btn-secondary');
        document.getElementById('adm-tab').className = 'tab-btn ' + (m === 'admin' ? 'btn-admin' : 'btn-secondary');
    }

    function togglePass(id, icon) {
        const el = document.getElementById(id);
        el.type = el.type === 'password' ? 'text' : 'password';
        icon.classList.toggle('fa-eye');
        icon.classList.toggle('fa-eye-slash');
    }

    // Show registration fields if new user
    if (hasNewUserError) {
        document.getElementById('reg-fields').classList.remove('hidden');
    }

    // Restore active tab after POST
    if (lastAction === 'teacher_login') setMode('teacher');
    else if (lastAction === 'admin_login') setMode('admin');
    else setMode('student');

    // Auto-uppercase student ID
    const sidInput = document.getElementById('std-login-id');
    if (sidInput) {
        sidInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }
</script>
</body>
</html>
