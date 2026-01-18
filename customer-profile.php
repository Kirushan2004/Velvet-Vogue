<?php
// customer-profile.php – Customer account profile & details (Modern + responsive + mobile menu + image fallback)
session_start();
require_once 'db.php';

// -------------------- require customer login --------------------
if (empty($_SESSION['customer_id'])) {
    header('Location: customer-login.php?redirect=customer-profile.php');
    exit;
}

$customer_id    = (int)($_SESSION['customer_id'] ?? 0);
$customer_name  = $_SESSION['customer_name']  ?? '';
$customer_email = $_SESSION['customer_email'] ?? '';

// helper: format date to input
function safe_date_for_input($dateStr) {
    if (!$dateStr) return '';
    if ($dateStr === '0000-00-00') return '';
    return $dateStr;
}

// load customer from DB
$sql = "SELECT id, full_name, email, password_hash, phone, gender, date_of_birth,
               address_line1, address_line2, city, state, postal_code, country,
               profile_photo, is_active
        FROM customers
        WHERE id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Database error.');
}
$stmt->bind_param('i', $customer_id);
$stmt->execute();
$res       = $stmt->get_result();
$customer  = $res->fetch_assoc();
$stmt->close();

// if no customer found -> force logout
if (!$customer) {
    unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['customer_email']);
    header('Location: customer-login.php');
    exit;
}

// ensure active
if ((int)$customer['is_active'] !== 1) {
    unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['customer_email']);
    header('Location: customer-login.php?inactive=1');
    exit;
}

// pre-fill form values
$full_name      = $customer['full_name'] ?? '';
$email          = $customer['email'] ?? '';
$phone          = $customer['phone'] ?? '';
$gender         = $customer['gender'] ?? '';
$date_of_birth  = safe_date_for_input($customer['date_of_birth'] ?? '');
$address1       = $customer['address_line1'] ?? '';
$address2       = $customer['address_line2'] ?? '';
$city           = $customer['city'] ?? '';
$state          = $customer['state'] ?? '';
$postal_code    = $customer['postal_code'] ?? '';
$country        = $customer['country'] ?? '';
$profile_photo  = $customer['profile_photo'] ?? ''; // path like uploads/customers/xxx.jpg

$error_message   = '';
$success_message = '';

// uploads directory for profile photos
$upload_rel_dir = 'uploads/customers/';
$upload_abs_dir = __DIR__ . '/' . $upload_rel_dir;
if (!is_dir($upload_abs_dir)) {
    @mkdir($upload_abs_dir, 0777, true);
}

