<?php
// admin/customer-purchases.php
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// ----- helpers -----
function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    header('Location: customers.php');
    exit;
}
$customerId = (int)$_GET['id'];

// ----- load customer + high level stats -----
$sqlCustomer = "
    SELECT 
        c.*,
        COUNT(o.id) AS orders_count,
        COALESCE(SUM(o.total_amount),0.00) AS total_spent,
        MIN(o.created_at) AS first_order_at,
        MAX(o.created_at) AS last_order_at
    FROM customers c
    LEFT JOIN orders o
        ON o.customer_id = c.id
       AND o.status IN ('paid','shipped','completed')
    WHERE c.id = ?
    GROUP BY c.id
";

$stmt = $conn->prepare($sqlCustomer);
$stmt->bind_param('i', $customerId);
$stmt->execute();
$res = $stmt->get_result();
$customer = $res->fetch_assoc();
$stmt->close();

if (!$customer) {
    header('Location: customers.php');
    exit;
}

// ----- pie chart data: category split (using products + categories) -----
$chart_labels = [];
$chart_values = [];

$sqlPie = "
    SELECT 
        COALESCE(cat.name, 'Uncategorized') AS category_label,
        COALESCE(SUM(oi.line_total),0.00)   AS total_spent
    FROM order_items oi
    INNER JOIN orders o   ON oi.order_id = o.id
    LEFT JOIN products p  ON oi.product_id = p.id
    LEFT JOIN categories cat ON p.category_id = cat.id
    WHERE o.customer_id = ?
      AND o.status IN ('paid','shipped','completed')
    GROUP BY category_label
    ORDER BY total_spent DESC
";

$stmt = $conn->prepare($sqlPie);
$stmt->bind_param('i', $customerId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $chart_labels[] = $row['category_label'];
    $chart_values[] = round((float)$row['total_spent'], 2);
}
$stmt->close();

// ----- product list: name, category, size, qty, price -----
$lines = [];

$sqlLines = "
    SELECT
        oi.product_id,
        oi.product_name,
        COALESCE(cat.name, 'Uncategorized') AS category_name,
        oi.size,
        oi.color,
        SUM(oi.quantity)                     AS total_qty,
        oi.unit_price,
        COALESCE(SUM(oi.line_total),0.00)    AS total_spent
    FROM order_items oi
    INNER JOIN orders o   ON oi.order_id = o.id
    LEFT JOIN products p  ON oi.product_id = p.id
    LEFT JOIN categories cat ON p.category_id = cat.id
    WHERE o.customer_id = ?
      AND o.status IN ('paid','shipped','completed')
    GROUP BY
        oi.product_id,
        oi.product_name,
        category_name,
        oi.size,
        oi.color,
        oi.unit_price
    ORDER BY total_spent DESC
";

$stmt = $conn->prepare($sqlLines);
$stmt->bind_param('i', $customerId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $lines[] = $row;
}
$stmt->close();

