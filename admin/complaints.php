<?php
// admin/complaints.php
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// ---------- helpers ----------
function redirect_with_msg($url, $msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    header("Location: {$url}");
    exit;
}

function badge_for_status($status) {
    switch ($status) {
        case 'open':
            return '<span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle">Open</span>';
        case 'in_progress':
            return '<span class="badge rounded-pill bg-warning-subtle text-warning border border-warning-subtle">In progress</span>';
        case 'resolved':
            return '<span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle">Resolved</span>';
        default:
            return '<span class="badge rounded-pill bg-secondary-subtle text-secondary border border-secondary-subtle">' .
                   htmlspecialchars((string)$status) . '</span>';
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- HANDLE POST ACTIONS (status change) ----------
$allowed_statuses = ['open', 'in_progress', 'resolved'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status_id'], $_POST['new_status'])) {
        $id        = (int)$_POST['update_status_id'];
        $newStatus = (string)$_POST['new_status'];

        if (!in_array($newStatus, $allowed_statuses, true)) {
            redirect_with_msg('complaints.php', 'Invalid status provided.', 'danger');
        }

        $stmt = $conn->prepare("
            UPDATE complaints
            SET status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('si', $newStatus, $id);

        if ($stmt->execute()) {
            $stmt->close();
            redirect_with_msg('complaints.php', 'Complaint status updated.');
        } else {
            $stmt->close();
            redirect_with_msg('complaints.php', 'Could not update complaint status.', 'danger');
        }
    }
}

// ---------- FILTER & SEARCH ----------
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? ''; // '', 'open','in_progress','resolved'

$sql = "
    SELECT
        cmp.*,
        c.full_name    AS customer_name,
        c.email        AS customer_email,
        o.order_number AS order_number
    FROM complaints cmp
    LEFT JOIN customers c ON cmp.customer_id = c.id
    LEFT JOIN orders    o ON cmp.order_id    = o.id
    WHERE 1 = 1
";

$params = [];
$types  = '';

// status filter
if ($status !== '' && in_array($status, $allowed_statuses, true)) {
    $sql   .= " AND cmp.status = ?";
    $types .= 's';
    $params[] = $status;
}

// search in subject, message, customer name, order number, email
if ($search !== '') {
    $sql   .= " AND (
                    cmp.subject LIKE ?
                 OR cmp.message LIKE ?
                 OR c.full_name LIKE ?
                 OR c.email LIKE ?
                 OR o.order_number LIKE ?
               )";
    $like   = '%' . $search . '%';
    $types .= 'sssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY cmp.created_at DESC";

$complaints = [];
if ($types !== '') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($sql);
}

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $complaints[] = $row;
    }
    $res->free();
}
if (isset($stmt)) {
    $stmt->close();
}

// ---------- STATUS COUNTS ----------
$status_counts = [
    'open'        => 0,
    'in_progress' => 0,
    'resolved'    => 0,
];