// -------------------- handle POST (update profile) --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // basic details
    $full_name     = trim($_POST['full_name'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $gender        = trim($_POST['gender'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $address1      = trim($_POST['address_line1'] ?? '');
    $address2      = trim($_POST['address_line2'] ?? '');
    $city          = trim($_POST['city'] ?? '');
    $state         = trim($_POST['state'] ?? '');
    $postal_code   = trim($_POST['postal_code'] ?? '');
    $country       = trim($_POST['country'] ?? '');

    // password section (optional)
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $errors = [];

    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    }
    if ($phone === '') {
        $errors[] = 'Phone number is required.';
    }
    if ($address1 === '' || $city === '' || $state === '' || $postal_code === '' || $country === '') {
        $errors[] = 'Please complete your shipping address (address, city, state, postal code, country).';
    }

    // gender normalization (optional)
    $allowed_gender = ['male', 'female', 'other', ''];
    if (!in_array(strtolower($gender), $allowed_gender, true)) {
        $gender = '';
    }

    // date of birth validation
    if ($date_of_birth !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $date_of_birth);
        if (!$d || $d->format('Y-m-d') !== $date_of_birth) {
            $errors[] = 'Invalid date of birth format.';
        }
    }

    // ---------------- profile photo upload (optional) ----------------
    if (!empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $tmpName  = $_FILES['profile_photo']['tmp_name'];
        $origName = $_FILES['profile_photo']['name'];

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowed_ext = ['jpg','jpeg','png','gif','webp'];
        if (!in_array($ext, $allowed_ext, true)) {
            $errors[] = 'Profile photo must be an image (jpg, jpeg, png, gif, webp).';
        } else {
            $newFileName = 'cust_' . $customer_id . '_' . time() . '.' . $ext;
            $destAbs = $upload_abs_dir . $newFileName;
            $destRel = $upload_rel_dir . $newFileName;

            if (move_uploaded_file($tmpName, $destAbs)) {
                if ($profile_photo && file_exists(__DIR__ . '/' . $profile_photo)) {
                    @unlink(__DIR__ . '/' . $profile_photo);
                }
                $profile_photo = $destRel;
            } else {
                $errors[] = 'Failed to upload profile photo.';
            }
        }
    }

    // handle password change only if any password field filled
    $update_password_hash = false;
    $new_hash             = null;

    if ($new_password !== '' || $confirm_password !== '' || $current_password !== '') {
        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            $errors[] = 'To change your password, fill in current, new, and confirm password fields.';
        } else {
            if ($new_password !== $confirm_password) {
                $errors[] = 'New password and confirm password do not match.';
            }
            if (strlen($new_password) < 6) {
                $errors[] = 'New password should be at least 6 characters.';
            }
            if (!password_verify($current_password, $customer['password_hash'])) {
                $errors[] = 'Current password is incorrect.';
            }

            if (empty($errors)) {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_password_hash = true;
            }
        }
    }

    if (!empty($errors)) {
        $error_message = implode(' ', $errors);
    } else {
        if ($update_password_hash) {
            $sql = "UPDATE customers
                    SET full_name = ?, phone = ?, gender = ?, date_of_birth = ?,
                        address_line1 = ?, address_line2 = ?, city = ?, state = ?,
                        postal_code = ?, country = ?, profile_photo = ?, password_hash = ?
                    WHERE id = ?
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param(
                    'ssssssssssssi',
                    $full_name,
                    $phone,
                    $gender,
                    $date_of_birth,
                    $address1,
                    $address2,
                    $city,
                    $state,
                    $postal_code,
                    $country,
                    $profile_photo,
                    $new_hash,
                    $customer_id
                );
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $sql = "UPDATE customers
                    SET full_name = ?, phone = ?, gender = ?, date_of_birth = ?,
                        address_line1 = ?, address_line2 = ?, city = ?, state = ?,
                        postal_code = ?, country = ?, profile_photo = ?
                    WHERE id = ?
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param(
                    'sssssssssssi',
                    $full_name,
                    $phone,
                    $gender,
                    $date_of_birth,
                    $address1,
                    $address2,
                    $city,
                    $state,
                    $postal_code,
                    $country,
                    $profile_photo,
                    $customer_id
                );
                $stmt->execute();
                $stmt->close();
            }
        }

        $_SESSION['customer_name'] = $full_name;
        $success_message = 'Your profile has been updated successfully.';
    }
}

// ===== Header counts (wishlist + cart) =====
$wishlist_ids   = (isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])) ? $_SESSION['wishlist'] : [];
$wishlist_count = count($wishlist_ids);

