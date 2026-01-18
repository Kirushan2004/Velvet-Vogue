<?php
session_start();
require_once '../db.php';

// If already logged in as admin, go to dashboard
if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        // Look up admin by email
        $sql = "SELECT id, email FROM admins WHERE email = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin  = $result->fetch_assoc();
            $stmt->close();

            if (!$admin) {
                // For security, we show a generic message
                $success_message = 'If this email is registered, a reset link has been generated.';
            } else {
                // Generate secure token
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // +1 hour

                $updateSql = "UPDATE admins 
                              SET reset_token = ?, reset_expires = ? 
                              WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                if ($updateStmt) {
                    $adminId = (int)$admin['id'];
                    $updateStmt->bind_param('ssi', $token, $expires, $adminId);
                    if ($updateStmt->execute()) {
                        // In real app: send email with this link.
                        // For local dev, show link on screen:
                        $resetLink = 'http://localhost/Velvet_Vogue/admin/reset-password.php?token=' . urlencode($token);

                        $success_message = 'If this email is registered, a reset link has been generated. 
                        For development, use this link: <br><code>' . htmlspecialchars($resetLink) . '</code>';
                        $error_message   = '';
                    } else {
                        $error_message = 'Unable to generate reset link. Please try again later.';
                    }
                    $updateStmt->close();
                } else {
                    $error_message = 'Something went wrong. Please try again later.';
                }
            }
        } else {
            $error_message = 'Something went wrong. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Forgot Password | Velvet Vogue</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Boxicons -->
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <!-- Main site CSS -->
    <link rel="stylesheet" href="../css/style.css">
    <!-- Admin login/auth specific CSS -->
    <link rel="stylesheet" href="../css/admin-login.css">
</head>
<body>

<div class="admin-login-wrapper">
    <div class="mb-3">
        <a href="login.php" class="back-to-site">
            <i class='bx bx-arrow-back'></i> Back to login
        </a>
    </div>

    <div class="admin-login-card">
        <div class="admin-login-title-row mb-3">
            <div>
                <div class="admin-login-logo">
                    Velvet <span>Vogue</span>
                </div>
                <p class="admin-login-subtitle mb-0">Admin Panel · Forgot password</p>
            </div>
            <div class="admin-login-icon">
                <i class='bx bx-help-circle'></i>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger py-2 px-3 small">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success py-2 px-3 small">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="forgot-password.php" autocomplete="off" class="mt-3">
            <div class="mb-3">
                <label for="email" class="form-label">Admin Email</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class='bx bxs-envelope'></i>
                    </span>
                    <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        placeholder="admin@velvetvogue.com"
                        required
                    >
                </div>
            </div>

            <p class="small text-muted mb-3">
                Enter the email associated with your admin account. If it exists, we’ll generate a password reset link.
            </p>

            <div class="d-grid mb-3">
                <button type="submit" class="btn btn-primary rounded-pill">
                    <i class='bx bx-link-alt me-1'></i>
                    Generate reset link
                </button>
            </div>

            <div class="admin-login-footer-links d-flex justify-content-between">
                <span class="text-muted">Velvet Vogue Admin</span>
                <a href="../contact.php">
                    <i class='bx bx-support me-1'></i> Need help?
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
