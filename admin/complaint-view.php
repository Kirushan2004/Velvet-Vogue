<?php
// admin/complaint-view.php
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// helpers
function clean_id($v) {
    return isset($v) && ctype_digit($v) ? (int)$v : 0;
}
function h($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// get complaint id
$complaint_id = clean_id($_GET['id'] ?? '');
if ($complaint_id <= 0) {
    header('Location: complaints.php');
    exit;
}

// load complaint + customer
$sql = "
    SELECT
        cm.*,
        c.full_name AS customer_name,
        c.email     AS customer_email,
        c.phone     AS customer_phone
    FROM complaints cm
    LEFT JOIN customers c ON cm.customer_id = c.id
    WHERE cm.id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $complaint_id);
$stmt->execute();
$res        = $stmt->get_result();
$complaint  = $res->fetch_assoc();
$stmt->close();

if (!$complaint) {
    header('Location: complaints.php');
    exit;
}

// attachment (photo) path
$attachment_rel = $complaint['attachment_path'] ?? '';
$hasAttachment  = !empty($attachment_rel);
$attachment_src = $hasAttachment ? '../' . $attachment_rel : '';

// IMPORTANT: complaints table has status + admin_reply.
// We will use admin_reply as "Admin notes" field in UI.
$status       = $complaint['status'] ?? 'open';
$priority     = $complaint['priority'] ?? 'normal';   // requires the DB column added
$created_at   = $complaint['created_at'] ?? null;
$updated_at   = $complaint['updated_at'] ?? null;

// handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {

    $newStatus   = $_POST['status'] ?? $status;
    $newPriority = $_POST['priority'] ?? $priority;
    $adminReply  = trim($_POST['admin_notes'] ?? ($complaint['admin_reply'] ?? ''));

    // Validate status
    $allowedStatuses = ['open','in_progress','resolved']; // your DB enum
    if (!in_array($newStatus, $allowedStatuses, true)) {
        $newStatus = 'open';
    }

    // Validate priority
    $allowedPriorities = ['low','normal','high','urgent'];
    if (!in_array($newPriority, $allowedPriorities, true)) {
        $newPriority = 'normal';
    }

    // Update (admin_reply exists in DB; priority exists after ALTER TABLE)
    $stmt = $conn->prepare("
        UPDATE complaints
        SET status = ?, priority = ?, admin_reply = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('sssi', $newStatus, $newPriority, $adminReply, $complaint_id);
    $stmt->execute();
    $stmt->close();

    header('Location: complaint-view.php?id=' . $complaint_id);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Complaint #<?php echo (int)$complaint_id; ?> | Velvet Vogue Admin</title>
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
        .complaint-attachment-box {
            width: 180px;
            height: 180px;
            border-radius: 16px;
            border: 1px dashed #ddd;
            background-color: #fafafa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            cursor: pointer;
        }
        .complaint-attachment-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .complaint-attachment-placeholder {
            font-size: 2.5rem;
            color: #c7c7c7;
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

        /* ======= shared preview modal style (same as product/customer) ======= */
        .image-preview-modal .modal-dialog { max-width: 720px; }
        .image-preview-modal-content{
            border-radius: 18px;
            overflow: hidden;
            border: none;
        }
        .image-preview-body{
            padding: 0.75rem;
            background: #ffffff;
        }
        .image-preview-img{
            display:block;
            width:100%;
            height:auto;
            max-height: 75vh;
            object-fit: contain;
        }
        .image-preview-close{
            position:absolute;
            top:0.35rem;
            right:0.5rem;
            z-index:5;
        }
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
                    Complaint #<?php echo (int)$complaint_id; ?>
                </h1>
                <p class="admin-page-subtitle mb-0">
                    <?php echo h($complaint['subject'] ?? 'Customer complaint'); ?>
                </p>
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
        <div class="row g-4">
            <!-- Complaint details + message -->
            <div class="col-lg-8">
                <div class="admin-panel mb-4">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title mb-0">Complaint details</h2>
                    </div>
                    <div class="admin-panel-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-3">Customer</dt>
                            <dd class="col-sm-9">
                                <?php echo h($complaint['customer_name'] ?? 'Unknown'); ?><br>
                                <small class="text-muted">
                                    <?php echo h($complaint['customer_email'] ?? ''); ?>
                                    <?php if (!empty($complaint['customer_phone'])): ?>
                                        · <?php echo h($complaint['customer_phone']); ?>
                                    <?php endif; ?>
                                </small>
                            </dd>

                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9 text-capitalize"><?php echo h($status); ?></dd>

                            <dt class="col-sm-3">Priority</dt>
                            <dd class="col-sm-9 text-capitalize"><?php echo h($priority); ?></dd>

                            <dt class="col-sm-3">Created</dt>
                            <dd class="col-sm-9">
                                <?php echo $created_at ? h(date('Y-m-d H:i', strtotime($created_at))) : '—'; ?>
                            </dd>

                            <dt class="col-sm-3">Last updated</dt>
                            <dd class="col-sm-9">
                                <?php echo $updated_at ? h(date('Y-m-d H:i', strtotime($updated_at))) : '—'; ?>
                            </dd>

                            <?php if (!empty($complaint['order_id'])): ?>
                                <dt class="col-sm-3">Order</dt>
                                <dd class="col-sm-9">
                                    <a href="order-view.php?id=<?php echo (int)$complaint['order_id']; ?>">
                                        View related order
                                    </a>
                                </dd>
                            <?php endif; ?>
                        </dl>

                        <hr>

                        <h6 class="mb-2">Message</h6>
                        <p class="mb-0" style="white-space: pre-wrap;">
                            <?php echo h($complaint['message'] ?? ''); ?>
                        </p>
                    </div>
                </div>

                <!-- Admin notes / status form -->
                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title mb-0">Update complaint</h2>
                    </div>
                    <div class="admin-panel-body">
                        <form method="post">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <?php
                                        $statusOptions = [
                                            'open'        => 'Open',
                                            'in_progress' => 'In progress',
                                            'resolved'    => 'Resolved',
                                        ];
                                        foreach ($statusOptions as $value => $label):
                                        ?>
                                            <option value="<?php echo h($value); ?>" <?php echo ($status === $value) ? 'selected' : ''; ?>>
                                                <?php echo h($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Priority</label>
                                    <select name="priority" class="form-select">
                                        <?php
                                        $priorityOptions = [
                                            'low'    => 'Low',
                                            'normal' => 'Normal',
                                            'high'   => 'High',
                                            'urgent' => 'Urgent',
                                        ];
                                        foreach ($priorityOptions as $value => $label):
                                        ?>
                                            <option value="<?php echo h($value); ?>" <?php echo ($priority === $value) ? 'selected' : ''; ?>>
                                                <?php echo h($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Admin notes</label>
                                    <textarea name="admin_notes" rows="3" class="form-control"><?php echo h($complaint['admin_reply'] ?? ''); ?></textarea>
                                </div>

                                <div class="col-12 mt-2">
                                    <button type="submit" name="update_status" class="btn btn-primary rounded-pill">
                                        <i class="bx bx-save me-1"></i> Save changes
                                    </button>
                                    <a href="complaints.php" class="btn btn-link">Back to complaints</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Attachment -->
            <div class="col-lg-4">
                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title mb-0">Attachment</h2>
                    </div>
                    <div class="admin-panel-body">
                        <?php if (!$hasAttachment): ?>
                            <p class="text-muted mb-0">No attachment was uploaded for this complaint.</p>
                        <?php else: ?>
                            <p class="small text-muted mb-2">
                                Click the image to view a larger version.
                            </p>

                            <div id="complaintAttachmentBox" class="complaint-attachment-box mb-2">
                                <img id="complaintAttachmentPreview"
                                     src="<?php echo h($attachment_src); ?>"
                                     alt="Complaint attachment">
                            </div>

                            <a href="<?php echo h($attachment_src); ?>" target="_blank" class="small text-decoration-none">
                                Open in new tab
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- MODAL: large attachment preview -->
<div class="modal fade image-preview-modal" id="complaintAttachmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content image-preview-modal-content">
            <button type="button" class="btn-close image-preview-close" aria-label="Close"></button>
            <div class="modal-body image-preview-body">
                <img id="complaintAttachmentModalImg"
                     src="<?php echo $hasAttachment ? h($attachment_src) : ''; ?>"
                     alt="Complaint attachment large preview"
                     class="img-fluid image-preview-img">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* ============ Mobile sidebar menu ============ */
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

/* ============ Attachment modal preview ============ */
function setupAttachmentModal(){
  const box     = document.getElementById('complaintAttachmentBox');
  const modalEl = document.getElementById('complaintAttachmentModal');
  const modalImg= document.getElementById('complaintAttachmentModalImg');
  const preview = document.getElementById('complaintAttachmentPreview');
  const closeBtn= modalEl ? modalEl.querySelector('.image-preview-close') : null;

  if (!box || !modalEl || !modalImg || !preview) return;

  const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);

  box.addEventListener('click', function () {
    if (!preview.src) return;
    modalImg.src = preview.src;
    modalInstance.show();
  });

  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      modalInstance.hide();
    });
  }
}

document.addEventListener('DOMContentLoaded', function () {
  setupMobileSidebar();
  setupAttachmentModal();
});
</script>
</body>
</html>
