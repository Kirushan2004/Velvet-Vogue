<?php
// customer-login.php – Modern + responsive customer login (safe redirect + working show/hide + forgot password link)
session_start();
require_once 'db.php';

/*
    If already logged in as customer, send to redirect or home.
*/
$redirect = trim($_GET['redirect'] ?? '');
$msg_code = trim($_GET['msg'] ?? '');

if (!empty($_SESSION['customer_id'])) {
    if ($redirect !== '' && preg_match('/^[a-zA-Z0-9_\-\/\.?&=]+$/', $redirect)) {
        header('Location: ' . $redirect);
    } else {
        header('Location: index.php');
    }
    exit;
}

/* Remember me: store email in cookie */
$saved_email   = $_COOKIE['cust_email'] ?? '';
$email_value   = $saved_email;
$error_message = '';
$info_message  = '';

if ($msg_code === 'login_required') {
    $info_message = 'You need to be logged in to continue.';
}
if ($msg_code === 'pw_reset_success') {
    $info_message = 'Your password has been updated. Please sign in.';
}
function clean($v) {
    return trim($v ?? '');
}

// Same safe redirect rule used in other pages
function safe_local_redirect(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    // disallow full URLs
    if (preg_match('/^\s*https?:\/\//i', $path)) return '';
    // allow simple local paths + querystring
    if (!preg_match('/^[a-zA-Z0-9_\-\/\.?&=]+$/', $path)) return '';
    return $path;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = clean($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember_me']);
    $redirect = clean($_POST['redirect'] ?? $redirect);

    $email_value = $email;

    if ($email === '' || $password === '') {
        $error_message = 'Please enter both email and password.';
    } else {
        $sql = "SELECT id, full_name, email, password_hash, is_active
                FROM customers
                WHERE email = ?
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result   = $stmt->get_result();
            $customer = $result->fetch_assoc();
            $stmt->close();

            if (!$customer) {
                $error_message = 'Invalid email or password.';
            } else {
                if ((int)$customer['is_active'] !== 1) {
                    $error_message = 'Your account is inactive. Please contact support.';
                } else {
                    if (password_verify($password, $customer['password_hash'])) {
                        // Login success
                        $_SESSION['customer_id']    = (int)$customer['id'];
                        $_SESSION['customer_name']  = $customer['full_name'];
                        $_SESSION['customer_email'] = $customer['email'];

                        // clear any admin session
                        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_email']);

                        // Remember email
                        if ($remember) {
                            setcookie('cust_email', $email, time() + (86400 * 30), "/");
                        } else {
                            setcookie('cust_email', '', time() - 3600, "/");
                        }

                        $safe_redirect = safe_local_redirect($redirect);

                        if ($safe_redirect !== '') {
                            header('Location: ' . $safe_redirect);
                        } else {
                            header('Location: index.php');
                        }
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

// For “Forgot password” link keep the redirect (optional)
$forgotLink = 'customer-forgot-password.php';
$safeRedirectForLinks = safe_local_redirect($redirect);
if ($safeRedirectForLinks !== '') {
    $forgotLink .= '?redirect=' . urlencode($safeRedirectForLinks);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login | Velvet Vogue</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Boxicons -->
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <!-- Main site CSS -->
    <link rel="stylesheet" href="css/style.css">
    <!-- Reuse admin-login style -->
    <link rel="stylesheet" href="css/admin-login.css">

    <style>
        /* Make the toggle span actually clickable and aligned */
        .toggle-password {
            cursor: pointer;
            user-select: none;
        }
        .toggle-password:active {
            transform: translateY(0.5px);
        }
        /* On very small screens, keep spacing tight */
        @media (max-width: 420px) {
            .admin-login-card { padding: 1rem; }
        }
    </style>
</head>
<body>

<div class="admin-login-wrapper">
    <div class="mb-3">
        <a href="index.php" class="back-to-site">
            <i class='bx bx-arrow-back'></i> Back to store
        </a>
    </div>

    <div class="admin-login-card">
        <div class="admin-login-title-row mb-3">
            <div>
                <div class="admin-login-logo">
                    Velvet <span>Vogue</span>
                </div>
                <p class="admin-login-subtitle mb-0">Customer · Sign in to continue</p>
            </div>
            <div class="admin-login-icon">
                <i class='bx bx-user'></i>
            </div>
        </div>

        <?php if ($info_message): ?>
            <div class="alert alert-info py-2 px-3 small mb-2">
                <?php echo htmlspecialchars($info_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger py-2 px-3 small">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="customer-login.php" autocomplete="off" class="mt-3">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">

            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class='bx bxs-envelope'></i>
                    </span>
                    <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        placeholder="you@example.com"
                        value="<?php echo htmlspecialchars($email_value); ?>"
                        required
                    >
                </div>
            </div>

            <div class="mb-2">
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
                    <!-- KEEP the same element you already have (no extra eye icons) -->
                    <span class="input-group-text toggle-password" id="togglePassword" role="button" aria-label="Show password" tabindex="0">
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

                <!-- Forgot password (real link now) -->
                <a class="small text-decoration-none" href="customer-forgot-password.php<?php echo $redirect ? '?redirect=' . urlencode($redirect) : ''; ?>">
                Forgot password?
                </a>
            </div>

            <div class="d-grid mb-2">
                <button type="submit" class="btn btn-primary rounded-pill">
                    <i class='bx bxs-log-in-circle me-1'></i>
                    Sign in
                </button>
            </div>

            <div class="mb-2 text-center small">
                Don’t have an account?
                <a href="customer-register.php<?php echo $safeRedirectForLinks ? '?redirect=' . urlencode($safeRedirectForLinks) : ''; ?>"
                   class="text-decoration-none">
                    Create one
                </a>
            </div>

            <div class="admin-login-footer-links d-flex justify-content-between">
                <span class="text-muted">Velvet Vogue Customer</span>
                <a href="contactsupport.php">
                    <i class='bx bx-support me-1'></i> Need help?
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- JS: show/hide password (works reliably + keyboard accessible) -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('togglePassword');
    const input  = document.getElementById('password');

    function flip() {
        if (!toggle || !input) return;

        const isHidden = input.getAttribute('type') === 'password';
        input.setAttribute('type', isHidden ? 'text' : 'password');

        const icon = toggle.querySelector('i');
        if (icon) {
            icon.classList.toggle('bx-show', !isHidden);
            icon.classList.toggle('bx-hide', isHidden);
        }

        toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    }

    if (toggle && input) {
        toggle.addEventListener('click', flip);
        // keyboard support
        toggle.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                flip();
            }
        });
    }
});
</script>
</body>
</html>