// Total from orders
$totalSpent = (float)$customer['total_spent'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Purchases | Velvet Vogue Admin</title>
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

        /* optional: keep the purchase table body usable in shorter screens */
        .purchase-table-wrapper{
            min-height: 180px;
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

            <div class="d-flex align-items-center">
                <div class="customer-avatar">
                    <?php
                    // If you later store profile_photo for customers, show it here instead of initial.
                    $initial = strtoupper(mb_substr($customer['full_name'], 0, 1));
                    echo htmlspecialchars($initial);
                    ?>
                </div>
                <div>
                    <h1 class="admin-page-title mb-1">
                        <?php echo htmlspecialchars($customer['full_name']); ?>
                    </h1>
                    <p class="admin-page-subtitle mb-0">
                        Purchases breakdown · <?php echo htmlspecialchars($customer['email']); ?>
                    </p>
                </div>
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

        <!-- Top stats for this customer (same height cards) -->
        <div class="row g-3 mb-4 customer-summary-row">
            <div class="col-6 col-lg-3">
                <div class="admin-stat-card small-card">
                    <div class="admin-stat-icon soft customers">
                        <i class='bx bxs-user-detail'></i>
                    </div>
                    <div class="admin-stat-body">
                        <p class="admin-stat-label">Orders</p>
                        <h4 class="admin-stat-value">
                            <?php echo (int)$customer['orders_count']; ?>
                        </h4>
                        <p class="admin-stat-hint">
                            Completed / paid / shipped
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="admin-stat-card small-card">
                    <div class="admin-stat-icon soft orders">
                        <i class='bx bxs-cart'></i>
                    </div>
                    <div class="admin-stat-body">
                        <p class="admin-stat-label">Total spent</p>
                        <h4 class="admin-stat-value">
                            <?php echo money_fmt($totalSpent); ?>
                        </h4>
                        <p class="admin-stat-hint">
                            Across all qualifying orders
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="admin-stat-card small-card">
                    <div class="admin-stat-icon soft products">
                        <i class='bx bxs-calendar-event'></i>
                    </div>
                    <div class="admin-stat-body">
                        <p class="admin-stat-label">First order</p>
                        <h4 class="admin-stat-value">
                            <?php echo $customer['first_order_at'] ? date('Y-m-d', strtotime($customer['first_order_at'])) : '—'; ?>
                        </h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="admin-stat-card small-card">
                    <div class="admin-stat-icon soft tickets">
                        <i class='bx bxs-time'></i>
                    </div>
                    <div class="admin-stat-body">
                        <p class="admin-stat-label">Last order</p>
                        <h4 class="admin-stat-value">
                            <?php echo $customer['last_order_at'] ? date('Y-m-d', strtotime($customer['last_order_at'])) : '—'; ?>
                        </h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chart + products list (same height panels) -->
        <div class="row g-4 customer-panels-row align-items-stretch">
            <!-- PIE CHART -->
            <div class="col-lg-5">
                <div class="admin-panel h-100">
                    <div class="admin-panel-header">
                        <div>
                            <h2 class="admin-panel-title mb-0">Product types</h2>
                            <p class="admin-panel-subtitle mb-0">
                                Distribution by category (amount spent)
                            </p>
                        </div>
                    </div>
                    <div class="admin-panel-body">
                        <?php if (empty($chart_labels)): ?>
                            <p class="text-muted mb-0">
                                This customer has no completed / paid / shipped orders yet.
                            </p>
                        <?php else: ?>
                            <canvas id="customerPieChart" height="260"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- PRODUCTS TABLE -->
            <div class="col-lg-7">
                <div class="admin-panel h-100">
                    <div class="admin-panel-header">
                        <div>
                            <h2 class="admin-panel-title mb-0">Purchased products</h2>
                            <p class="admin-panel-subtitle mb-0">
                                Items grouped by product, size &amp; color.
                            </p>
                        </div>
                    </div>
                    <div class="admin-panel-body d-flex flex-column h-100">
                        <?php if (empty($lines)): ?>
                            <p class="text-muted mb-0">No line items found for this customer.</p>
                        <?php else: ?>
                            <div class="purchase-table-wrapper flex-grow-1">
                                <div class="table-responsive">
                                    <table class="table align-middle">
                                        <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Category</th>
                                            <th>Size</th>
                                            <th>Color</th>
                                            <th class="text-end">Qty</th>
                                            <th class="text-end">Unit price</th>
                                            <th class="text-end">Line total</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        $tableTotal = 0.0;
                                        foreach ($lines as $line):
                                            $rowTotal = (float)$line['total_spent'];
                                            $tableTotal += $rowTotal;
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($line['product_name']); ?></td>
                                                <td><?php echo htmlspecialchars($line['category_name'] ?: '—'); ?></td>
                                                <td><?php echo htmlspecialchars($line['size'] ?: '—'); ?></td>
                                                <td><?php echo htmlspecialchars($line['color'] ?: '—'); ?></td>
                                                <td class="text-end"><?php echo (int)$line['total_qty']; ?></td>
                                                <td class="text-end"><?php echo money_fmt($line['unit_price']); ?></td>
                                                <td class="text-end"><?php echo money_fmt($rowTotal); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="mt-2">
                                <div class="d-flex justify-content-end">
                                    <div class="text-end">
                                        <div><strong>Total spent (from lines):</strong>
                                            <?php echo money_fmt($tableTotal); ?>
                                        </div>
                                        <div><strong>Total spent (order totals):</strong>
                                            <?php echo money_fmt($totalSpent); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- back link -->
        <div class="mt-3">
            <a href="customers.php" class="btn btn-sm btn-outline-secondary rounded-pill">
                <i class='bx bx-arrow-back me-1'></i> Back to customers
            </a>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

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

/* ============ Chart ============ */
document.addEventListener('DOMContentLoaded', function () {
    setupMobileSidebar();

    const labels = <?php echo json_encode($chart_labels); ?>;
    const values = <?php echo json_encode($chart_values); ?>;

    if (labels.length && document.getElementById('customerPieChart')) {
        const ctx = document.getElementById('customerPieChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: values
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                return label + ': ' + value.toLocaleString(undefined, {
                                    style: 'currency',
                                    currency: 'USD'
                                });
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>
</body>
</html>
