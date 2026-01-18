<?php
// admin/promotions.php
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_id   = (int)$_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'Admin';

/* =========================
   Helpers
========================= */
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function flash_set(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function dt_from_input(?string $v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;

    // supports: 2026-01-15T12:48 (datetime-local)
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $v);
    if ($dt instanceof DateTime) return $dt->format('Y-m-d H:i:s');

    
    // fallback if already in SQL format
    $dt2 = DateTime::createFromFormat('Y-m-d H:i:s', $v);
    if ($dt2 instanceof DateTime) return $dt2->format('Y-m-d H:i:s');

    return null;
}

function is_local_upload_path(string $path): bool {
    // only delete files inside uploads/promotions
    return (strpos($path, 'uploads/promotions/') === 0);
}

function ensure_upload_dir(string $dirAbs): bool {
    if (is_dir($dirAbs)) return true;
    return @mkdir($dirAbs, 0775, true);
}

function save_promo_image(array $file): array {
    // returns [ok(bool), path(?string), err(?string)]
    if (empty($file['name']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        return [false, null, ''];
    }

    $maxBytes = 5 * 1024 * 1024; // 5MB
    if ((int)$file['size'] > $maxBytes) {
        return [false, null, 'Image too large (max 5MB).'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','gif'];
    if (!in_array($ext, $allowed, true)) {
        return [false, null, 'Invalid image type. Allowed: JPG, PNG, WEBP, GIF.'];
    }

    $uploadDirAbs = realpath(__DIR__ . '/../') . '/uploads/promotions';
    if (!$uploadDirAbs) {
        return [false, null, 'Upload path error.'];
    }
    if (!ensure_upload_dir($uploadDirAbs)) {
        return [false, null, 'Could not create upload folder.'];
    }

    $safeName = 'promo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destAbs  = $uploadDirAbs . '/' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
        return [false, null, 'Failed to upload image.'];
    }

    // store relative path (consistent with your other uploads)
    $relative = 'uploads/promotions/' . $safeName;
    return [true, $relative, null];
}

function log_admin_action(mysqli $conn, int $admin_id, string $action, ?string $details = null): void {
    // Optional: table exists in your SQL, so log if possible (ignore errors).
    try {
        $stmt = $conn->prepare("INSERT INTO admin_activity_log (admin_id, action, details) VALUES (?,?,?)");
        if ($stmt) {
            $stmt->bind_param('iss', $admin_id, $action, $details);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/* =========================
   POST Actions (CRUD)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    // common fields
    $title       = trim((string)($_POST['title'] ?? ''));
    $subtitle    = trim((string)($_POST['subtitle'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $badge_label = trim((string)($_POST['badge_label'] ?? ''));
    $image_url   = trim((string)($_POST['image_url'] ?? ''));
    $cta_label   = trim((string)($_POST['cta_label'] ?? ''));
    $cta_link    = trim((string)($_POST['cta_link'] ?? ''));
    $is_active   = isset($_POST['is_active']) ? 1 : 0;
    $starts_at   = dt_from_input($_POST['starts_at'] ?? null);
    $ends_at     = dt_from_input($_POST['ends_at'] ?? null);

    // validate dates if both provided
    if ($starts_at && $ends_at) {
        try {
            $s = new DateTime($starts_at);
            $e = new DateTime($ends_at);
            if ($s > $e) {
                flash_set('danger', 'Start date must be before end date.');
                header('Location: promotions.php');
                exit;
            }
        } catch (Throwable $e) {}
    }

    // CREATE
    if ($action === 'create') {
        if ($title === '') {
            flash_set('danger', 'Title is required.');
            header('Location: promotions.php');
            exit;
        }

        // image preference: uploaded file > image_url input > null
        $finalImage = $image_url !== '' ? $image_url : null;

        if (!empty($_FILES['image_file']) && (int)$_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            [$ok, $path, $err] = save_promo_image($_FILES['image_file']);
            if (!$ok) {
                flash_set('danger', $err ?: 'Image upload failed.');
                header('Location: promotions.php');
                exit;
            }
            $finalImage = $path;
        }

        $stmt = $conn->prepare("
            INSERT INTO promotions
                (title, subtitle, description, badge_label, image_url, cta_label, cta_link, is_active, starts_at, ends_at)
            VALUES
                (?,?,?,?,?,?,?,?,?,?)
        ");
        if (!$stmt) {
            flash_set('danger', 'Database error (prepare).');
            header('Location: promotions.php');
            exit;
        }

        $stmt->bind_param(
            'sssssssiss',
            $title,
            $subtitle,
            $description,
            $badge_label,
            $finalImage,
            $cta_label,
            $cta_link,
            $is_active,
            $starts_at,
            $ends_at
        );

        if ($stmt->execute()) {
            $newId = (int)$stmt->insert_id;
            $stmt->close();
            log_admin_action($conn, $admin_id, 'create_promotion', "Created promotion ID {$newId}: {$title}");
            flash_set('success', 'Promotion created successfully.');
        } else {
            $stmt->close();
            flash_set('danger', 'Failed to create promotion.');
        }

        header('Location: promotions.php');
        exit;
    }

    // UPDATE
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('danger', 'Invalid promotion ID.');
            header('Location: promotions.php');
            exit;
        }
        if ($title === '') {
            flash_set('danger', 'Title is required.');
            header('Location: promotions.php');
            exit;
        }

        // fetch existing for image handling
        $old = null;
        $stmt0 = $conn->prepare("SELECT image_url, title FROM promotions WHERE id = ?");
        if ($stmt0) {
            $stmt0->bind_param('i', $id);
            $stmt0->execute();
            $res0 = $stmt0->get_result();
            $old = $res0 ? $res0->fetch_assoc() : null;
            $stmt0->close();
        }

        $oldImage = $old['image_url'] ?? null;
        $finalImage = ($image_url !== '' ? $image_url : $oldImage);

        // remove image?
        if (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
            $finalImage = null;
            if ($oldImage && is_local_upload_path($oldImage)) {
                $abs = realpath(__DIR__ . '/../') . '/' . $oldImage;
                if ($abs && is_file($abs)) @unlink($abs);
            }
        }

        // new upload?
        if (!empty($_FILES['image_file']) && (int)$_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            [$ok, $path, $err] = save_promo_image($_FILES['image_file']);
            if (!$ok) {
                flash_set('danger', $err ?: 'Image upload failed.');
                header('Location: promotions.php');
                exit;
            }

            // delete old local image if replaced
            if ($oldImage && is_local_upload_path($oldImage)) {
                $abs = realpath(__DIR__ . '/../') . '/' . $oldImage;
                if ($abs && is_file($abs)) @unlink($abs);
            }

            $finalImage = $path;
        }

        $stmt = $conn->prepare("
            UPDATE promotions
            SET title = ?, subtitle = ?, description = ?, badge_label = ?, image_url = ?,
                cta_label = ?, cta_link = ?, is_active = ?, starts_at = ?, ends_at = ?
            WHERE id = ?
        ");
        if (!$stmt) {
            flash_set('danger', 'Database error (prepare).');
            header('Location: promotions.php');
            exit;
        }

        $stmt->bind_param(
            'sssssssissi',
            $title,
            $subtitle,
            $description,
            $badge_label,
            $finalImage,
            $cta_label,
            $cta_link,
            $is_active,
            $starts_at,
            $ends_at,
            $id
        );

        if ($stmt->execute()) {
            $stmt->close();
            log_admin_action($conn, $admin_id, 'update_promotion', "Updated promotion ID {$id}: {$title}");
            flash_set('success', 'Promotion updated successfully.');
        } else {
            $stmt->close();
            flash_set('danger', 'Failed to update promotion.');
        }

        header('Location: promotions.php');
        exit;
    }

    // DELETE
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('danger', 'Invalid promotion ID.');
            header('Location: promotions.php');
            exit;
        }

        // fetch old image
        $oldImage = null;
        $oldTitle = null;
        $stmt0 = $conn->prepare("SELECT image_url, title FROM promotions WHERE id = ?");
        if ($stmt0) {
            $stmt0->bind_param('i', $id);
            $stmt0->execute();
            $res0 = $stmt0->get_result();
            if ($res0) {
                $row0 = $res0->fetch_assoc();
                $oldImage = $row0['image_url'] ?? null;
                $oldTitle = $row0['title'] ?? null;
            }
            $stmt0->close();
        }

        $stmt = $conn->prepare("DELETE FROM promotions WHERE id = ?");
        if (!$stmt) {
            flash_set('danger', 'Database error (prepare).');
            header('Location: promotions.php');
            exit;
        }
        $stmt->bind_param('i', $id);

        if ($stmt->execute()) {
            $stmt->close();

            if ($oldImage && is_local_upload_path($oldImage)) {
                $abs = realpath(__DIR__ . '/../') . '/' . $oldImage;
                if ($abs && is_file($abs)) @unlink($abs);
            }

            log_admin_action($conn, $admin_id, 'delete_promotion', "Deleted promotion ID {$id}: {$oldTitle}");
            flash_set('success', 'Promotion deleted.');
        } else {
            $stmt->close();
            flash_set('danger', 'Failed to delete promotion.');
        }

        header('Location: promotions.php');
        exit;
    }

    // QUICK TOGGLE ACTIVE
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $to = (int)($_POST['to'] ?? 0) ? 1 : 0;

        if ($id <= 0) {
            flash_set('danger', 'Invalid promotion ID.');
            header('Location: promotions.php');
            exit;
        }

        $stmt = $conn->prepare("UPDATE promotions SET is_active = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $to, $id);
            if ($stmt->execute()) {
                $stmt->close();
                log_admin_action($conn, $admin_id, 'toggle_promotion', "Promotion ID {$id} is_active={$to}");
                flash_set('success', 'Promotion status updated.');
            } else {
                $stmt->close();
                flash_set('danger', 'Failed to update status.');
            }
        } else {
            flash_set('danger', 'Database error (prepare).');
        }

        header('Location: promotions.php');
        exit;
    }

    flash_set('danger', 'Invalid action.');
    header('Location: promotions.php');
    exit;
}

/* =========================
   Load promotions list
========================= */
$promotions = [];
$q = $conn->query("SELECT * FROM promotions ORDER BY created_at DESC, id DESC");
if ($q) {
    while ($row = $q->fetch_assoc()) $promotions[] = $row;
    $q->free();
}

$flash = flash_get();

// helper for status badge
function promo_runtime_status(array $p): array {
    $isActive = (int)($p['is_active'] ?? 0) === 1;

    $now = new DateTime('now');
    $s = !empty($p['starts_at']) ? new DateTime($p['starts_at']) : null;
    $e = !empty($p['ends_at']) ? new DateTime($p['ends_at']) : null;

    // Determine window status
    $inWindow = true;
    if ($s && $now < $s) $inWindow = false;
    if ($e && $now > $e) $inWindow = false;

    if (!$isActive) return ['Disabled', 'secondary'];
    if ($s && $now < $s) return ['Scheduled', 'info'];
    if ($e && $now > $e) return ['Expired', 'warning'];
    if ($inWindow) return ['Running', 'success'];
    return ['Active', 'success'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Promotions | Velvet Vogue Admin</title>

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
        :root{
            --vv-primary: #6B1F4F;   /* velvet */
            --vv-bg: #f4f5fb;
            --vv-border: #e9e9f5;
            --vv-text: #111013;
            --vv-muted: #6b7280;
        }

        .panel-card{
            background:#fff;
            border:1px solid var(--vv-border);
            border-radius:16px;
            padding:16px;
        }

        .page-title{
            font-family:"Playfair Display", serif;
            font-weight:700;
            margin:0;
        }
        .page-sub{
            color:var(--vv-muted);
            margin:2px 0 0 0;
            font-size:.92rem;
        }

        .btn-pill{
            border-radius:999px;
            font-weight:600;
            font-size:.85rem;
            display:inline-flex;
            gap:6px;
            align-items:center;
        }

        .promo-thumb{
            width:64px;
            height:44px;
            border-radius:10px;
            border:1px solid var(--vv-border);
            object-fit:cover;
            background:#fafafa;
        }

        .table thead th{
            background:#fafafa;
        }

        /* Mobile menu button */
        .admin-mobile-menu-btn{
            border: 1px solid var(--vv-border);
            background: #fff;
            border-radius: 12px;
            width: 44px;
            height: 44px;
            display: inline-flex;
            align-items:center;
            justify-content:center;
        }
        .admin-mobile-menu-btn i{
            font-size: 1.55rem;
            color: var(--vv-primary);
            line-height: 1;
        }

        /* Modal styling */
        .details-modal .modal-content{
            border-radius:16px;
            overflow:hidden;
        }
        .details-modal .modal-header{
            background:#fff;
            border-bottom:1px solid var(--vv-border);
            display:flex;
            justify-content:space-between;
            gap:12px;
        }
        .details-modal .modal-title{
            font-family:"Playfair Display", serif;
            font-weight:700;
        }
        .modal-actions{
            margin-left:auto;
            display:flex;
            gap:8px;
            align-items:center;
            justify-content:flex-end;
            flex-wrap:wrap;
        }
        .modal-actions .btn{
            border-radius:999px;
            padding:6px 10px;
            font-weight:600;
            font-size:.82rem;
            line-height:1;
            display:inline-flex;
            align-items:center;
            gap:6px;
        }

        /* ===== Mobile Off-canvas Sidebar (FORCE show contents) ===== */
        @media (max-width: 991.98px){
            .sidebar,
            .admin-sidebar,
            #sidebar {
                display: block !important;
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;

                width: 290px !important;
                height: 100vh !important;

                background: #fff !important;
                z-index: 1055 !important;

                overflow-y: auto !important;
                -webkit-overflow-scrolling: touch;

                transform: translateX(-110%) !important;
                transition: transform .25s ease !important;

                box-shadow: 0 12px 30px rgba(0,0,0,.18) !important;
            }

            .sidebar.open,
            .sidebar.active,
            .sidebar.show,
            .admin-sidebar.open,
            .admin-sidebar.active,
            .admin-sidebar.show,
            #sidebar.open,
            #sidebar.active,
            #sidebar.show {
                transform: translateX(0) !important;
            }

            body.sidebar-open{ overflow: hidden; }

            .admin-sidebar-backdrop{
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,.35);
                z-index: 1050;
                display: none;
            }
            body.sidebar-open .admin-sidebar-backdrop{ display:block; }

            .sidebar-close-btn{
                position: sticky;
                top: 10px;
                float: right;
                margin: 10px 10px 0 0;
                border: 1px solid var(--vv-border);
                background: #fff;
                border-radius: 12px;
                width: 40px;
                height: 40px;
                display:flex;
                align-items:center;
                justify-content:center;
                z-index: 2;
            }
            .sidebar-close-btn i{
                font-size: 1.4rem;
                color: var(--vv-primary);
                line-height: 1;
            }
        }
    </style>
</head>

<body class="admin-dashboard-body">
<?php include 'includes/sidebar.php'; ?>

<!-- backdrop for mobile sidebar -->
<div id="adminSidebarBackdrop" class="admin-sidebar-backdrop d-lg-none" aria-hidden="true"></div>

<div class="admin-main">
    <header class="admin-topbar">
        <div class="d-flex align-items-start gap-2">
            <button class="btn admin-mobile-menu-btn d-lg-none" type="button" id="adminSidebarToggle" aria-label="Open menu">
                <i class='bx bx-menu'></i>
            </button>
            <div>
                <h1 class="admin-page-title">Promotions</h1>
                <p class="admin-page-subtitle mb-0">Create, update and schedule homepage promotions & banners.</p>
            </div>
        </div>

        <a href="admin-profile.php" class="admin-user-pill text-decoration-none text-dark">
            <i class='bx bxs-user-circle'></i>
            <div>
                <span class="admin-user-name"><?php echo h($admin_name); ?></span>
                <span class="admin-user-role">Administrator</span>
            </div>
        </a>
    </header>

    <main class="admin-content container-fluid">

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo h($flash['type']); ?> rounded-4">
                <?php echo h($flash['msg']); ?>
            </div>
        <?php endif; ?>

        <div class="panel-card mb-3">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                <div>
                    <h2 class="page-title">All Promotions</h2>
                    <p class="page-sub">Manage promotions from the <code>promotions</code> table.</p>
                </div>
                <button class="btn btn-outline-success btn-pill" data-bs-toggle="modal" data-bs-target="#modalCreatePromo" type="button">
                    <i class="bx bx-plus"></i> New Promotion
                </button>
            </div>

            <div class="table-responsive mt-3">
                <table class="table align-middle mb-0">
                    <thead>
                    <tr>
                        <th style="width:86px;">Image</th>
                        <th>Title</th>
                        <th>Badge</th>
                        <th>Status</th>
                        <th>Starts</th>
                        <th>Ends</th>
                        <th>CTA</th>
                        <th class="text-end" style="width:220px;">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($promotions)): ?>
                        <tr>
                            <td colspan="8" class="text-muted">No promotions found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($promotions as $p): ?>
                            <?php
                                [$statusText, $statusColor] = promo_runtime_status($p);
                                $img = $p['image_url'] ?? '';
                                $imgSrc = $img ? h('../' . ltrim($img, '/')) : 'data:image/svg+xml;charset=UTF-8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="80"><rect width="100%" height="100%" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#9ca3af" font-family="Arial" font-size="12">No image</text></svg>');
                                $isActive = (int)($p['is_active'] ?? 0) === 1;
                            ?>
                            <tr>
                                <td>
                                    <img class="promo-thumb" src="<?php echo $imgSrc; ?>" alt="promo">
                                </td>
                                <td>
                                    <div style="font-weight:700;"><?php echo h($p['title']); ?></div>
                                    <?php if (!empty($p['subtitle'])): ?>
                                        <small class="text-muted"><?php echo h($p['subtitle']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?php echo h($p['badge_label']); ?></td>
                                <td>
                                    <span class="badge text-bg-<?php echo h($statusColor); ?>"><?php echo h($statusText); ?></span>
                                </td>
                                <td class="text-muted">
                                    <?php echo !empty($p['starts_at']) ? h(date('Y-m-d H:i', strtotime($p['starts_at']))) : '—'; ?>
                                </td>
                                <td class="text-muted">
                                    <?php echo !empty($p['ends_at']) ? h(date('Y-m-d H:i', strtotime($p['ends_at']))) : '—'; ?>
                                </td>
                                <td class="text-muted">
                                    <?php if (!empty($p['cta_label'])): ?>
                                        <span style="font-weight:600;"><?php echo h($p['cta_label']); ?></span><br>
                                        <small><?php echo h($p['cta_link']); ?></small>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-2 flex-wrap justify-content-end">
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary btn-pill"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalEditPromo"
                                            data-id="<?php echo (int)$p['id']; ?>"
                                            data-title="<?php echo h($p['title']); ?>"
                                            data-subtitle="<?php echo h($p['subtitle']); ?>"
                                            data-description="<?php echo h($p['description']); ?>"
                                            data-badge="<?php echo h($p['badge_label']); ?>"
                                            data-image="<?php echo h($p['image_url']); ?>"
                                            data-cta_label="<?php echo h($p['cta_label']); ?>"
                                            data-cta_link="<?php echo h($p['cta_link']); ?>"
                                            data-is_active="<?php echo (int)$p['is_active']; ?>"
                                            data-starts_at="<?php echo h(!empty($p['starts_at']) ? date('Y-m-d\TH:i', strtotime($p['starts_at'])) : ''); ?>"
                                            data-ends_at="<?php echo h(!empty($p['ends_at']) ? date('Y-m-d\TH:i', strtotime($p['ends_at'])) : ''); ?>"
                                        >
                                            <i class="bx bx-edit"></i> Edit
                                        </button>

                                        <form method="post" class="m-0">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                            <input type="hidden" name="to" value="<?php echo $isActive ? 0 : 1; ?>">
                                            <button type="submit" class="btn btn-outline-<?php echo $isActive ? 'warning' : 'success'; ?> btn-pill">
                                                <i class="bx <?php echo $isActive ? 'bx-pause-circle' : 'bx-play-circle'; ?>"></i>
                                                <?php echo $isActive ? 'Disable' : 'Enable'; ?>
                                            </button>
                                        </form>

                                        <form method="post" class="m-0" onsubmit="return confirm('Delete this promotion?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-pill">
                                                <i class="bx bx-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary rounded-pill">
            <i class='bx bx-arrow-back me-1'></i> Back to dashboard
        </a>

    </main>
</div>

<!-- =========================
     CREATE MODAL
========================= -->
<div class="modal fade details-modal" id="modalCreatePromo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0">Create Promotion</h5>
                        <small class="text-muted">Adds a new row to the <code>promotions</code> table.</small>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            <i class="bx bx-x"></i> Close
                        </button>
                        <button type="submit" class="btn btn-outline-success">
                            <i class="bx bx-save"></i> Save
                        </button>
                    </div>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-lg-8">
                            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input class="form-control" name="title" required>

                            <div class="mt-3">
                                <label class="form-label fw-semibold">Subtitle</label>
                                <input class="form-control" name="subtitle">
                            </div>

                            <div class="mt-3">
                                <label class="form-label fw-semibold">Description</label>
                                <textarea class="form-control" name="description" rows="4"></textarea>
                            </div>

                            <div class="row g-3 mt-0">
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Badge label</label>
                                    <input class="form-control" name="badge_label" placeholder="e.g. Limited time">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Active</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="createActive" checked>
                                        <label class="form-check-label" for="createActive">Enabled</label>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mt-0">
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Starts at</label>
                                    <input class="form-control" type="datetime-local" name="starts_at">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Ends at</label>
                                    <input class="form-control" type="datetime-local" name="ends_at">
                                </div>
                            </div>

                            <div class="row g-3 mt-0">
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">CTA label</label>
                                    <input class="form-control" name="cta_label" placeholder="e.g. Shop now">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">CTA link</label>
                                    <input class="form-control" name="cta_link" placeholder="e.g. shop.php?view=sale">
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-4">
                            <div class="border rounded-4 p-3">
                                <div class="fw-semibold mb-2">Image</div>

                                <label class="form-label">Upload image (recommended)</label>
                                <input class="form-control" type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp,.gif">

                                <div class="my-3 text-muted text-center">— or —</div>

                                <label class="form-label">Image URL / path</label>
                                <input class="form-control" name="image_url" placeholder="images/promos/banner.jpg">

                                <small class="text-muted d-block mt-2">
                                    Tip: If you upload, it will save to <code>uploads/promotions/</code> and override the URL field.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>

<!-- =========================
     EDIT MODAL (single modal)
========================= -->
<div class="modal fade details-modal" id="modalEditPromo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0">Edit Promotion</h5>
                        <small class="text-muted">Updates the selected row in <code>promotions</code>.</small>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            <i class="bx bx-x"></i> Close
                        </button>
                        <button type="submit" class="btn btn-outline-success">
                            <i class="bx bx-save"></i> Save changes
                        </button>
                    </div>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-lg-8">
                            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input class="form-control" name="title" id="edit_title" required>

                            <div class="mt-3">
                                <label class="form-label fw-semibold">Subtitle</label>
                                <input class="form-control" name="subtitle" id="edit_subtitle">
                            </div>

                            <div class="mt-3">
                                <label class="form-label fw-semibold">Description</label>
                                <textarea class="form-control" name="description" id="edit_description" rows="4"></textarea>
                            </div>

                            <div class="row g-3 mt-0">
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Badge label</label>
                                    <input class="form-control" name="badge_label" id="edit_badge">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Active</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                                        <label class="form-check-label" for="edit_is_active">Enabled</label>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mt-0">
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Starts at</label>
                                    <input class="form-control" type="datetime-local" name="starts_at" id="edit_starts_at">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Ends at</label>
                                    <input class="form-control" type="datetime-local" name="ends_at" id="edit_ends_at">
                                </div>
                            </div>

                            <div class="row g-3 mt-0">
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">CTA label</label>
                                    <input class="form-control" name="cta_label" id="edit_cta_label">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">CTA link</label>
                                    <input class="form-control" name="cta_link" id="edit_cta_link">
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-4">
                            <div class="border rounded-4 p-3">
                                <div class="fw-semibold mb-2">Image</div>

                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <img id="edit_image_preview" class="promo-thumb" src="" alt="preview">
                                    <div class="text-muted small" id="edit_image_label"></div>
                                </div>

                                <label class="form-label">Upload new image</label>
                                <input class="form-control" type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp,.gif">

                                <div class="my-3 text-muted text-center">— or —</div>

                                <label class="form-label">Image URL / path</label>
                                <input class="form-control" name="image_url" id="edit_image_url">

                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="edit_remove_image">
                                    <label class="form-check-label" for="edit_remove_image">Remove current image</label>
                                </div>

                                <small class="text-muted d-block mt-2">
                                    Upload overrides the URL field. If “Remove current image” is checked, image will be cleared.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* =========================
   Fill Edit Modal from row button
========================= */
const editModal = document.getElementById('modalEditPromo');
if (editModal) {
  editModal.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    if (!btn) return;

    const id         = btn.getAttribute('data-id') || '';
    const title      = btn.getAttribute('data-title') || '';
    const subtitle   = btn.getAttribute('data-subtitle') || '';
    const desc       = btn.getAttribute('data-description') || '';
    const badge      = btn.getAttribute('data-badge') || '';
    const image      = btn.getAttribute('data-image') || '';
    const ctaLabel   = btn.getAttribute('data-cta_label') || '';
    const ctaLink    = btn.getAttribute('data-cta_link') || '';
    const isActive   = btn.getAttribute('data-is_active') || '0';
    const startsAt   = btn.getAttribute('data-starts_at') || '';
    const endsAt     = btn.getAttribute('data-ends_at') || '';

    document.getElementById('edit_id').value = id;
    document.getElementById('edit_title').value = title;
    document.getElementById('edit_subtitle').value = subtitle;
    document.getElementById('edit_description').value = desc;
    document.getElementById('edit_badge').value = badge;
    document.getElementById('edit_cta_label').value = ctaLabel;
    document.getElementById('edit_cta_link').value = ctaLink;
    document.getElementById('edit_starts_at').value = startsAt;
    document.getElementById('edit_ends_at').value = endsAt;

    const activeEl = document.getElementById('edit_is_active');
    activeEl.checked = (parseInt(isActive, 10) === 1);

    const removeEl = document.getElementById('edit_remove_image');
    removeEl.checked = false;

    const imgUrlEl = document.getElementById('edit_image_url');
    imgUrlEl.value = image;

    const preview = document.getElementById('edit_image_preview');
    const label = document.getElementById('edit_image_label');

    if (image) {
      // if it is a local path like uploads/promotions/..., show from project root
      const src = image.startsWith('uploads/') ? ('../' + image.replace(/^\/+/, '')) : image;
      preview.src = src;
      label.textContent = image;
    } else {
      preview.src = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="80"><rect width="100%" height="100%" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#9ca3af" font-family="Arial" font-size="12">No image</text></svg>'
      );
      label.textContent = 'No image';
    }
  });
}

