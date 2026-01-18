<?php
// admin/admin-profile.php
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_id   = (int)($_SESSION['admin_id'] ?? 0);
$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// ---------- helpers ----------
function clean($v) {
    return trim($v ?? '');
}

$profile_errors   = [];
$password_errors  = [];
$profile_success  = '';
$password_success = '';

// ---------- upload config ----------
$upload_rel_base = 'uploads/admins/';
$upload_dir      = __DIR__ . '/../' . $upload_rel_base;
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

// ---------- load current admin ----------
$stmt = $conn->prepare("
    SELECT id, full_name, email, phone, profile_photo, password_hash
    FROM admins
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param('i', $admin_id);
$stmt->execute();
$res   = $stmt->get_result();
$admin = $res->fetch_assoc();
$stmt->close();

if (!$admin) {
    header('Location: logout.php');
    exit;
}

$current_photo = $admin['profile_photo'] ?? '';
$photo_src     = $current_photo ? '../' . $current_photo : '';

// ---------- handle POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ===========================================
       UPDATE PROFILE (name, email, phone, photo)
       =========================================== */
    if ($action === 'profile') {
        $full_name = clean($_POST['full_name'] ?? '');
        $email     = clean($_POST['email'] ?? '');
        $phone     = clean($_POST['phone'] ?? '');

        $photo_to_save = $current_photo;

        // Basic validation
        if ($full_name === '') {
            $profile_errors[] = 'Full name is required.';
        }
        if ($email === '') {
            $profile_errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $profile_errors[] = 'Please enter a valid email address.';
        }

        // Optional: unique email check (avoid duplicate admin emails)
        if (empty($profile_errors)) {
            $stmt = $conn->prepare("SELECT id FROM admins WHERE email = ? AND id != ? LIMIT 1");
            $stmt->bind_param('si', $email, $admin_id);
            $stmt->execute();
            $check = $stmt->get_result();
            if ($check && $check->fetch_assoc()) {
                $profile_errors[] = 'Another admin already uses this email.';
            }
            $stmt->close();
        }

        // Handle profile photo upload (optional)
        if (!empty($_FILES['profile_photo']['name'])) {
            $file = $_FILES['profile_photo'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $profile_errors[] = 'Error uploading profile photo.';
            } elseif ($file['size'] > 3 * 1024 * 1024) {
                $profile_errors[] = 'Profile photo must be smaller than 3MB.';
            } else {
                $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed  = ['jpg','jpeg','png','gif','webp'];

                if (!in_array($ext, $allowed, true)) {
                    $profile_errors[] = 'Photo must be JPG, JPEG, PNG, GIF or WEBP.';
                } else {
                    $new_filename = uniqid('admin_', true) . '.' . $ext;
                    $dest_path    = $upload_dir . $new_filename;
                    $rel_path     = $upload_rel_base . $new_filename;

                    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
                        $profile_errors[] = 'Could not save profile photo on the server.';
                    } else {
                        // Delete old photo (only if in our uploads folder)
                        if (!empty($current_photo) && str_starts_with($current_photo, $upload_rel_base)) {
                            $old_path = __DIR__ . '/../' . $current_photo;
                            if (is_file($old_path)) {
                                @unlink($old_path);
                            }
                        }
                        $photo_to_save = $rel_path;
                    }
                }
            }
        }

        if (empty($profile_errors)) {
            $stmt = $conn->prepare("
                UPDATE admins
                SET full_name = ?, email = ?, phone = ?, profile_photo = ?
                WHERE id = ?
            ");
            $stmt->bind_param('ssssi', $full_name, $email, $phone, $photo_to_save, $admin_id);

            if ($stmt->execute()) {
                $profile_success = 'Profile updated successfully.';

                // Update local variables + session name
                $admin['full_name']     = $full_name;
                $admin['email']         = $email;
                $admin['phone']         = $phone;
                $admin['profile_photo'] = $photo_to_save;
                $_SESSION['admin_name'] = $full_name;

                $current_photo = $photo_to_save;
                $photo_src     = $current_photo ? '../' . $current_photo : '';
            } else {
                $profile_errors[] = 'Database error while updating profile.';
            }
            $stmt->close();
        }
    }

    /* ===========================================
       CHANGE PASSWORD
       =========================================== */
    if ($action === 'password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Basic validation
        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            $password_errors[] = 'All password fields are required.';
        } elseif (strlen($new_password) < 6) {
            $password_errors[] = 'New password must be at least 6 characters.';
        } elseif ($new_password !== $confirm_password) {
            $password_errors[] = 'New password and confirmation do not match.';
        }

        // Verify current password
        if (empty($password_errors)) {
            $hash_from_db = $admin['password_hash'] ?? '';
            if (!$hash_from_db || !password_verify($current_password, $hash_from_db)) {
                $password_errors[] = 'Current password is incorrect.';
            }
        }

        // Update password if ok
        if (empty($password_errors)) {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                UPDATE admins
                SET password_hash = ?
                WHERE id = ?
            ");
            $stmt->bind_param('si', $new_hash, $admin_id);

            if ($stmt->execute()) {
                $password_success       = 'Password updated successfully.';
                $admin['password_hash'] = $new_hash;
            } else {
                $password_errors[] = 'Database error while updating password.';
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
    <title>My Profile | Velvet Vogue Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap & Boxicons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <!-- Site + admin CSS -->
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">

    <style>
        /* Avatar box */
        .admin-profile-photo-box {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            border: 2px dashed #ddd;
            background-color: #fafafa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            cursor: pointer;
            position: relative;
        }
        .admin-profile-photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .admin-profile-photo-placeholder {
            font-size: 3rem;
            color: #c7c7c7;
        }
        .admin-profile-photo-hint {
            font-size: 0.8rem;
            color: #777;
        }

        /* Center label above avatar */
        .admin-profile-photo-label {
            display: block;
            text-align: center;
            margin-bottom: 0.35rem;
        }

        /* ===== shared preview modal style ===== */
        .image-preview-modal .modal-dialog {
            max-width: 540px;
        }
        .image-preview-modal-content {
            border-radius: 18px;
            overflow: hidden;
            border: none;
        }
        .image-preview-body {
            padding: 0.75rem;
            background: #ffffff;
        }
        .image-preview-img {
            display: block;
            width: 100%;
            height: auto;
            max-height: 70vh;
            object-fit: contain;
        }
        .image-preview-close {
            position: absolute;
            top: 0.35rem;
            right: 0.5rem;
            z-index: 5;
        }

        /* Password show/hide button alignment */
        .password-toggle-group .password-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 0.75rem;
            border-color: #ced4da;
            border-left: 0;
        }
        .password-toggle-group .password-toggle i {
            font-size: 1.2rem;
            line-height: 1;
        }

        /* ================= Mobile Sidebar MENU (same fix) ================= */
        .sidebar-close-btn{
            position:absolute;
            right:10px;
            top:10px;
            border:1px solid rgba(255,255,255,0.25);
            background:rgba(255,255,255,0.1);
            color:#fff;
            border-radius:12px;
            padding:.35rem .55rem;
            line-height:1;
            z-index:2;
        }
        .sidebar-close-btn i{ font-size:1.3rem; }

        .admin-sidebar-backdrop{
            position:fixed;
            inset:0;
            background:rgba(0,0,0,.35);
            z-index:5000;
            opacity:0;
            pointer-events:none;
            transition:150ms ease;
        }
        body.admin-sidebar-open .admin-sidebar-backdrop{
            opacity:1;
            pointer-events:auto;
        }

        @media (max-width: 991.98px){
            .sidebar{
                display:block !important;
                visibility:visible !important;
                opacity:1 !important;

                position:fixed !important;
                left:0;
                top:0;
                height:100vh;
                width:min(300px, 86vw);

                transform:translate3d(-105%,0,0);
                transition:transform 200ms ease;
                z-index:5005 !important;
            }
            body.admin-sidebar-open .sidebar{
                transform:translate3d(0,0,0);
            }
            body.admin-sidebar-open{ overflow:hidden; }
        }

        .admin-mobile-menu-btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border:1px solid rgba(0,0,0,0.12);
            background:#fff;
            border-radius:14px;
            padding:.45rem .55rem;
            line-height:1;
        }
        .admin-mobile-menu-btn i{ font-size:1.35rem; }
    </style>
</head>
<body class="admin-dashboard-body">

<?php include 'includes/sidebar.php'; ?>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>

<div class="admin-main">
    <header class="admin-topbar">
        <div class="d-flex align-items-start gap-2">
            <button type="button" class="admin-mobile-menu-btn d-lg-none" id="adminSidebarOpen" aria-label="Open menu">
                <i class='bx bx-menu'></i>
            </button>

            <div>
                <h1 class="admin-page-title">My Profile</h1>
                <p class="admin-page-subtitle mb-0">
                    Manage your personal details, profile photo, and password.
                </p>
            </div>
        </div>

        <a href="admin-profile.php" class="admin-user-pill text-decoration-none text-dark">
            <i class='bx bxs-user-circle'></i>
            <div>
                <span class="admin-user-name"><?php echo htmlspecialchars($admin['full_name']); ?></span>
                <span class="admin-user-role">Administrator</span>
            </div>
        </a>
    </header>

    <main class="admin-content container-fluid">
        <div class="row g-4">
            <!-- PROFILE DETAILS PANEL -->
            <div class="col-lg-7">
                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title mb-0">Profile details</h2>
                    </div>
                    <div class="admin-panel-body">
                        <?php if (!empty($profile_success)): ?>
                            <div class="alert alert-success">
                                <?php echo htmlspecialchars($profile_success); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($profile_errors)): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    <?php foreach ($profile_errors as $err): ?>
                                        <li><?php echo htmlspecialchars($err); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="post" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="action" value="profile">

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label admin-profile-photo-label">Profile photo</label>

                                    <div id="adminPhotoBox" class="admin-profile-photo-box mb-2">
                                        <img
                                            id="adminPhotoPreview"
                                            src="<?php echo $photo_src ? htmlspecialchars($photo_src) : ''; ?>"
                                            alt="Admin profile photo"
                                            style="<?php echo $photo_src ? '' : 'display:none;'; ?>"
                                        >
                                        <i
                                            id="adminPhotoPlaceholder"
                                            class="bx bxs-user admin-profile-photo-placeholder"
                                            style="<?php echo $photo_src ? 'display:none;' : ''; ?>"
                                        ></i>
                                    </div>

                                    <div class="admin-profile-photo-hint mb-2">
                                        Click the photo to view larger.
                                    </div>

                                    <input
                                        type="file"
                                        name="profile_photo"
                                        id="adminPhotoInput"
                                        class="form-control"
                                        accept="image/*"
                                    >
                                    <small class="text-muted">JPG, PNG, GIF, WEBP · Max 3MB</small>
                                </div>

                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label class="form-label">Full name<span class="text-danger">*</span></label>
                                        <input
                                            type="text"
                                            name="full_name"
                                            class="form-control"
                                            value="<?php echo htmlspecialchars($admin['full_name']); ?>"
                                            required
                                        >
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Email address<span class="text-danger">*</span></label>
                                        <input
                                            type="email"
                                            name="email"
                                            class="form-control"
                                            value="<?php echo htmlspecialchars($admin['email']); ?>"
                                            required
                                        >
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Phone</label>
                                        <input
                                            type="text"
                                            name="phone"
                                            class="form-control"
                                            value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>"
                                        >
                                    </div>

                                    <button type="submit" class="btn btn-primary rounded-pill mt-1">
                                        <i class='bx bx-save me-1'></i> Save profile
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- PASSWORD PANEL -->
            <div class="col-lg-5">
                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title mb-0">Change password</h2>
                    </div>
                    <div class="admin-panel-body">
                        <?php if (!empty($password_success)): ?>
                            <div class="alert alert-success">
                                <?php echo htmlspecialchars($password_success); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($password_errors)): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    <?php foreach ($password_errors as $err): ?>
                                        <li><?php echo htmlspecialchars($err); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="post" novalidate>
                            <input type="hidden" name="action" value="password">

                            <div class="mb-3">
                                <label class="form-label" for="current_password">Current password</label>
                                <div class="input-group password-toggle-group">
                                    <input
                                        type="password"
                                        name="current_password"
                                        id="current_password"
                                        class="form-control"
                                        autocomplete="current-password"
                                    >
                                    <button class="btn btn-outline-secondary password-toggle" type="button" data-target="#current_password">
                                        <i class='bx bx-show'></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="new_password">New password</label>
                                <div class="input-group password-toggle-group">
                                    <input
                                        type="password"
                                        name="new_password"
                                        id="new_password"
                                        class="form-control"
                                        autocomplete="new-password"
                                    >
                                    <button class="btn btn-outline-secondary password-toggle" type="button" data-target="#new_password">
                                        <i class='bx bx-show'></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="confirm_password">Confirm new password</label>
                                <div class="input-group password-toggle-group">
                                    <input
                                        type="password"
                                        name="confirm_password"
                                        id="confirm_password"
                                        class="form-control"
                                        autocomplete="new-password"
                                    >
                                    <button class="btn btn-outline-secondary password-toggle" type="button" data-target="#confirm_password">
                                        <i class='bx bx-show'></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-outline-primary rounded-pill">
                                <i class='bx bx-lock-alt me-1'></i> Update password
                            </button>
                        </form>
                    </div>
                </div>

                <div class="admin-panel mt-3">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title mb-0">Account info</h2>
                    </div>
                    <div class="admin-panel-body">
                        <p class="mb-1">
                            <strong>Login email:</strong><br>
                            <?php echo htmlspecialchars($admin['email']); ?>
                        </p>
                        <p class="mb-1">
                            <strong>Admin ID:</strong><br>
                            #<?php echo (int)$admin['id']; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Back link -->
        <div class="mt-3">
            <a href="dashboard.php" class="btn btn-sm btn-outline-secondary rounded-pill">
                <i class='bx bx-arrow-back me-1'></i> Back to dashboard
            </a>
        </div>
    </main>
