<?php
session_start();
require_once '../db.php';

$token = $_GET['token'] ?? '';
$token = trim($token);

$valid_token   = false;
$error_message = '';
$success_message = '';
$admin_id = null;

// Step 1: Validate token on initial load (or from POST hidden field)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($token === '') {
        $error_message = 'Missing reset token.';
    } else {
        $sql = "SELECT id, reset_expires FROM admins 
                WHERE reset_token = ? 
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin  = $result->fetch_assoc();
            $stmt->close();

            if (!$admin) {
                $error_message = 'Invalid or expired reset link.';
            } else {
                $expiresTs = strtotime($admin['reset_expires']);
                if ($expiresTs !== false && $expiresTs > time()) {
                    $valid_token = true;
                    $admin_id    = (int)$admin['id'];
                } else {
                    $error_message = 'This reset link has expired. Please request a new one.';
                }
            }
        } else {
            $error_message = 'Something went wrong. Please try again later.';
        }
    }
}

// Step 2: Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token      = trim($_POST['token'] ?? '');
    $new_pass   = $_POST['new_password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';

    if ($token === '') {
        $error_message = 'Missing reset token.';
    } else {
        if ($new_pass === '' || $confirm === '') {
            $error_message = 'Please fill in both password fields.';
        } elseif ($new_pass !== $confirm) {
            $error_message = 'Passwords do not match.';
        } elseif (strlen($new_pass) < 6) {
            $error_message = 'Password should be at least 6 characters long.';
        } else {
            // Check token validity again
            $sql = "SELECT id, reset_expires FROM admins 
                    WHERE reset_token = ? 
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $token);
                $stmt->execute();
                $result = $stmt->get_result();
                $admin  = $result->fetch_assoc();
                $stmt->close();

                if (!$admin) {
                    $error_message = 'Invalid or expired reset link.';
                } else {
                    $expiresTs = strtotime($admin['reset_expires']);
                    if ($expiresTs !== false && $expiresTs > time()) {
                        $admin_id = (int)$admin['id'];

                        // Update password
                        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                        $updateSql = "UPDATE admins 
                                      SET password_hash = ?, reset_token = NULL, reset_expires = NULL
                                      WHERE id = ?";
                        $updateStmt = $conn->prepare($updateSql);
                        if ($updateStmt) {
                            $updateStmt->bind_param('si', $hash, $admin_id);
                            if ($updateStmt->execute()) {
                                $success_message = 'Your password has been reset successfully. You can now log in.';
                                $error_message   = '';
                            } else {
                                $error_message = 'Unable to reset password. Please try again later.';
                            }
                            $updateStmt->close();
                        } else {
                            $error_message = 'Unable to reset password. Please try again later.';
                        }

                    } else {
                        $error_message = 'This reset link has expired. Please request a new one.';
                    }
                }
            } else {
                $error_message = 'Something went wrong. Please try again later.';
            }
        }
    }
}

// If password reset succeeded, we no longer care about valid_token
if ($success_message !== '') {
    $valid_token = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Reset Password | Velvet Vogue</title>

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
    <!-- Admin login/auth CSS -->
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
                <p class="admin-login-subtitle mb-0">Admin Panel · Reset password</p>
            </div>
            <div class="admin-login-icon">
                <i class='bx bx-reset'></i>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger py-2 px-3 small">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success py-2 px-3 small">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <div class="d-grid mt-3">
                <a href="login.php" class="btn btn-primary rounded-pill">
                    <i class='bx bxs-log-in-circle me-1'></i> Go to login
                </a>
            </div>
        <?php elseif ($valid_token): ?>
            <form method="post" action="reset-password.php" autocomplete="off" class="mt-3">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class='bx bxs-key'></i>
                        </span>
                        <input
                            type="password"
                            class="form-control"
                            id="new_password"
                            name="new_password"
                            placeholder="Enter new password"
                            required
                        >
                    </div>
                </div>

                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class='bx bxs-key'></i>
                        </span>
                        <input
                            type="password"
                            class="form-control"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Re-enter new password"
                            required
                        >
                    </div>
                </div>

                <p class="small text-muted mb-3">
                    Your new password should be at least 6 characters long. For security, avoid using old or common passwords.
                </p>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary rounded-pill">
                        <i class='bx bx-reset me-1'></i>
                        Reset password
                    </button>
                </div>

                <div class="admin-login-footer-links d-flex justify-content-between">
                    <span class="text-muted">Velvet Vogue Admin</span>
                    <a href="../contact.php">
                        <i class='bx bx-support me-1'></i> Need help?
                    </a>
                </div>
            </form>
        <?php else: ?>
            <!-- No valid token + no success; nothing to show except error above -->
            <p class="small text-muted mb-3">
                If you reached this page by mistake, you can request a new link from the forgot password page.
            </p>
            <div class="d-grid">
                <a href="forgot-password.php" class="btn btn-outline-primary rounded-pill">
                    <i class='bx bx-link-alt me-1'></i> Request new reset link
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
