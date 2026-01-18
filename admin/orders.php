<?php
// admin/orders.php
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

function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// allowed filters
$allowedStatus = ['pending','paid','shipped','completed','cancelled','refunded'];
$allowedPayment = ['cod','card','bank_transfer'];

// ---------- FILTER & SEARCH (GET) ----------
$search       = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status']   ?? '';
$paymentFilter= $_GET['payment']  ?? '';
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');

// ---------- LOAD ORDERS WITH FILTERS ----------
$sql = "
    SELECT
        o.*,
        c.full_name AS customer_name
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE 1=1
";

$params = [];
$types  = '';

// status filter
if ($statusFilter !== '' && in_array($statusFilter, $allowedStatus, true)) {
    $sql   .= " AND o.status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}

// payment filter
if ($paymentFilter !== '' && in_array($paymentFilter, $allowedPayment, true)) {
    $sql   .= " AND o.payment_method = ?";
    $types .= 's';
    $params[] = $paymentFilter;
}

// date range filters (by order created_at date)
if ($dateFrom !== '') {
    $sql   .= " AND DATE(o.created_at) >= ?";
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $sql   .= " AND DATE(o.created_at) <= ?";
    $types .= 's';
    $params[] = $dateTo;
}

// search text filter (order no, customer name, email, phone)
if ($search !== '') {
    $sql   .= " AND (o.order_number LIKE ?
                     OR o.full_name LIKE ?
                     OR o.email LIKE ?
                     OR o.phone LIKE ?)";
    $like  = '%' . $search . '%';
    $types .= 'ssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY o.created_at DESC";

$orders = [];
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
        $orders[] = $row;
    }
    $res->free();
}
if (isset($stmt)) {
    $stmt->close();
}

// helper for status badge
function order_status_badge($status) {
    $status = strtolower((string)$status);
    $class  = 'bg-secondary-subtle text-secondary border border-secondary-subtle';

    switch ($status) {
        case 'pending':
            $class = 'bg-warning-subtle text-warning border border-warning-subtle';
            break;
        case 'paid':
            $class = 'bg-info-subtle text-info border border-info-subtle';
            break;
        case 'shipped':
            $class = 'bg-primary-subtle text-primary border border-primary-subtle';
            break;
        case 'completed':
            $class = 'bg-success-subtle text-success border border-success-subtle';
            break;
        case 'cancelled':
        case 'refunded':
            $class = 'bg-danger-subtle text-danger border border-danger-subtle';
            break;
    }

    return '<span class="badge ' . $class . '">' . htmlspecialchars(ucfirst($status)) . '</span>';
}

// helper for payment label
function payment_label($m) {
    $m = strtolower((string)$m);
    switch ($m) {
        case 'cod':           return 'Cash on Delivery';
        case 'card':          return 'Card';
        case 'bank_transfer': return 'Bank Transfer';
        default:              return ucfirst($m);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Orders | Velvet Vogue Admin</title>
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
            body.admin-sidebar-open{
                overflow:hidden;
            }
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
                <h1 class="admin-page-title">Orders</h1>
                <p class="admin-page-subtitle mb-0">
                    View and manage all customer orders.
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

        <div class="admin-panel mb-4">
            <div class="admin-panel-header">
                <h2 class="admin-panel-title mb-0">Order list</h2>
            </div>

            <div class="admin-panel-body">
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                        <?php echo htmlspecialchars($flash['msg']); ?>
                    </div>
                <?php endif; ?>

                <!-- FILTER / SEARCH BAR -->
                <form class="row g-2 mt-3 mb-3" method="get">
                    <div class="col-lg-4">
                        <input
                            type="text"
                            name="q"
                            class="form-control"
                            placeholder="Search order no, customer name, email, phone..."
                            value="<?php echo htmlspecialchars($search); ?>"
                        >
                    </div>

                    <div class="col-lg-2">
                        <select name="status" class="form-select">
                            <option value="">All statuses</option>
                            <?php foreach ($allowedStatus as $st): ?>
                                <option value="<?php echo $st; ?>"
                                    <?php echo $statusFilter === $st ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($st); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-2">
                        <select name="payment" class="form-select">
                            <option value="">All payments</option>
                            <option value="cod"           <?php echo $paymentFilter === 'cod' ? 'selected' : ''; ?>>Cash on Delivery</option>
                            <option value="card"          <?php echo $paymentFilter === 'card' ? 'selected' : ''; ?>>Card</option>
                            <option value="bank_transfer" <?php echo $paymentFilter === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                        </select>
                    </div>

                    <div class="col-lg-2">
                        <input
                            type="date"
                            name="date_from"
                            class="form-control"
                            value="<?php echo htmlspecialchars($dateFrom); ?>"
                            placeholder="From"
                        >
                    </div>

                    <div class="col-lg-2">
                        <input
                            type="date"
                            name="date_to"
                            class="form-control"
                            value="<?php echo htmlspecialchars($dateTo); ?>"
                            placeholder="To"
                        >
                    </div>

                    <div class="col-12 mt-1 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-outline-secondary">
                            <i class='bx bx-search-alt-2 me-1'></i> Filter
                        </button>
                        <a href="orders.php" class="btn btn-link">Reset</a>
                    </div>
                </form>

                <?php if (empty($orders)): ?>
                    <p class="text-muted mb-0">No orders found for the current filters.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Date</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($orders as $o): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($o['order_number']); ?></strong></td>
                                    <td>
                                        <?php if (!empty($o['customer_id'])): ?>
                                            <a href="customer-edit.php?id=<?php echo (int)$o['customer_id']; ?>">
                                                <?php echo htmlspecialchars($o['customer_name'] ?: $o['full_name']); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($o['full_name']); ?>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($o['email']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?php echo htmlspecialchars(payment_label($o['payment_method'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo order_status_badge($o['status']); ?></td>
                                    <td class="text-end"><?php echo money_fmt($o['total_amount']); ?></td>
                                    <td class="text-end"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($o['created_at']))); ?></td>
                                    <td class="text-end">
                                        <a href="order-view.php?id=<?php echo (int)$o['id']; ?>" class="btn btn-sm btn-light">
                                            <i class='bx bx-show-alt'></i> View
                                        </a>
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

  // If user clicks a sidebar link on mobile, close menu
  if (sidebar){
    sidebar.querySelectorAll('a.nav-item').forEach(a => {
      a.addEventListener('click', () => {
        if (window.matchMedia('(max-width: 991.98px)').matches) close();
      });
    });
  }
}

document.addEventListener('DOMContentLoaded', () => {
  setupMobileSidebar();
});
</script>
</body>
</html>
