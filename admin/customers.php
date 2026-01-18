<?php
// admin/customers.php
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

// ---------- HANDLE POST ACTIONS (toggle active / delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Toggle active / inactive
    if (isset($_POST['toggle_active_id'], $_POST['new_status'])) {
        $id        = (int)$_POST['toggle_active_id'];
        $newStatus = (int)$_POST['new_status'];

        $stmt = $conn->prepare("UPDATE customers SET is_active = ? WHERE id = ?");
        $stmt->bind_param('ii', $newStatus, $id);
        if ($stmt->execute()) {
            redirect_with_msg('customers.php', 'Customer status updated.');
        } else {
            redirect_with_msg('customers.php', 'Could not update customer status.', 'danger');
        }
    }

    // Delete customer
    if (isset($_POST['delete_id'])) {
        $deleteId = (int)$_POST['delete_id'];

        // load customer to delete photo if possible
        $stmt = $conn->prepare("SELECT profile_photo FROM customers WHERE id = ?");
        $stmt->bind_param('i', $deleteId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        try {
            $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->bind_param('i', $deleteId);
            if ($stmt->execute()) {
                // delete profile photo only if in uploads/customers
                if ($row && !empty($row['profile_photo']) &&
                    str_starts_with($row['profile_photo'], 'uploads/customers/')) {
                    $old = __DIR__ . '/../' . $row['profile_photo'];
                    if (is_file($old)) {
                        @unlink($old);
                    }
                }
                redirect_with_msg('customers.php', 'Customer deleted successfully.');
            } else {
                redirect_with_msg(
                    'customers.php',
                    'Unable to delete this customer because they have orders or related records.',
                    'danger'
                );
            }
        } catch (Throwable $e) {
            redirect_with_msg(
                'customers.php',
                'Unable to delete this customer because they have orders or related records.',
                'danger'
            );
        }
    }
}

// ---------- FILTER & SEARCH (GET) ----------
$search      = trim($_GET['q'] ?? '');
$status      = $_GET['status'] ?? ''; // '', 'active', 'inactive'
$genderFilter = $_GET['gender'] ?? ''; // '', 'male','female','other','prefer_not_say'

$allowedStatus = ['active', 'inactive'];
$allowedGender = ['male', 'female', 'other', 'prefer_not_say'];

// ---------- LOAD CUSTOMERS WITH FILTERS ----------
$sql = "
    SELECT 
        id,
        full_name,
        email,
        phone,
        gender,
        city,
        state,
        country,
        profile_photo,
        is_active,
        created_at
    FROM customers
    WHERE 1=1
";

$params = [];
$types  = '';

// status filter
if (in_array($status, $allowedStatus, true)) {
    if ($status === 'active') {
        $sql .= " AND is_active = 1";
    } elseif ($status === 'inactive') {
        $sql .= " AND is_active = 0";
    }
}

// gender filter
if (in_array($genderFilter, $allowedGender, true)) {
    $sql   .= " AND gender = ?";
    $types .= 's';
    $params[] = $genderFilter;
}

// text search filter
if ($search !== '') {
    $sql   .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR city LIKE ? OR state LIKE ? OR country LIKE ?)";
    $like  = '%' . $search . '%';
    $types .= 'ssssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY created_at DESC";

$customers = [];
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
        $customers[] = $row;
    }
    $res->free();
}
if (isset($stmt)) {
    $stmt->close();
}

// ---------- TOP CUSTOMERS (RANKING PANEL) ----------
$top_customers = [];

$sqlTop = "
    SELECT 
        c.id,
        c.full_name,
        c.email,
        COUNT(o.id) AS orders_count,
        COALESCE(SUM(o.total_amount),0.00) AS total_spent
    FROM orders o
    INNER JOIN customers c ON o.customer_id = c.id
    WHERE o.status IN ('paid','shipped','completed')
    GROUP BY c.id, c.full_name, c.email
    ORDER BY total_spent DESC
    LIMIT 10