</div>

<!-- MODAL: large profile photo preview -->
<div class="modal fade image-preview-modal" id="adminPhotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content image-preview-modal-content">
            <button type="button" class="btn-close image-preview-close" aria-label="Close"></button>
            <div class="modal-body image-preview-body">
                <img
                    id="adminModalPhoto"
                    src="<?php echo $photo_src ? htmlspecialchars($photo_src) : ''; ?>"
                    alt="Admin profile photo large"
                    class="img-fluid image-preview-img"
                >
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* ============ Mobile sidebar menu (same as dashboard) ============ */
function ensureSidebarCloseBtn(){
  const sidebar = document.querySelector('.sidebar');
  if (!sidebar) return null;

  let btn = document.getElementById('adminSidebarClose');
  if (!btn){
    btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'adminSidebarClose';
    btn.className = 'sidebar-close-btn';
    btn.setAttribute('aria-label', 'Close menu');
    btn.innerHTML = "<i class='bx bx-x'></i>";
    sidebar.appendChild(btn);
  }
  return btn;
}

function setupMobileSidebar(){
  const openBtn  = document.getElementById('adminSidebarOpen');
  const backdrop = document.getElementById('adminSidebarBackdrop');
  const closeBtn = ensureSidebarCloseBtn();
  const sidebar  = document.querySelector('.sidebar');

  const open  = () => document.body.classList.add('admin-sidebar-open');
  const close = () => document.body.classList.remove('admin-sidebar-open');

  if (openBtn) openBtn.addEventListener('click', (e) => {
    e.preventDefault(); e.stopPropagation(); open();
  });
  if (closeBtn) closeBtn.addEventListener('click', (e) => {
    e.preventDefault(); e.stopPropagation(); close();
  });
  if (backdrop) backdrop.addEventListener('click', close);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });

  if (sidebar){
    sidebar.querySelectorAll('a.nav-item').forEach(a => {
      a.addEventListener('click', () => {
        if (window.matchMedia('(max-width: 991.98px)').matches) close();
      });
    });
  }
}