/* =========================
   Mobile sidebar toggle (reliable)
========================= */
function getSidebarEl(){
  const selectors = ['.admin-sidebar', '.sidebar', '#sidebar', 'aside.sidebar', 'nav.sidebar'];
  for (const s of selectors){
    const el = document.querySelector(s);
    if (el) return el;
  }
  return null;
}

function ensureSidebarCloseBtn(sidebar){
  if (!sidebar) return null;

  let btn = document.getElementById('adminSidebarClose');
  if (!btn){
    btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'adminSidebarClose';
    btn.className = 'sidebar-close-btn d-lg-none';
    btn.setAttribute('aria-label', 'Close menu');
    btn.innerHTML = "<i class='bx bx-x'></i>";
    sidebar.insertBefore(btn, sidebar.firstChild);
  }
  return btn;
}

function setupMobileSidebar(){
  const toggleBtn = document.getElementById('adminSidebarToggle') || document.getElementById('adminSidebarOpen');
  const backdrop  = document.getElementById('adminSidebarBackdrop');
  const sidebar   = getSidebarEl();
  if (!toggleBtn || !sidebar) return;

  const closeBtn = ensureSidebarCloseBtn(sidebar);
  const isMobile = () => window.matchMedia('(max-width: 991.98px)').matches;

  const open = () => {
    sidebar.classList.add('open','active','show');
    document.body.classList.add('sidebar-open');
    sidebar.style.display = 'block';
    sidebar.setAttribute('aria-hidden', 'false');
  };

  const close = () => {
    sidebar.classList.remove('open','active','show');
    document.body.classList.remove('sidebar-open');
    sidebar.setAttribute('aria-hidden', 'true');
  };

  toggleBtn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (!isMobile()) return;

    const opened = sidebar.classList.contains('open') || sidebar.classList.contains('active') || sidebar.classList.contains('show');
    opened ? close() : open();
  });

  if (closeBtn){
    closeBtn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      close();
    });
  }

  if (backdrop){
    backdrop.addEventListener('click', (e) => {
      e.preventDefault();
      close();
    });
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });

  sidebar.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => { if (isMobile()) close(); });
  });

  window.addEventListener('resize', () => {
    if (!isMobile()) close();
  });
}

document.addEventListener('DOMContentLoaded', setupMobileSidebar);
</script>

</body>
</html>
