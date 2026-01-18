<?php
// admin/customer-edit.php
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// Helpers
function clean($v) { return trim($v ?? ''); }
function redirect_with_msg($url, $msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    header("Location: {$url}");
    exit;
}

// Upload config
$upload_rel_base = 'uploads/customers/';
$upload_dir      = __DIR__ . '/../' . $upload_rel_base;
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

// identify mode
$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit     = $customer_id > 0;

$errors = [];
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// default data
$customer = [
    'full_name'     => '',
    'email'         => '',
    'password_hash' => '',
    'phone'         => '',
    'gender'        => 'prefer_not_say',
    'date_of_birth' => '',
    'address_line1' => '',
    'address_line2' => '',
    'city'          => '',
    'state'         => '',
    'postal_code'   => '',
    'country'       => '',
    'profile_photo' => '',
    'is_active'     => 1,
];

// load existing
if ($is_edit) {
    $stmt = $conn->prepare("
        SELECT *
        FROM customers
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $customer = $row;
    } else {
        redirect_with_msg('customers.php', 'Customer not found.', 'danger');
    }
    $stmt->close();
}

// handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Delete customer
    if (isset($_POST['delete_customer']) && $is_edit) {

        // delete photo if inside uploads/customers
        if (!empty($customer['profile_photo']) &&
            str_starts_with($customer['profile_photo'], $upload_rel_base)) {

            $old = __DIR__ . '/../' . $customer['profile_photo'];
            if (is_file($old)) {
                @unlink($old);
            }
        }

        try {
            $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->bind_param('i', $customer_id);
            if ($stmt->execute()) {
                redirect_with_msg('customers.php', 'Customer deleted.');
            } else {
                redirect_with_msg(
                    "customer-edit.php?id={$customer_id}",
                    'Unable to delete this customer because they have orders or related records.',
                    'danger'
                );
            }
        } catch (Throwable $e) {
            redirect_with_msg(
                "customer-edit.php?id={$customer_id}",
                'Unable to delete this customer because they have orders or related records.',
                'danger'
            );
        }
    }

    // Save (add/edit)
    $full_name   = clean($_POST['full_name'] ?? '');
    $email       = clean($_POST['email'] ?? '');
    $phone       = clean($_POST['phone'] ?? '');
    $gender      = clean($_POST['gender'] ?? 'prefer_not_say');
    $dob         = clean($_POST['date_of_birth'] ?? '');
    $addr1       = clean($_POST['address_line1'] ?? '');
    $addr2       = clean($_POST['address_line2'] ?? '');
    $city        = clean($_POST['city'] ?? '');
    $state       = clean($_POST['state'] ?? '');
    $postal      = clean($_POST['postal_code'] ?? '');
    $country     = clean($_POST['country'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    $profile_to_save = $customer['profile_photo'] ?? '';

    // validation
    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    }
    if ($email === '') {
        $errors[] = 'Email is required.';
    }

    // unique email
    $stmt = $conn->prepare(
        $is_edit
            ? "SELECT id FROM customers WHERE email = ? AND id != ? LIMIT 1"
            : "SELECT id FROM customers WHERE email = ? LIMIT 1"
    );
    if ($is_edit) {
        $stmt->bind_param('si', $email, $customer_id);
    } else {
        $stmt->bind_param('s', $email);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->fetch_assoc()) {
        $errors[] = 'Another customer already uses this email.';
    }
    $stmt->close();

    // handle profile photo upload
    if (!empty($_FILES['profile_photo']['name'])) {
        $file = $_FILES['profile_photo'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Error uploading profile photo.';
        } elseif ($file['size'] > 3 * 1024 * 1024) {
            $errors[] = 'Profile photo must be smaller than 3MB.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (!in_array($ext, $allowed, true)) {
                $errors[] = 'Profile photo must be JPG, JPEG, PNG, GIF, or WEBP.';
            } else {
                $new_filename = uniqid('cust_', true) . '.' . $ext;
                $dest_path    = $upload_dir . $new_filename;
                $rel_path     = $upload_rel_base . $new_filename;

                if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
                    $errors[] = 'Could not save profile photo on the server.';
                } else {
                    // delete previous upload only if it was in uploads/customers
                    if (!empty($customer['profile_photo']) &&
                        str_starts_with($customer['profile_photo'], $upload_rel_base)) {

                        $old = __DIR__ . '/../' . $customer['profile_photo'];
                        if (is_file($old)) {
                            @unlink($old);
                        }
                    }
                    $profile_to_save = $rel_path;
                }
            }
        }
    }

    if (empty($errors)) {
        if ($is_edit) {
            $stmt = $conn->prepare("
                UPDATE customers
                SET full_name = ?, email = ?, phone = ?, gender = ?, date_of_birth = ?,
                    address_line1 = ?, address_line2 = ?, city = ?, state = ?, postal_code = ?,
                    country = ?, profile_photo = ?, is_active = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                'ssssssssssssii',
                $full_name,
                $email,
                $phone,
                $gender,
                $dob,
                $addr1,
                $addr2,
                $city,
                $state,
                $postal,
                $country,
                $profile_to_save,
                $is_active,
                $customer_id
            );

            if ($stmt->execute()) {
                $stmt->close();
                redirect_with_msg("customer-edit.php?id={$customer_id}", 'Customer updated successfully.');
            } else {
                $errors[] = 'Database error while updating customer.';
            }
        } else {
            // NOTE: you had plain string "client123". Keep as-is if your customer login expects plain.
            // If your customer login uses password_hash(), you MUST hash it here.
            $password_hash = 'client123';

            $stmt = $conn->prepare("
                INSERT INTO customers
                    (full_name, email, password_hash, phone, gender, date_of_birth,
                     address_line1, address_line2, city, state, postal_code, country,
                     profile_photo, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");

            $stmt->bind_param(
                'sssssssssssssi',
                $full_name,
                $email,
                $password_hash,
                $phone,
                $gender,
                $dob,
                $addr1,
                $addr2,
                $city,
                $state,
                $postal,
                $country,
                $profile_to_save,
                $is_active
            );

            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                $stmt->close();
                redirect_with_msg("customer-edit.php?id={$new_id}", 'Customer created successfully.');
            } else {
                $errors[] = 'Database error while creating customer.';
            }
        }
    }

    // Keep form values on error
    $customer = array_merge($customer, [
        'full_name'     => $full_name,
        'email'         => $email,
        'phone'         => $phone,
        'gender'        => $gender,
        'date_of_birth' => $dob,
        'address_line1' => $addr1,
        'address_line2' => $addr2,
        'city'          => $city,
        'state'         => $state,
        'postal_code'   => $postal,
        'country'       => $country,
        'profile_photo' => $profile_to_save,
        'is_active'     => $is_active,
    ]);
}