";
if ($resTop = $conn->query($sqlTop)) {
    while ($row = $resTop->fetch_assoc()) {
        $top_customers[] = $row;
    }
    $resTop->free();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customers | Velvet Vogue Admin</title>
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
        .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            font-weight: 600;
            color: #ffffff;
            background: #6B1F4F;
            text-transform: uppercase;
            margin-right: 0.5rem;
            overflow: hidden;
        }
        .customer-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
                <h1 class="admin-page-title">Customers</h1>
                <p class="admin-page-subtitle mb-0">
                    Manage your customer accounts and view top customers.
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

        <!-- MAIN CUSTOMERS TABLE -->
        <div class="admin-panel mb-4">
            <div class="admin-panel-header">
                <h2 class="admin-panel-title mb-0">Customer list</h2>
                <a href="customer-edit.php" class="btn btn-primary rounded-pill">
                    <i class='bx bx-plus me-1'></i> Add customer
                </a>
            </div>

            <div class="admin-panel-body">
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                        <?php echo htmlspecialchars($flash['msg']); ?>
                    </div>
                <?php endif; ?>

                <!-- FILTER / SEARCH BAR -->
                <form class="row g-2 mt-3 mb-3" method="get">
                    <div class="col-md-4">
                        <input
                            type="text"
                            name="q"
                            class="form-control"
                            placeholder="Search name, email, phone, city..."
                            value="<?php echo htmlspecialchars($search); ?>"
                        >
                    </div>

                    <div class="col-md-3">
                        <select name="status" class="form-select">
                            <option value="">All statuses</option>
                            <option value="active"   <?php echo $status === 'active'   ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <select name="gender" class="form-select">
                            <option value="">All genders</option>
                            <option value="male"   <?php echo $genderFilter === 'male'   ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo $genderFilter === 'female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>

                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-outline-secondary w-100">
                            <i class='bx bx-search-alt-2 me-1'></i> Filter
                        </button>
                        <a href="customers.php" class="btn btn-link">
                            Reset
                        </a>
                    </div>
                </form>

                <?php if (empty($customers)): ?>
                    <p class="text-muted mb-0">No customers found for the current filters.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Location</th>
                                <th>Gender</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($customers as $c): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="customer-avatar">
                                                <?php if (!empty($c['profile_photo'])): ?>
                                                    <img src="<?php echo '../' . htmlspecialchars($c['profile_photo']); ?>" alt="">
                                                <?php else:
                                                    $initial = strtoupper(mb_substr($c['full_name'], 0, 1));
                                                    echo htmlspecialchars($initial);
                                                endif; ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($c['full_name']); ?></strong><br>
                                                <small class="text-muted">
                                                    ID: <?php echo (int)$c['id']; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($c['email']); ?></td>
                                    <td><?php echo htmlspecialchars($c['phone'] ?? '—'); ?></td>
                                    <td>
                                        <?php
                                        $locParts = [];
                                        if (!empty($c['city']))   $locParts[] = $c['city'];
                                        if (!empty($c['state']))  $locParts[] = $c['state'];
                                        if (!empty($c['country']))$locParts[] = $c['country'];
                                        echo htmlspecialchars($locParts ? implode(', ', $locParts) : '—');
                                        ?>
                                    </td>
                                    <td class="text-capitalize"><?php echo htmlspecialchars($c['gender'] ?? '—'); ?></td>
                                    <td>
                                        <?php if ($c['is_active']): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <a href="customer-edit.php?id=<?php echo (int)$c['id']; ?>"
                                               class="btn btn-sm btn-light">
                                                <i class='bx bx-user'></i>
                                            </a>

                                            <form method="post" class="d-inline"
                                                  onsubmit="return confirm('Delete this customer? This may fail if they have orders.');">
                                                <input type="hidden" name="delete_id" value="<?php echo (int)$c['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class='bx bx-trash'></i>
                                                </button>
                                            </form>

                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="toggle_active_id" value="<?php echo (int)$c['id']; ?>">
                                                <input type="hidden" name="new_status"
                                                       value="<?php echo $c['is_active'] ? 0 : 1; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                    <?php if ($c['is_active']): ?>
                                                        <i class='bx bx-user-x'></i>
                                                    <?php else: ?>
                                                        <i class='bx bx-user-check'></i>
                                                    <?php endif; ?>
                                                </button>
                                            </form>
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

        <!-- TOP CUSTOMERS (RANKING PANEL) -->
        <div class="admin-panel">
            <div class="admin-panel-header">
                <div>
                    <h2 class="admin-panel-title mb-0">Top Customers</h2>
                    <p class="admin-panel-subtitle mb-0">
                        Based on total amount spent (paid / shipped / completed orders).
                    </p>
                </div>
                <a href="customer-ranking.php" class="btn btn-sm btn-outline-secondary rounded-pill">
                    View detailed ranking
                </a>
            </div>
            <div class="admin-panel-body">
                <?php if (empty($top_customers)): ?>
                    <p class="text-muted mb-0">No order history yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Email</th>
                                <th class="text-end">Orders</th>
                                <th class="text-end">Total spent</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php $rank = 1; ?>
                            <?php foreach ($top_customers as $tc): ?>
                                <tr>
                                    <td><?php echo $rank++; ?></td>
                                    <td>
                                        <a href="customer-purchases.php?id=<?php echo (int)$tc['id']; ?>">
                                            <?php echo htmlspecialchars($tc['full_name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($tc['email']); ?></td>
                                    <td class="text-end"><?php echo (int)$tc['orders_count']; ?></td>
                                    <td class="text-end"><?php echo money_fmt($tc['total_spent']); ?></td>
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

document.addEventListener('DOMContentLoaded', () => {
  setupMobileSidebar();
});
</script>
</body>
</html>
