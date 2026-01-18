<?php
// customer-logout.php – Customer logout (clean + safe + no cache) + optional redirect support
session_start();

/**
 * Optional: support redirect after logout
 * Example: customer-logout.php?redirect=customer-login.php
 */
$redirect = $_GET['redirect'] ?? 'index.php';
$redirect = trim($redirect);

// Basic safety: only allow local redirects (no http/https)
if ($redirect === '' || preg_match('/^\s*https?:\/\//i', $redirect)) {
    $redirect = 'index.php';
}

// Prevent cached “back button shows logged in” issue
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Clear only customer session keys
unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['customer_email']);

// If you use other customer-only session flags, clear them too (optional)
unset($_SESSION['customer_login_notice'], $_SESSION['customer_orders_flash']);

// Optional: clear customer-specific cookie(s)
setcookie('cust_email', '', time() - 3600, "/");

// Optional: regenerate session ID for safety
session_regenerate_id(true);

// Go to redirect (or home)
header('Location: ' . $redirect);
exit;
?>
