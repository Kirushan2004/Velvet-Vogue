<?php
// admin/customer-ranking.php
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}

// Optional sorting: by total spent (default) or orders count
$allowedSort = ['spent', 'orders'];
$sort = isset($_GET['sort']) && in_array($_GET['sort'], $allowedSort, true)
    ? $_GET['sort']
    : 'spent';

$orderBySql = $sort === 'orders'
    ? 'orders_count DESC, total_spent DESC'
    : 'total_spent DESC, orders_count DESC';

// Optional filter by year (e.g. ?year=2024)
$yearFilter = isset($_GET['year']) && ctype_digit($_GET['year']) ? (int)$_GET['year'] : null;

// Build SQL
$sql = "
    SELECT
        c.id,
        c.full_name,
        c.email,
        c.city,
        c.state,
        c.country,
        COUNT(o.id) AS orders_count,
        COALESCE(SUM(o.total_amount),0.00) AS total_spent,
        MIN(o.created_at) AS first_order_at,
        MAX(o.created_at) AS last_order_at
    FROM orders o
    INNER JOIN customers c ON o.customer_id = c.id
    WHERE o.status IN ('paid','shipped','completed')
";

$params = [];
$types  = '';

if ($yearFilter !== null) {
    $sql   .= " AND YEAR(o.created_at) = ? ";
    $types .= 'i';
    $params[] = $yearFilter;
}

$sql .= "
    GROUP BY c.id, c.full_name, c.email, c.city, c.state, c.country
    ORDER BY {$orderBySql}
";

// Execute
$customers = [];
if ($types) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Ranking | Velvet Vogue Admin</title>
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
        .ranking-badge {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 600;
            color: #fff;
        }
        .ranking-1 { background: #FFD700; color: #111013; } /* gold */
        .ranking-2 { background: #C0C0C0; color: #111013; } /* silver */
        .ranking-3 { background: #CD7F32; color: #fff; }    /* bronze */
        .ranking-default { background: #6B1F4F; }

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
                <h1 class="admin-page-title">Customer Ranking</h1>
                <p class="admin-page-subtitle mb-0">
                    Top customers by total spent and orders count.
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
                <div>
                    <h2 class="admin-panel-title mb-0">Top Customers</h2>
                    <p class="admin-panel-subtitle mb-0">
                        Showing all customers with completed / paid / shipped orders.
                    </p>
                </div>

                <form class="d-flex flex-wrap gap-2" method="get">
                    <div class="input-group input-group-sm" style="width:auto; min-width: 170px;">
                        <span class="input-group-text">Year</span>
                        <input
                            type="number"
                            class="form-control"
                            name="year"
                            placeholder="All"
                            value="<?php echo $yearFilter ? (int)$yearFilter : ''; ?>"
                        >
                    </div>

                    <select name="sort" class="form-select form-select-sm" style="width:auto; min-width: 180px;">
                        <option value="spent"  <?php echo $sort === 'spent'  ? 'selected' : ''; ?>>Sort by total spent</option>
                        <option value="orders" <?php echo $sort === 'orders' ? 'selected' : ''; ?>>Sort by orders count</option>
                    </select>

                    <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill">
                        <i class='bx bx-filter-alt me-1'></i> Apply
                    </button>

                    <a href="customer-ranking.php" class="btn btn-sm btn-link">
                        Reset
                    </a>
                </form>
            </div>

            <div class="admin-panel-body">
                <?php if (empty($customers)): ?>
                    <p class="text-muted mb-0">
                        No ranking data yet. You need completed / paid / shipped orders.
                    </p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                            <tr>
                                <th style="width:70px;">Rank</th>
                                <th>Customer</th>
                                <th>Email</th>
                                <th>Location</th>
                                <th class="text-end">Orders</th>
                                <th class="text-end">Total spent</th>
                                <th class="text-end">First order</th>
                                <th class="text-end">Last order</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            $rank = 1;
                            foreach ($customers as $c):
                                $badgeClass = 'ranking-default';
                                if ($rank === 1) $badgeClass = 'ranking-1';
                                elseif ($rank === 2) $badgeClass = 'ranking-2';
                                elseif ($rank === 3) $badgeClass = 'ranking-3';

                                $locParts = [];
                                if (!empty($c['city']))   $locParts[] = $c['city'];
                                if (!empty($c['state']))  $locParts[] = $c['state'];
                                if (!empty($c['country']))$locParts[] = $c['country'];
                                $location = $locParts ? implode(', ', $locParts) : '—';

                                $firstOrder = $c['first_order_at']
                                    ? date('Y-m-d', strtotime($c['first_order_at']))
                                    : '—';
                                $lastOrder  = $c['last_order_at']
                                    ? date('Y-m-d', strtotime($c['last_order_at']))
                                    : '—';
                            ?>
                                <tr>
                                    <td>
                                        <span class="ranking-badge <?php echo $badgeClass; ?>">
                                            <?php echo $rank; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="customer-purchases.php?id=<?php echo (int)$c['id']; ?>">
                                            <?php echo htmlspecialchars($c['full_name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($c['email']); ?></td>
                                    <td><?php echo htmlspecialchars($location); ?></td>
                                    <td class="text-end"><?php echo (int)$c['orders_count']; ?></td>
                                    <td class="text-end"><?php echo money_fmt($c['total_spent']); ?></td>
                                    <td class="text-end"><?php echo htmlspecialchars($firstOrder); ?></td>
                                    <td class="text-end"><?php echo htmlspecialchars($lastOrder); ?></td>
                                </tr>
                            <?php
                                $rank++;
                            endforeach;
                            ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-3">
            <a href="customers.php" class="btn btn-sm btn-outline-secondary rounded-pill">
                <i class='bx bx-arrow-back me-1'></i> Back to customers
            </a>
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
