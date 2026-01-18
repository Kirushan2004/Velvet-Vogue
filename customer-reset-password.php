<?php
session_start();
require_once 'db.php';

$customerId = (int)($_SESSION['pw_reset_customer_id'] ?? 0);
$okUntil    = (int)($_SESSION['pw_reset_ok_until'] ?? 0);
$redirect   = (string)($_SESSION['pw_reset_redirect'] ?? '');

if ($customerId <= 0 || $okUntil <= 0 || time() > $okUntil) {
    // expired / not verified
    unset($_SESSION['pw_reset_customer_id'], $_SESSION['pw_reset_ok_until'], $_SESSION['pw_reset_redirect']);
    header('Location: customer-forgot-password.php?msg=expired');
    exit;
}

$errors = [];
$success = '';

function clean($v) { return trim($v ?? ''); }
function safe_local_redirect(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('/^\s*https?:\/\//i', $path)) return '';
    if (!preg_match('/^[a-zA-Z0-9_\-\/\.?&=]+$/', $path)) return '';
    return $path;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = $_POST['password'] ?? '';
    $p2 = $_POST['password_confirm'] ?? '';

    if ($p1 === '' || $p2 === '') {
        $errors[] = 'Please enter and confirm your new password.';
    } elseif ($p1 !== $p2) {
        $errors[] = 'Passwords do not match.';
    } elseif (strlen($p1) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if (empty($errors)) {
        $hash = password_hash($p1, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE customers SET password_hash = ? WHERE id = ? LIMIT 1");
        if (!$stmt) {
            $errors[] = 'Something went wrong. Please try again.';
        } else {
            $stmt->bind_param('si', $hash, $customerId);
            if ($stmt->execute()) {
                // clear reset session
                unset($_SESSION['pw_reset_customer_id'], $_SESSION['pw_reset_ok_until'], $_SESSION['pw_reset_redirect']);

                // OPTIONAL: clear any login session
                unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['customer_email']);

                $safe = safe_local_redirect($redirect);
                // Send to login page with message
                $to = 'customer-login.php?msg=pw_reset_success';
                if ($safe !== '') {
                    $to .= '&redirect=' . urlencode($safe);
                }
                header('Location: ' . $to);
                exit;
            } else {
                $errors[] = 'Could not update password. Please try again.';
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password | Velvet Vogue</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/admin-login.css">
</head>
<body>

<div class="admin-login-wrapper">
    <div class="mb-3">
        <a href="customer-forgot-password.php" class="back-to-site">
            <i class='bx bx-arrow-back'></i> Back
        </a>
    </div>

    <div class="admin-login-card">
        <div class="admin-login-title-row mb-3">
            <div>
                <div class="admin-login-logo">
                    Velvet <span>Vogue</span>
                </div>
                <p class="admin-login-subtitle mb-0">Customer · Choose a new password</p>
            </div>
            <div class="admin-login-icon">
                <i class='bx bx-shield-quarter'></i>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger py-2 px-3 small">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="mt-3" autocomplete="off">
            <div class="mb-3">
                <label class="form-label">New password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class='bx bxs-key'></i></span>
                    <input type="password" class="form-control" name="password" placeholder="At least 6 characters" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Confirm new password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class='bx bxs-key'></i></span>
                    <input type="password" class="form-control" name="password_confirm" required>
                </div>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-primary rounded-pill">
                    Update password
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