$resCount = $conn->query("
    SELECT status, COUNT(*) AS cnt
    FROM complaints
    GROUP BY status
");
if ($resCount) {
    while ($row = $resCount->fetch_assoc()) {
        $st = (string)$row['status'];
        if (isset($status_counts[$st])) {
            $status_counts[$st] = (int)$row['cnt'];
        }
    }
    $resCount->free();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Complaints | Velvet Vogue Admin</title>
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
                <h1 class="admin-page-title">Complaints</h1>
                <p class="admin-page-subtitle mb-0">
                    Track and resolve customer complaints linked to orders.
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

        <!-- Top small stats row -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-4">
                <div class="admin-stat-card small-card">
                    <div class="admin-stat-icon soft tickets">
                        <i class='bx bxs-error-circle'></i>
                    </div>
                    <div class="admin-stat-body">
                        <p class="admin-stat-label">Open</p>
                        <h4 class="admin-stat-value"><?php echo number_format($status_counts['open']); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-4">
                <div class="admin-stat-card small-card">
                    <div class="admin-stat-icon soft orders">
                        <i class='bx bx-loader-circle'></i>
                    </div>
                    <div class="admin-stat-body">
                        <p class="admin-stat-label">In Progress</p>
                        <h4 class="admin-stat-value"><?php echo number_format($status_counts['in_progress']); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-4">
                <div class="admin-stat-card small-card">
                    <div class="admin-stat-icon soft customers">
                        <i class='bx bx-check-circle'></i>
                    </div>
                    <div class="admin-stat-body">
                        <p class="admin-stat-label">Resolved</p>
                        <h4 class="admin-stat-value"><?php echo number_format($status_counts['resolved']); ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Complaints list panel -->
        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2 class="admin-panel-title mb-0">Complaints list</h2>
            </div>
            <div class="admin-panel-body">

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                        <?php echo htmlspecialchars($flash['msg']); ?>
                    </div>
                <?php endif; ?>

                <!-- Filters -->
                <form class="row g-2 mb-3" method="get">
                    <div class="col-lg-6">
                        <input
                            type="text"
                            name="q"
                            class="form-control"
                            placeholder="Search by subject, message, customer, email, order number..."
                            value="<?php echo htmlspecialchars($search); ?>"
                        >
                    </div>
                    <div class="col-lg-3">
                        <select name="status" class="form-select">
                            <option value="">All statuses</option>
                            <option value="open"        <?php echo $status === 'open'        ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>In progress</option>
                            <option value="resolved"    <?php echo $status === 'resolved'    ? 'selected' : ''; ?>>Resolved</option>
                        </select>
                    </div>
                    <div class="col-lg-3 d-flex gap-2">
                        <button type="submit" class="btn btn-outline-secondary">
                            <i class='bx bx-search-alt-2 me-1'></i> Filter
                        </button>
                        <a href="complaints.php" class="btn btn-link">Reset</a>
                    </div>
                </form>

                <?php if (empty($complaints)): ?>
                    <p class="text-muted mb-0">No complaints found for the current filters.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Customer</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($complaints as $cmp): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <a href="complaint-view.php?id=<?php echo (int)$cmp['id']; ?>">
                                                <?php echo htmlspecialchars($cmp['subject']); ?>
                                            </a>
                                        </strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php
                                            $msg = (string)($cmp['message'] ?? '');
                                            $snippet = mb_substr($msg, 0, 80);
                                            if (mb_strlen($msg) > 80) $snippet .= '...';
                                            echo htmlspecialchars($snippet);
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if (!empty($cmp['customer_name'])): ?>
                                            <div><?php echo htmlspecialchars($cmp['customer_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($cmp['customer_email']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Guest / unknown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($cmp['order_number'])): ?>
                                            <span class="badge bg-light text-dark border">
                                                <?php echo htmlspecialchars($cmp['order_number']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">No order linked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo badge_for_status($cmp['status']); ?></td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($cmp['created_at']))); ?>
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <a href="complaint-view.php?id=<?php echo (int)$cmp['id']; ?>"
                                               class="btn btn-sm btn-light">
                                                <i class='bx bx-search-alt-2'></i>
                                            </a>

                                            <!-- quick status change dropdown -->
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                                        type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    Status
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <?php foreach ($allowed_statuses as $st): ?>
                                                        <li>
                                                            <form method="post" class="px-2 py-1">
                                                                <input type="hidden" name="update_status_id" value="<?php echo (int)$cmp['id']; ?>">
                                                                <input type="hidden" name="new_status" value="<?php echo htmlspecialchars($st); ?>">
                                                                <button type="submit" class="btn btn-link p-0 text-decoration-none">
                                                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $st))); ?>
                                                                </button>
                                                            </form>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>
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

document.addEventListener('DOMContentLoaded', setupMobileSidebar);
</script>
</body>
</html>