// IMPORTANT: cart bubble should show PRODUCT COUNT (distinct cart lines), NOT quantity sum
$cart = (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? $_SESSION['cart'] : [];
$cart_items = count($cart);

// first name for pill
$display_name = $full_name !== '' ? $full_name : $customer_name;
$first_name   = $display_name;
if ($display_name !== '') {
    $parts = preg_split('/\s+/', trim($display_name));
    $first_name = $parts[0] ?? $display_name;
}

// initial letter for avatar
$avatar_initial = strtoupper(substr($first_name !== '' ? $first_name : 'V', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My profile | Velvet Vogue</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Boxicons -->
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <!-- Main site CSS -->
    <link rel="stylesheet" href="css/style.css">

    <!-- Image fallback logic (same style as other pages) -->
    <script>
      function vvSvgPlaceholderDataUri(text) {
        const safe = String(text || 'Image unavailable').slice(0, 40);
        const svg =
          `<svg xmlns="http://www.w3.org/2000/svg" width="800" height="520" viewBox="0 0 800 520">
            <rect width="800" height="520" fill="#f4f3fb"/>
            <g opacity="0.9">
              <path d="M255 320l85-90 70 75 45-50 90 95H255z" fill="#d9d3ea"/>
              <circle cx="315" cy="210" r="28" fill="#d9d3ea"/>
            </g>
            <text x="50%" y="72%" text-anchor="middle" font-family="Poppins, Arial, sans-serif"
                  font-size="22" fill="#b7b0c9">` + safe.replace(/</g,'').replace(/>/g,'') + `</text>
          </svg>`;
        return "data:image/svg+xml;charset=UTF-8," + encodeURIComponent(svg);
      }

      function vvImgFallback(img) {
        if (!img || img.dataset.fallbackApplied === "1") return;
        img.dataset.fallbackApplied = "1";
        const label = img.getAttribute("data-fallback-text") || "Image unavailable";
        img.src = vvSvgPlaceholderDataUri(label);
        img.classList.add("vv-img-fallback");
      }
    </script>

    <style>
        *{ box-sizing:border-box; }
        img{ max-width:100%; height:auto; display:block; }

        /* ===== Primary button hover fix (text never disappears) ===== */
        .vv-btn-primary,
        .vv-btn.vv-btn-primary{
            position:relative;
            overflow:hidden;
            color:#fff !important;
            text-decoration:none;
            transition: transform 160ms ease, box-shadow 160ms ease, background-color 160ms ease, border-color 160ms ease, color 160ms ease;
        }
        .vv-btn-primary:hover,
        .vv-btn.vv-btn-primary:hover,
        .vv-btn-primary:focus,
        .vv-btn.vv-btn-primary:focus{
            color:#fff !important;
            background-color: var(--vv-accent) !important;
            border-color: var(--vv-accent) !important;
            box-shadow: 0 10px 26px rgba(25,12,64,0.18) !important;
            transform: translateY(-1px);
        }
        .vv-btn-primary:active,
        .vv-btn.vv-btn-primary:active{
            transform: translateY(0);
            box-shadow: 0 6px 18px rgba(25,12,64,0.16) !important;
        }

        /* ===== Header: mobile menu LEFT (same behaviour as cart page) ===== */
        .vv-nav-wrapper{ position:relative; }

        .vv-nav-toggle{
            display:none;
            border:1px solid var(--vv-border-soft);
            background:#fff;
            border-radius:12px;
            padding:0.45rem 0.6rem;
            cursor:pointer;
            line-height:1;
        }
        .vv-mobile-nav{ display:none; }

        @media (max-width: 991.98px){
            .vv-nav{ display:none; }

            .vv-nav-wrapper{
                display:flex;
                align-items:center;
                gap:0.6rem;
            }
            .vv-nav-toggle{
                display:inline-flex;
                align-items:center;
                justify-content:center;
                order:0;
            }
            .vv-logo{
                order:1;
                margin-right:auto;
            }
            .vv-nav-actions{
                order:2;
                display:flex;
                align-items:center;
                gap:0.6rem;
                flex-wrap:wrap;
                justify-content:flex-end;
            }

            .vv-mobile-nav{
                display:block;
                position:absolute;
                left:0.75rem;
                top:calc(100% + 0.75rem);
                width:min(360px, calc(100% - 1.5rem));
                background:#fff;
                border:1px solid var(--vv-border-soft);
                border-radius:18px;
                box-shadow: var(--vv-shadow-subtle);
                padding:0.6rem;
                z-index:40;
                transform: translateY(-8px);
                opacity:0;
                pointer-events:none;
                transition:160ms ease;
            }
            body.vv-nav-open .vv-mobile-nav{
                transform: translateY(0);
                opacity:1;
                pointer-events:auto;
            }
            .vv-mobile-nav a{
                display:block;
                padding:0.6rem 0.8rem;
                border-radius:12px;
                text-decoration:none;
                color: var(--vv-text-main);
            }
            .vv-mobile-nav a:hover{
                background:#f7f0fa;
                color: var(--vv-accent);
            }
        }

        /* ===== Page padding ===== */
        .vv-account-main { padding: 1.8rem 0 2.2rem; }

        .vv-account-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) minmax(0, 1.2fr);
            gap: 1.5rem;
            align-items: flex-start;
        }
        .vv-account-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 1rem 1.1rem 1.1rem;
        }
        .vv-account-card h3 { font-size: 1rem; margin-bottom: 0.45rem; }
        .vv-account-card p.vv-account-sub {
            font-size: 0.8rem;
            color: var(--vv-text-soft);
            margin-bottom: 0.8rem;
        }

        .vv-account-fields-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.7rem 0.9rem;
        }
        .vv-account-field { font-size: 0.8rem; }
        .vv-account-field label {
            display: block;
            font-size: 0.78rem;
            margin-bottom: 0.15rem;
            color: var(--vv-text-soft);
        }
        .vv-account-field input,
        .vv-account-field select,
        .vv-account-field textarea {
            width: 100%;
            border-radius: 999px;
            border: 1px solid var(--vv-border-strong);
            padding: 0.45rem 0.9rem;
            font-size: 0.8rem;
            outline: none;
            background-color: #fff;
            line-height: 1.2;
        }

        .vv-account-field select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            padding-right: 2rem;
            background-image:
                linear-gradient(45deg, transparent 50%, #a49bb6 50%),
                linear-gradient(135deg, #a49bb6 50%, transparent 50%);
            background-position:
                calc(100% - 14px) 50%,
                calc(100% - 9px) 50%;
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
            cursor: pointer;
        }
        .vv-account-field textarea {
            border-radius: 14px;
            min-height: 70px;
            resize: vertical;
        }
        .vv-account-section-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.11em;
            color: var(--vv-text-soft);
            margin-top: 0.6rem;
            margin-bottom: 0.3rem;
        }
        .vv-account-divider { height: 1px; background: #eee3fb; margin: 0.7rem 0; }

        .vv-account-summary-list {
            list-style: none;
            padding-left: 0;
            margin: 0.4rem 0 0.7rem;
            font-size: 0.82rem;
            color: var(--vv-text-muted);
        }
        .vv-account-summary-list li {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            margin-bottom: 0.3rem;
        }
        .vv-account-summary-list i { color: var(--vv-accent); font-size: 1.1rem; }

        .vv-account-meta-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.7rem;
            border-radius: 999px;
            background: var(--vv-accent-soft);
            color: var(--vv-accent);
            font-size: 0.75rem;
            margin-top: 0.3rem;
        }
        .vv-account-small { font-size: 0.76rem; color: var(--vv-text-soft); }

        /* user menu dropdown */
        .vv-user-menu { position: relative; }
        .vv-user-menu .vv-user-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 0.4rem);
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.4rem 0;
            min-width: 180px;
            display: none;
            z-index: 60;
            font-size: 0.8rem;
        }
        .vv-user-menu.open .vv-user-dropdown { display: block; }
        .vv-user-dropdown a {
            display: flex;
            align-items: center;
            padding: 0.35rem 0.9rem;
            color: var(--vv-text-main);
            text-decoration: none;
        }
        .vv-user-dropdown a:hover { background: #f6f1ff; }
        .vv-user-dropdown-separator { height: 1px; background: #eee3fb; margin: 0.3rem 0; }

        /* profile avatar */
        .vv-account-avatar-wrap {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            margin-bottom: 0.8rem;
        }
        .vv-account-avatar {
            width: 72px;
            height: 72px;
            border-radius: 999px;
            background: #f2e8ff;
            border: 1px solid var(--vv-border-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            cursor: pointer;
            position: relative;
        }
        .vv-account-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .vv-account-avatar img.vv-img-fallback{
            object-fit: contain !important;
            background:#f4f3fb;
        }
        .vv-account-avatar-initial {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--vv-accent);
        }
        .vv-avatar-hint { font-size: 0.78rem; color: var(--vv-text-soft); }
        .vv-avatar-change-btn { margin-top: 0.2rem; }

        /* ===== Password "view" (use the EXISTING eye buttons you already have) =====
           This CSS just ensures the eye button area is clickable and not blocked.
           (No extra eye icons are added beyond the one per field.) */
        .vv-input-with-icon{ position: relative; }
        .vv-input-with-icon input{ padding-right: 2.6rem; }
        .vv-pass-toggle{
            position:absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 1px solid transparent;
            background: transparent;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            cursor:pointer;
            pointer-events:auto;
            z-index: 2;
        }
        .vv-pass-toggle:hover{
            background:#f7f0fa;
            border-color: var(--vv-border-soft);
        }
        .vv-pass-toggle i{ font-size: 1.15rem; color: var(--vv-text-soft); }
        .vv-pass-toggle:hover i{ color: var(--vv-accent); }

        /* photo modal */
        .vv-photo-modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 5, 26, 0.72);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 500;
        }
        .vv-photo-modal.open { display: flex; }
        .vv-photo-modal-inner {
            max-width: 90vw;
            max-height: 90vh;
            border-radius: 18px;
            overflow: hidden;
            background: #000;
            position: relative;
        }
        .vv-photo-modal-inner img {
            display: block;
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .vv-photo-modal-close {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 30px;
            height: 30px;
            border-radius: 999px;
            border: none;
            background: rgba(0,0,0,0.7);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        @media (max-width: 991.98px) {
            .vv-account-grid { grid-template-columns: minmax(0, 1fr); }
            /* password row becomes single column nicely */
            .vv-account-fields-row { grid-template-columns: 1fr; }
        }
        @media (min-width: 992px){
            /* keep 2-col on desktop for non-password sections */
            .vv-account-fields-row.vv-keep-2col { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>

<body class="vv-body">

<header class="vv-header">
    <div class="vv-container vv-nav-wrapper">

        <!-- Mobile toggle LEFT -->
        <button type="button" class="vv-nav-toggle" id="vvNavToggle" aria-expanded="false" aria-controls="vvMobileNav">
            <i class="bx bx-menu" style="font-size:1.3rem;"></i>
        </button>

        <a href="index.php" class="vv-logo" style="text-decoration:none;color:inherit;">
            <span class="vv-logo-main">Velvet</span>
            <span class="vv-logo-sub">Vogue</span>
        </a>

        <nav class="vv-nav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="index.php#about">About</a>
        </nav>

        <!-- Mobile nav -->
        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="index.php#about">About</a>
            <a href="wishlist.php">Wishlist</a>
            <a href="customer-orders.php">My orders</a>
        </div>

        <div class="vv-nav-actions">
            <!-- wishlist icon with counter -->
            <a href="wishlist.php" class="vv-icon-btn" aria-label="Wishlist">
                <i class="bx bx-heart"></i>
                <span class="vv-count-badge" id="wishlistCount"><?php echo (int)$wishlist_count; ?></span>
            </a>

            <!-- cart icon with counter (PRODUCT COUNT, not quantity sum) -->
            <a href="cart.php" class="vv-icon-btn" aria-label="Cart">
                <i class="bx bx-shopping-bag"></i>
                <span class="vv-count-badge" id="cartCount"><?php echo (int)$cart_items; ?></span>
            </a>

            <!-- customer pill / dropdown -->
            <div class="vv-user-menu">
                <button type="button" class="vv-pill-link vv-user-toggle">
                    <i class="bx bx-user-circle"></i>
                    <span><?php echo htmlspecialchars($first_name); ?></span>
                    <i class="bx bx-chevron-down vv-user-caret"></i>
                </button>
                <div class="vv-user-dropdown">
                    <a href="customer-profile.php">
                        <i class="bx bx-id-card" style="font-size:1rem;margin-right:0.25rem;"></i>
                        My profile
                    </a>
                    <a href="customer-orders.php">
                        <i class="bx bx-package" style="font-size:1rem;margin-right:0.25rem;"></i>
                        My orders
                    </a>
                    <a href="wishlist.php">
                        <i class="bx bx-heart" style="font-size:1rem;margin-right:0.25rem;"></i>
                        Wishlist
                    </a>
                    <div class="vv-user-dropdown-separator"></div>
                    <a href="customer-logout.php">
                        <i class="bx bx-log-out" style="font-size:1rem;margin-right:0.25rem;"></i>
                        Log out
                    </a>
                </div>
            </div>
        </div>

    </div>
</header>

<main class="vv-account-main">
    <div class="vv-container">
        <div class="vv-section-header">
            <div>
                <h2>My profile</h2>
                <p class="vv-section-sub">
                    Manage your personal details, photo, and shipping address for smoother checkout.
                </p>
            </div>
        </div>

        <div class="vv-account-grid">
            <!-- LEFT: profile form -->
            <div class="vv-account-card">
                <div class="vv-account-avatar-wrap">
                    <div class="vv-account-avatar" id="profileAvatar">
                        <?php if ($profile_photo): ?>
                            <img
                                src="<?php echo htmlspecialchars($profile_photo); ?>"
                                alt="Profile photo"
                                id="profilePhotoPreview"
                                data-fallback-text="Profile photo"
                                onerror="vvImgFallback(this)"
                            >
                        <?php else: ?>
                            <span class="vv-account-avatar-initial" id="profileAvatarInitial">
                                <?php echo htmlspecialchars($avatar_initial); ?>
                            </span>
                            <img
                                src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="
                                alt="Profile photo"
                                id="profilePhotoPreview"
                                style="display:none;"
                                data-fallback-text="Profile photo"
                                onerror="vvImgFallback(this)"
                            >
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="vv-avatar-hint">
                            This photo appears on your account and orders.
                        </div>
                        <button type="button"
                                class="vv-btn vv-btn-secondary vv-btn-sm vv-avatar-change-btn"
                                onclick="document.getElementById('profile_photo_input').click();">
                            <i class="bx bx-image-add"></i> Change photo
                        </button>
                        <input
                            type="file"
                            id="profile_photo_input"
                            name="profile_photo"
                            accept="image/*"
                            style="display:none;"
                            form="customerProfileForm"
                        >
                        <div class="vv-account-small" style="margin-top:0.2rem;">
                            JPG, PNG, GIF, WEBP – up to a few MB.
                        </div>
                    </div>
                </div>

                <h3>Account details</h3>
                <p class="vv-account-sub">
                    These details are used for order confirmations and delivery updates.
                </p>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger" style="padding:0.4rem 0.7rem;font-size:0.8rem;margin-bottom:0.7rem;border-radius:12px;border:1px solid #f5c2c7;background:#f8d7da;color:#842029;">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="alert alert-success" style="padding:0.4rem 0.7rem;font-size:0.8rem;margin-bottom:0.7rem;border-radius:12px;border:1px solid #badbcc;background:#d1e7dd;color:#0f5132;">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <form method="post" autocomplete="off" id="customerProfileForm" enctype="multipart/form-data">

                    <!-- keep 2 columns on desktop for these -->
                    <div class="vv-account-fields-row vv-keep-2col">
                        <div class="vv-account-field">
                            <label for="full_name">Full name</label>
                            <input type="text" id="full_name" name="full_name"
                                   value="<?php echo htmlspecialchars($full_name); ?>" required>
                        </div>

                        <div class="vv-account-field">
                            <label for="email">Email (login)</label>
                            <input type="email" id="email" value="<?php echo htmlspecialchars($email); ?>" disabled>
                        </div>

                        <div class="vv-account-field">
                            <label for="phone">Phone</label>
                            <input type="text" id="phone" name="phone"
                                   value="<?php echo htmlspecialchars($phone); ?>" required>
                        </div>

                        <div class="vv-account-field">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender">
                                <option value="">Prefer not to say</option>
                                <option value="female" <?php echo strtolower($gender) === 'female' ? 'selected' : ''; ?>>Female</option>
                                <option value="male"   <?php echo strtolower($gender) === 'male'   ? 'selected' : ''; ?>>Male</option>
                                <option value="other"  <?php echo strtolower($gender) === 'other'  ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="vv-account-field">
                            <label for="date_of_birth">Date of birth</label>
                            <input type="date" id="date_of_birth" name="date_of_birth"
                                   value="<?php echo htmlspecialchars($date_of_birth); ?>">
                        </div>
                    </div>

                    <div class="vv-account-section-title">Shipping address</div>

                    <!-- keep 2 columns on desktop for these -->
                    <div class="vv-account-fields-row vv-keep-2col">
                        <div class="vv-account-field">
                            <label for="address_line1">Address line 1</label>
                            <input type="text" id="address_line1" name="address_line1"
                                   value="<?php echo htmlspecialchars($address1); ?>" required>
                        </div>

                        <div class="vv-account-field">
                            <label for="address_line2">Address line 2 (optional)</label>
                            <input type="text" id="address_line2" name="address_line2"
                                   value="<?php echo htmlspecialchars($address2); ?>">
                        </div>

                        <div class="vv-account-field">
                            <label for="city">City / Town</label>
                            <input type="text" id="city" name="city"
                                   value="<?php echo htmlspecialchars($city); ?>" required>
                        </div>

                        <div class="vv-account-field">
                            <label for="state">State / Province</label>
                            <input type="text" id="state" name="state"
                                   value="<?php echo htmlspecialchars($state); ?>" required>
                        </div>

                        <div class="vv-account-field">
                            <label for="postal_code">Postal / ZIP code</label>
                            <input type="text" id="postal_code" name="postal_code"
                                   value="<?php echo htmlspecialchars($postal_code); ?>" required>
                        </div>

                        <div class="vv-account-field">
                            <label for="country">Country</label>
                            <input type="text" id="country" name="country"
                                   value="<?php echo htmlspecialchars($country); ?>" required>
                        </div>
                    </div>

                    <div class="vv-account-divider"></div>

                    <div class="vv-account-section-title">Change password (optional)</div>
                    <p class="vv-account-small" style="margin-bottom:0.4rem;">
                        Leave these fields blank if you don't want to change your password.
                    </p>

                    <!-- Password fields: single built-in eye per field (no duplicates). -->
                    <div class="vv-account-fields-row">
                        <div class="vv-account-field">
                            <label for="current_password">Current password</label>
                            <div class="vv-input-with-icon">
                                <input type="password" id="current_password" name="current_password"
                                       placeholder="Enter current password" autocomplete="current-password">
                                <!-- this is the ONE eye toggle for this input -->
                                <button type="button" class="vv-pass-toggle" data-target="current_password" aria-label="Show password">
                                    <i class="bx bx-show"></i>
                                </button>
                            </div>
                        </div>

                        <div class="vv-account-field">
                            <label for="new_password">New password</label>
                            <div class="vv-input-with-icon">
                                <input type="password" id="new_password" name="new_password"
                                       placeholder="At least 6 characters" autocomplete="new-password">
                                <button type="button" class="vv-pass-toggle" data-target="new_password" aria-label="Show password">
                                    <i class="bx bx-show"></i>
                                </button>
                            </div>
                        </div>

                        <div class="vv-account-field">
                            <label for="confirm_password">Confirm new password</label>
                            <div class="vv-input-with-icon">
                                <input type="password" id="confirm_password" name="confirm_password"
                                       placeholder="Re-type new password" autocomplete="new-password">
                                <button type="button" class="vv-pass-toggle" data-target="confirm_password" aria-label="Show password">
                                    <i class="bx bx-show"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:1rem;display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
                        <button type="submit" class="vv-btn vv-btn-primary">
                            <i class="bx bx-save"></i> Save changes
                        </button>
                        <span class="vv-account-small">
                            Your details are used only for orders you place with Velvet Vogue.
                        </span>
                    </div>
                </form>
            </div>

            <!-- RIGHT: summary / quick info -->
            <aside class="vv-account-card">
                <h3>Account overview</h3>
                <p class="vv-account-sub">
                    A quick snapshot of your Velvet Vogue account.
                </p>

                <ul class="vv-account-summary-list">
                    <li>
                        <i class="bx bx-user-circle"></i>
                        <span>
                            Signed in as<br>
                            <strong><?php echo htmlspecialchars($display_name); ?></strong><br>
                            <span class="vv-account-small"><?php echo htmlspecialchars($email); ?></span>
                        </span>
                    </li>
                    <li>
                        <i class="bx bx-map"></i>
                        <span>
                            Default shipping address<br>
                            <span class="vv-account-small">
                                <?php echo htmlspecialchars($address1); ?>
                                <?php if ($address2 !== ''): ?>
                                    , <?php echo htmlspecialchars($address2); ?>
                                <?php endif; ?><br>
                                <?php echo htmlspecialchars($city); ?>,
                                <?php echo htmlspecialchars($state); ?><br>
                                <?php echo htmlspecialchars($postal_code); ?>,
                                <?php echo htmlspecialchars($country); ?>
                            </span>
                        </span>
                    </li>
                    <li>
                        <i class="bx bx-phone"></i>
                        <span>
                            Contact number<br>
                            <span class="vv-account-small">
                                <?php echo htmlspecialchars($phone); ?>
                            </span>
                        </span>
                    </li>
                </ul>

                <div class="vv-account-divider"></div>

                <div class="vv-account-meta-tag">
                    <i class="bx bx-badge-check"></i>
                    <span>Profile ready for checkout</span>
                </div>

                <p class="vv-account-small" style="margin-top:0.6rem;">
                    When you place a new order, these details will be used automatically on the checkout page.
                    You can still make changes before confirming your order.
                </p>

                <div style="margin-top:0.8rem;display:flex;flex-direction:column;gap:0.4rem;">
                    <a href="customer-orders.php" class="vv-btn vv-btn-secondary">
                        <i class="bx bx-package"></i> View your orders
                    </a>
                    <a href="wishlist.php" class="vv-btn vv-btn-outline">
                        <i class="bx bx-heart"></i> View your wishlist
                    </a>
                </div>
            </aside>
        </div>
    </div>
</main>

<footer class="vv-footer">
    <div class="vv-container vv-footer-grid">
        <div class="vv-footer-brand">
            <div class="vv-logo">
                <span class="vv-logo-main">Velvet</span>
                <span class="vv-logo-sub">Vogue</span>
            </div>
            <p class="vv-footer-copy">
                Curated pieces, considered details and a smoother online shopping experience.
            </p>
            <p class="vv-footer-copy-small">
                &copy; <?php echo date('Y'); ?> Velvet Vogue. All rights reserved.
            </p>
        </div>

        <div class="vv-footer-column">
            <h4>Shop</h4>
            <ul class="vv-footer-list">
                <li><a href="shop.php">All products</a></li>
                <li><a href="shop.php?view=new">New arrivals</a></li>
                <li><a href="shop.php?view=sale">On sale</a></li>
            </ul>
        </div>

        <div class="vv-footer-column">
            <h4>Support</h4>
            <ul class="vv-footer-list">
                <li><a href="contactsupport.php">Contact support</a></li>
                <li><a href="customer-orders.php">My orders</a></li>
                <li><a href="#">Shipping &amp; returns</a></li>
            </ul>
            <div class="vv-footer-social">
                <a href="#"><i class="bx bxl-instagram"></i></a>
                <a href="#"><i class="bx bxl-facebook"></i></a>
                <a href="#"><i class="bx bxl-pinterest"></i></a>
            </div>
            <div class="vv-footer-legal">
                <a href="#">Privacy</a>
                <span>·</span>
                <a href="#">Terms</a>
            </div>
        </div>
    </div>
</footer>

<!-- Photo modal -->
<div class="vv-photo-modal" id="profilePhotoModal">
    <div class="vv-photo-modal-inner">
        <button type="button" class="vv-photo-modal-close" id="profilePhotoModalClose">
            <i class="bx bx-x"></i>
        </button>
        <img src="" alt="Profile photo preview" id="profilePhotoModalImg">
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // -------- Mobile nav toggle --------
    const navToggle = document.getElementById('vvNavToggle');
    const mobileNav = document.getElementById('vvMobileNav');
    if (navToggle && mobileNav) {
        navToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = document.body.classList.toggle('vv-nav-open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', () => {
            document.body.classList.remove('vv-nav-open');
            navToggle.setAttribute('aria-expanded', 'false');
        });
        mobileNav.addEventListener('click', (e) => e.stopPropagation());
    }

    // USER DROPDOWN
    const userMenu = document.querySelector('.vv-user-menu');
    if (userMenu) {
        const toggle = userMenu.querySelector('.vv-user-toggle');
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            userMenu.classList.toggle('open');
        });
        document.addEventListener('click', function () {
            userMenu.classList.remove('open');
        });
    }

    // PROFILE PHOTO PREVIEW
    const fileInput = document.getElementById('profile_photo_input');
    const previewImg = document.getElementById('profilePhotoPreview');
    const avatarInitial = document.getElementById('profileAvatarInitial');

    if (fileInput && previewImg) {
        fileInput.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function (e) {
                previewImg.src = e.target.result;
                previewImg.style.display = 'block';
                previewImg.dataset.fallbackApplied = "0";
                if (avatarInitial) avatarInitial.style.display = 'none';
            };
            reader.readAsDataURL(file);
        });
    }

    // PHOTO MODAL (click avatar to enlarge)
    const avatar = document.getElementById('profileAvatar');
    const modal = document.getElementById('profilePhotoModal');
    const modalImg = document.getElementById('profilePhotoModalImg');
    const modalClose = document.getElementById('profilePhotoModalClose');

    if (avatar && modal && modalImg) {
        avatar.addEventListener('click', function () {
            if (!previewImg || !previewImg.src) return;
            modalImg.src = previewImg.src;
            modal.classList.add('open');
        });
    }
    if (modalClose && modal) {
        modalClose.addEventListener('click', function () {
            modal.classList.remove('open');
        });
    }
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) modal.classList.remove('open');
        });
    }

    // ✅ PASSWORD VIEW BUTTON FIX (works properly)
    // Uses the existing single eye button per field (no duplicates).
    document.querySelectorAll('.vv-pass-toggle').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = btn.getAttribute('data-target');
            const input = document.getElementById(targetId);
            if (!input) return;

            const icon = btn.querySelector('i');
            const isHidden = (input.type === 'password');

            input.type = isHidden ? 'text' : 'password';

            if (icon) {
                if (isHidden) {
                    icon.classList.remove('bx-show');
                    icon.classList.add('bx-hide');
                    btn.setAttribute('aria-label', 'Hide password');
                } else {
                    icon.classList.remove('bx-hide');
                    icon.classList.add('bx-show');
                    btn.setAttribute('aria-label', 'Show password');
                }
            }
        });
    });

});
</script>
</body>
</html>