// values for preview / modal
$hasPhoto = !empty($customer['profile_photo']);
$photoSrc = $hasPhoto ? '../' . $customer['profile_photo'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $is_edit ? 'Edit Customer' : 'Add Customer'; ?> | Velvet Vogue Admin</title>
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
        /* small box on the form */
        .customer-photo-box {
            width: 140px;
            height: 140px;
            border-radius: 16px;
            border: 1px dashed #ddd;
            background-color: #fafafa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            cursor: pointer;
            position: relative;
        }
        .customer-photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .customer-photo-placeholder {
            font-size: 2.5rem;
            color: #c7c7c7;
        }
        .customer-photo-hint {
            font-size: 0.8rem;
            color: #777;
        }

        /* ======= shared preview modal style (same as product) ======= */
        .image-preview-modal .modal-dialog { max-width: 540px; }
        .image-preview-modal-content { border-radius: 18px; overflow: hidden; border: none; }
        .image-preview-body { padding: 0.75rem; background: #ffffff; }
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

        /* ================= Mobile Sidebar MENU (same as dashboard) ================= */
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
                <h1 class="admin-page-title">
                    <?php echo $is_edit ? 'Edit Customer' : 'Add Customer'; ?>
                </h1>
                <p class="admin-page-subtitle mb-0">
                    Manage customer details, profile photo and status.
                </p>
            </div>
        </div>

        <a href="admin-profile.php" class="admin-user-pill text-decoration-none text-dark">
            <i class='bx bxs-user-circle'></i>
            <div>
                <span class="admin-user-name"><?php echo htmlspecialchars($admin_name); ?></span>
                <span class="admin-user-role">Administrator</span>
            </div>
        </a>
    </header>

    <main class="admin-content container-fluid">
        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2 class="admin-panel-title mb-0">
                    <?php echo $is_edit ? 'Customer information' : 'New customer'; ?>
                </h2>
                <div class="d-flex gap-2">
                    <a href="customers.php" class="btn btn-sm btn-outline-secondary rounded-pill">
                        <i class='bx bx-list-ul me-1'></i> Back to list
                    </a>

                    <?php if ($is_edit): ?>
                        <form method="post" onsubmit="return confirm('Delete this customer?');">
                            <input type="hidden" name="delete_customer" value="1">
                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">
                                <i class='bx bx-trash-alt me-1'></i> Delete
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-panel-body">
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                        <?php echo htmlspecialchars($flash['msg']); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $e): ?>
                                <li><?php echo htmlspecialchars($e); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" novalidate>
                    <div class="row g-3">
                        <!-- photo column -->
                        <div class="col-md-3">
                            <label class="form-label">Profile photo</label>

                            <div id="customerPhotoBox" class="customer-photo-box mb-2">
                                <img id="customerImagePreview"
                                     src="<?php echo $hasPhoto ? htmlspecialchars($photoSrc) : ''; ?>"
                                     alt="Profile image preview"
                                     style="<?php echo $hasPhoto ? '' : 'display:none;'; ?>">

                                <i id="customerPlaceholderIcon"
                                   class="bx bx-user customer-photo-placeholder"
                                   style="<?php echo $hasPhoto ? 'display:none;' : ''; ?>"></i>
                            </div>

                            <div class="customer-photo-hint mb-2">
                                Click photo to view larger.
                            </div>

                            <input type="file" name="profile_photo" id="customerImageInput"
                                   class="form-control" accept="image/*">
                            <small class="text-muted">
                                PNG, JPG, GIF, or WEBP. Max 3MB. Preview updates immediately.
                            </small>
                        </div>

                        <!-- details column -->
                        <div class="col-md-9">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Full name<span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control"
                                           value="<?php echo htmlspecialchars($customer['full_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email<span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control"
                                           value="<?php echo htmlspecialchars($customer['email']); ?>" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control"
                                           value="<?php echo htmlspecialchars($customer['phone']); ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Gender</label>
                                    <select name="gender" class="form-select">
                                        <option value="male"   <?php echo $customer['gender'] === 'male'   ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo $customer['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="other" <?php echo $customer['gender'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                        <option value="prefer_not_say" <?php echo $customer['gender'] === 'prefer_not_say' ? 'selected' : ''; ?>>Prefer not to say</option>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Date of birth</label>
                                    <input type="date" name="date_of_birth" class="form-control"
                                           value="<?php echo htmlspecialchars($customer['date_of_birth']); ?>">
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Address line 1</label>
                                    <input type="text" name="address_line1" class="form-control"
                                           value="<?php echo htmlspecialchars($customer['address_line1']); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Address line 2</label>
                                    <input type="text" name="address_line2" class="form-control"
                                           value="<?php echo htmlspecialchars($customer['address_line2']); ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">City</label>
                                    <input type="text" name="city" class="form-control"
                                           value="<?php echo htmlspecialchars($customer['city']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">State</label>
                                    <input type="text" name="state" class="form-control"
                                           value="<?php echo htmlspecialchars($customer['state']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Postal code</label>
                                    <input type="text" name="postal_code" class="form-control"
                                           value="<?php echo htmlspecialchars($customer['postal_code']); ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Country</label>
                                    <input type="text" name="country" class="form-control"
                                           value="<?php echo htmlspecialchars($customer['country']); ?>">
                                </div>

                                <div class="col-md-6 d-flex align-items-center mt-4 mt-md-0">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_active"
                                               name="is_active" value="1"
                                            <?php echo $customer['is_active'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">Active customer</label>
                                    </div>
                                </div>

                                <?php if (!$is_edit): ?>
                                    <div class="col-12">
                                        <small class="text-muted">
                                            Initial password for new customers will be <code>client123</code>.
                                        </small>
                                    </div>
                                <?php endif; ?>

                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-primary rounded-pill">
                                        <i class='bx bx-save me-1'></i>
                                        <?php echo $is_edit ? 'Save changes' : 'Create customer'; ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div> <!-- row -->
                </form>
            </div>
        </div>
    </main>
</div>

<!-- MODAL: large profile photo preview -->
<div class="modal fade image-preview-modal" id="customerImageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content image-preview-modal-content">
            <button type="button" class="btn-close image-preview-close" aria-label="Close"></button>
            <div class="modal-body image-preview-body">
                <img id="customerModalImage"
                     src="<?php echo $hasPhoto ? htmlspecialchars($photoSrc) : ''; ?>"
                     alt="Profile image large preview"
                     class="img-fluid image-preview-img">
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
    e.preventDefault();
    e.stopPropagation();
    open();
  });

  if (closeBtn) closeBtn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    close();
  });

  if (backdrop) backdrop.addEventListener('click', close);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });

  // close menu when clicking a sidebar link on mobile
  if (sidebar){
    sidebar.querySelectorAll('a.nav-item').forEach(a => {
      a.addEventListener('click', () => {
        if (window.matchMedia('(max-width: 991.98px)').matches) close();
      });
    });
  }
}