/* ============ Profile photo modal + live preview ============ */
function setupProfilePhotoPreview(){
  const fileInput       = document.getElementById('adminPhotoInput');
  const previewImg      = document.getElementById('adminPhotoPreview');
  const placeholderIcon = document.getElementById('adminPhotoPlaceholder');
  const photoBox        = document.getElementById('adminPhotoBox');
  const modalEl         = document.getElementById('adminPhotoModal');
  const modalImg        = document.getElementById('adminModalPhoto');
  const closeBtn        = modalEl ? modalEl.querySelector('.image-preview-close') : null;

  if (!previewImg || !photoBox || !modalEl || !modalImg) return;

  const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);

  if (fileInput) {
    fileInput.addEventListener('change', function (e) {
      const file = e.target.files[0];
      if (!file) return;

      const reader = new FileReader();
      reader.onload = function (ev) {
        const src = ev.target.result;
        previewImg.src = src;
        previewImg.style.display = 'block';
        if (placeholderIcon) placeholderIcon.style.display = 'none';
        modalImg.src = src;
      };
      reader.readAsDataURL(file);
    });
  }

  photoBox.addEventListener('click', function () {
    if (!previewImg.src) return;
    modalImg.src = previewImg.src;
    modalInstance.show();
  });

  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      modalInstance.hide();
    });
  }
}

/* ============ Password show/hide toggles ============ */
function setupPasswordToggles(){
  document.querySelectorAll('.password-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const targetSelector = btn.getAttribute('data-target');
      const input = document.querySelector(targetSelector);
      if (!input) return;

      const icon = btn.querySelector('i');
      if (input.type === 'password') {
        input.type = 'text';
        if (icon) { icon.classList.remove('bx-show'); icon.classList.add('bx-hide'); }
      } else {
        input.type = 'password';
        if (icon) { icon.classList.remove('bx-hide'); icon.classList.add('bx-show'); }
      }
    });
  });
}

document.addEventListener('DOMContentLoaded', function () {
  setupMobileSidebar();
  setupProfilePhotoPreview();
  setupPasswordToggles();
});
</script>
</body>
</html>
