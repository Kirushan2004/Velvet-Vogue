<?php
session_start();
require_once '../db.php';

/*
    If already logged in as admin, go straight to dashboard
*/
if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

/* Remember me: load saved email from cookie (if any) */
$saved_email  = $_COOKIE['admin_email'] ?? '';
$email_value  = $saved_email;
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember_me']);

    // Use submitted email in the form after submit
    $email_value = $email;

    if ($email === '' || $password === '') {
        $error_message = 'Please enter both email and password.';
    } else {
        $sql = "SELECT id, full_name, email, password_hash, is_active 
                FROM admins 
                WHERE email = ? 
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();
            $stmt->close();

            if (!$admin) {
                $error_message = 'Invalid email or password.';
            } else {
                if ((int)$admin['is_active'] !== 1) {
                    $error_message = 'Your admin account is inactive. Please contact system owner.';
                } else {
                    if (password_verify($password, $admin['password_hash'])) {
                        // Login success
                        $_SESSION['admin_id']    = (int)$admin['id'];
                        $_SESSION['admin_name']  = $admin['full_name'];
                        $_SESSION['admin_email'] = $admin['email'];

                        // Clear any customer session
                        unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['customer_email']);

                        // Handle "Remember me" -> store email in cookie for 30 days
                        if ($remember) {
                            setcookie('admin_email', $email, time() + (86400 * 30), "/"); // 30 days
                        } else {
                            // Clear cookie if unchecked
                            setcookie('admin_email', '', time() - 3600, "/");
                        }

                        header('Location: dashboard.php');
                        exit;
                    } else {
                        $error_message = 'Invalid email or password.';
                    }
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
    <title>Admin Login | Velvet Vogue</title>

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
    <!-- Admin login specific CSS -->
    <link rel="stylesheet" href="../css/admin-login.css">
</head>
<body>

<div class="admin-login-wrapper">
    <div class="mb-3">
        <a href="../index.php" class="back-to-site">
            <i class='bx bx-arrow-back'></i> Back to store
        </a>
    </div>

    <div class="admin-login-card">
        <div class="admin-login-title-row mb-3">
            <div>
                <div class="admin-login-logo">
                    Velvet <span>Vogue</span>
                </div>
                <p class="admin-login-subtitle mb-0">Admin Panel · Sign in to continue</p>
            </div>
            <div class="admin-login-icon">
                <i class='bx bxs-lock-alt'></i>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger py-2 px-3 small">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="off" class="mt-3">
            <div class="mb-3">
                <label for="email" class="form-label">Admin Email</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class='bx bxs-user'></i>
                    </span>
                    <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        placeholder="admin@velvetvogue.com"
                        value="<?php echo htmlspecialchars($email_value); ?>"
                        required
                    >
                </div>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class='bx bxs-key'></i>
                    </span>
                    <input
                        type="password"
                        class="form-control"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        required
                    >
                    <span class="input-group-text toggle-password" id="togglePassword">
                        <i class='bx bx-show'></i>
                    </span>
                </div>
            </div>

            <div class="mb-3 d-flex justify-content-between align-items-center">
                <div class="form-check small">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        value="1"
                        id="rememberMe"
                        name="remember_me"
                        <?php echo $saved_email ? 'checked' : ''; ?>
                    >
                    <label class="form-check-label" for="rememberMe">
                        Remember me
                    </label>
                </div>
                <a href="forgot-password.php" class="small text-muted text-decoration-none forgot-link">
                    Forgot password?
                </a>
            </div>

            <div class="d-grid mb-3">
                <button type="submit" class="btn btn-primary rounded-pill">
                    <i class='bx bxs-log-in-circle me-1'></i>
                    Sign in as Admin
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

<!-- Simple JS: show/hide password -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);

                const icon = this.querySelector('i');
                if (icon) {
                    icon.classList.toggle('bx-show');
                    icon.classList.toggle('bx-hide');
                }
            });
        }
    });
</script>
</body>
</html>