document.addEventListener('DOMContentLoaded', function () {
    setupMobileSidebar();

    const fileInput       = document.getElementById('customerImageInput');
    const previewImg      = document.getElementById('customerImagePreview');
    const placeholderIcon = document.getElementById('customerPlaceholderIcon');
    const photoBox        = document.getElementById('customerPhotoBox');
    const modalEl         = document.getElementById('customerImageModal');
    const modalImg        = document.getElementById('customerModalImage');
    const closeBtn        = modalEl ? modalEl.querySelector('.image-preview-close') : null;

    let modalInstance = null;
    if (modalEl) modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);

    if (fileInput && previewImg) {
        fileInput.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function (ev) {
                const src = ev.target.result;
                previewImg.src = src;
                previewImg.style.display = 'block';
                if (placeholderIcon) placeholderIcon.style.display = 'none';
                if (modalImg) modalImg.src = src;
            };
            reader.readAsDataURL(file);
        });
    }

    if (photoBox && modalInstance && modalImg) {
        photoBox.addEventListener('click', function () {
            if (!previewImg || !previewImg.src) return;
            modalImg.src = previewImg.src;
            modalInstance.show();
        });
    }

    if (closeBtn && modalInstance) {
        closeBtn.addEventListener('click', function () {
            modalInstance.hide();
        });
    }
});
</script>
</body>
</html>
