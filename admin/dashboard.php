<?php
// admin/dashboard.php
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
$admin_name = $_SESSION['admin_name'] ?? 'Admin';

function money_fmt($v) { return '$' . number_format((float)$v, 2); }

function getPeriodFilter(string $period): array {
    $allowed = ['last_30', 'last_90', 'this_year', 'all'];
    if (!in_array($period, $allowed, true)) $period = 'last_90';

    $dateFilterSql = '';
    $ticketFilterSql = '';
    $label = '';

    switch ($period) {
        case 'last_30':
            $dateFilterSql = " AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            $ticketFilterSql = " AND st.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            $label = 'Last 30 days';
            break;
        case 'last_90':
            $dateFilterSql = " AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
            $ticketFilterSql = " AND st.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
            $label = 'Last 90 days';
            break;
        case 'this_year':
            $dateFilterSql = " AND YEAR(o.created_at) = YEAR(CURDATE())";
            $ticketFilterSql = " AND YEAR(st.created_at) = YEAR(CURDATE())";
            $label = 'This year';
            break;
        default:
            $label = 'All time';
            break;
    }

    return [$period, $dateFilterSql, $ticketFilterSql, $label];
}

$period = $_GET['period'] ?? 'last_90';
[$period, $dateFilterSql, $ticketDateFilterSql, $periodLabel] = getPeriodFilter($period);

/* ---------------- Summary cards ---------------- */
$revenue_total = 0.0;
$cost_total    = 0.0;

$sqlSummary = "
    SELECT
        COALESCE(SUM(oi.line_total), 0) AS revenue,
        COALESCE(SUM(oi.line_cost), 0)  AS cost
    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.id
    WHERE o.status IN ('paid','shipped','completed')
    {$dateFilterSql}
";
if ($res = $conn->query($sqlSummary)) {
    if ($row = $res->fetch_assoc()) {
        $revenue_total = (float)$row['revenue'];
        $cost_total    = (float)$row['cost'];
    }
    $res->free();
}

$profit_total  = $revenue_total - $cost_total;
$profit_margin = $revenue_total > 0 ? ($profit_total / $revenue_total) * 100 : 0.0;

$total_orders_period = 0;
$sqlOrders = "
    SELECT COUNT(*) AS cnt
    FROM orders o
    WHERE o.status IN ('paid','shipped','completed')
    {$dateFilterSql}
";
if ($res = $conn->query($sqlOrders)) {
    if ($row = $res->fetch_assoc()) $total_orders_period = (int)$row['cnt'];
    $res->free();
}

$active_customers_filtered = 0;
$sqlActiveCustomers = "
    SELECT COUNT(DISTINCT c.id) AS cnt
    FROM customers c
    INNER JOIN orders o ON o.customer_id = c.id
    WHERE o.status IN ('paid','shipped','completed')
    {$dateFilterSql}
";
if ($res = $conn->query($sqlActiveCustomers)) {
    if ($row = $res->fetch_assoc()) $active_customers_filtered = (int)$row['cnt'];
    $res->free();
}

$active_products_filtered = 0;
$sqlActiveProducts = "
    SELECT COUNT(DISTINCT p.id) AS cnt
    FROM products p
    INNER JOIN order_items oi ON oi.product_id = p.id
    INNER JOIN orders o       ON oi.order_id   = o.id
    WHERE o.status IN ('paid','shipped','completed')
    {$dateFilterSql}
";
if ($res = $conn->query($sqlActiveProducts)) {
    if ($row = $res->fetch_assoc()) $active_products_filtered = (int)$row['cnt'];
    $res->free();
}

$open_tickets_filtered = 0;
$sqlTickets = "
    SELECT COUNT(*) AS cnt
    FROM support_tickets st
    WHERE st.status IN ('new','in_progress')
    {$ticketDateFilterSql}
";
if ($res = $conn->query($sqlTickets)) {
    if ($row = $res->fetch_assoc()) $open_tickets_filtered = (int)$row['cnt'];
    $res->free();
}

/* ---------------- Revenue vs Profit chart ---------------- */
$chart_labels  = [];
$chart_revenue = [];
$chart_profit  = [];
$chart_cost    = [];
$chart_detail  = [];

$sqlChart = "
    SELECT
        DATE_FORMAT(o.created_at, '%Y-%m') AS ym,
        COALESCE(SUM(oi.line_total), 0)   AS revenue,
        COALESCE(SUM(oi.line_cost), 0)    AS cost
    FROM orders o
    INNER JOIN order_items oi ON o.id = oi.order_id
    WHERE o.status IN ('paid','shipped','completed')
    {$dateFilterSql}
    GROUP BY ym
    ORDER BY ym
";

if ($res = $conn->query($sqlChart)) {
    while ($row = $res->fetch_assoc()) {
        $rev  = (float)$row['revenue'];
        $cost = (float)$row['cost'];
        $prof = $rev - $cost;

        $dt = DateTime::createFromFormat('Y-m', $row['ym']);
        $label = $dt ? $dt->format('M Y') : $row['ym'];

        $chart_labels[]  = $label;
        $chart_revenue[] = round($rev, 2);
        $chart_cost[]    = round($cost, 2);
        $chart_profit[]  = round($prof, 2);

        $chart_detail[] = [
            'ym'     => $row['ym'],
            'label'  => $label,
            'revenue'=> round($rev, 2),
            'cost'   => round($cost, 2),
            'profit' => round($prof, 2),
        ];
    }
    $res->free();
}

/* ---------------- Category chart ---------------- */
$pie_labels = [];
$pie_values = [];
$pie_detail = [];

$sqlPie = "
    SELECT
        COALESCE(cat.name, 'Uncategorized') AS category_label,
        COALESCE(SUM(oi.quantity), 0)       AS total_sold,
        COALESCE(SUM(oi.line_total), 0)     AS total_revenue
    FROM order_items oi
    INNER JOIN orders o        ON oi.order_id   = o.id
    LEFT  JOIN products p      ON oi.product_id = p.id
    LEFT  JOIN categories cat  ON p.category_id = cat.id
    WHERE o.status IN ('paid','shipped','completed')
    {$dateFilterSql}
    GROUP BY category_label
    ORDER BY total_revenue DESC
";
if ($res = $conn->query($sqlPie)) {
    while ($row = $res->fetch_assoc()) {
        $cat  = (string)$row['category_label'];
        $sold = (int)$row['total_sold'];
        $rev  = (float)$row['total_revenue'];
        $unit = $sold > 0 ? $rev / $sold : 0.0;

        $pie_labels[] = $cat;
        $pie_values[] = round($rev, 2);

        $pie_detail[] = [
            'category'   => $cat,
            'unit_price' => round($unit, 2),
            'total_sold' => $sold,
            'revenue'    => round($rev, 2),
        ];
    }
    $res->free();
}

/* ---------------- Top products ---------------- */
$top_products = [];
$sqlTopProducts = "
    SELECT
        p.id,
        p.name,
        p.image_url,
        COALESCE(SUM(oi.quantity), 0)      AS total_qty,
        COALESCE(SUM(oi.line_total), 0.00) AS total_revenue
    FROM order_items oi
    INNER JOIN products p ON oi.product_id = p.id
    INNER JOIN orders o   ON oi.order_id   = o.id
    WHERE o.status IN ('paid','shipped','completed')
    {$dateFilterSql}
    GROUP BY p.id, p.name, p.image_url
    ORDER BY total_qty DESC
    LIMIT 10
";
if ($res = $conn->query($sqlTopProducts)) {
    while ($row = $res->fetch_assoc()) {
        $row['image_src'] = (!empty($row['image_url'])) ? ('../' . ltrim($row['image_url'], '/')) : '';
        $top_products[] = $row;
    }
    $res->free();
}

/* ---------------- Top customers ---------------- */
$top_customers = [];
$sqlTopCustomers = "
    SELECT
        c.id,
        c.full_name,
        c.email,
        COUNT(o.id) AS orders_count,
        COALESCE(SUM(o.total_amount), 0.00) AS total_spent
    FROM orders o
    INNER JOIN customers c ON o.customer_id = c.id
    WHERE o.status IN ('paid','shipped','completed')
    {$dateFilterSql}
    GROUP BY c.id, c.full_name, c.email
    ORDER BY total_spent DESC
    LIMIT 10
";
if ($res = $conn->query($sqlTopCustomers)) {
    while ($row = $res->fetch_assoc()) $top_customers[] = $row;
    $res->free();
}

$seed = [
    'success' => true,
    'period' => $period,
    'periodLabel' => $periodLabel,
    'summary' => [
        'revenue_total'    => $revenue_total,
        'cost_total'       => $cost_total,
        'profit_total'     => $profit_total,
        'profit_margin'    => $profit_margin,
        'orders'           => $total_orders_period,
        'active_customers' => $active_customers_filtered,
        'active_products'  => $active_products_filtered,
        'open_tickets'     => $open_tickets_filtered,
    ],
    'charts' => [
        'revenue_profit' => [
            'labels'  => $chart_labels,
            'revenue' => $chart_revenue,
            'profit'  => $chart_profit,
            'detail'  => $chart_detail,
        ],
        'category_pie' => [
            'labels' => $pie_labels,
            'values' => $pie_values,
            'detail' => $pie_detail,
        ],
    ],
    'top_products'  => $top_products,
    'top_customers' => $top_customers,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard | Velvet Vogue</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Fonts -->
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
        /* ================= Mobile Sidebar FIX ================= */
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

        /* ============ CHARTS: compact height & fully inside card ============ */
        .admin-panel.report-chart-panel{
            overflow:hidden;                 /* keep content inside rounded panel */
        }

        /* Desktop / large screens */
        .report-chart-container{
            position:relative;
            height:260px;                    /* <<< main chart height */
            min-height:260px;
            overflow:hidden;
        }

        /* Tablets */
        @media (max-width: 991.98px){
            .report-chart-container{
                height:240px;
                min-height:240px;
            }
        }

        /* Phones */
        @media (max-width: 575.98px){
            .report-chart-container{
                height:220px;
                min-height:220px;
            }
        }

        .report-chart-container canvas{
            width:100% !important;
            height:100% !important;
            display:block;
        }

        .vv-chart-head{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:.75rem;
            width:100%;
        }
        .vv-chart-actions{
            display:flex;
            gap:.5rem;
            flex-wrap:wrap;
            justify-content:flex-end;
        }
        .vv-chart-actions .btn{
            border-radius:999px;
            padding:.35rem .65rem;
            font-size:.85rem;
            line-height:1.2;
        }

        .dash-loading{
            position:fixed;
            inset:0;
            background:rgba(255,255,255,0.6);
            display:none;
            z-index:6000;
            align-items:center;
            justify-content:center;
        }
        body.dash-is-loading .dash-loading{ display:flex; }

        .report-product-thumb img{
            width:44px;
            height:44px;
            object-fit:cover;
            border-radius:10px;
            display:block;
            background:#f4f3fb;
        }
    </style>
</head>

<body class="admin-dashboard-body">

<?php include 'includes/sidebar.php'; ?>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>

<div class="dash-loading">
    <div class="spinner-border" role="status" aria-label="Loading"></div>
</div>

<div class="admin-main reports-page">
    <header class="admin-topbar">
        <div class="d-flex align-items-start gap-2">
            <button type="button" class="admin-mobile-menu-btn d-lg-none" id="adminSidebarOpen" aria-label="Open menu">
                <i class='bx bx-menu'></i>
            </button>

            <div>
                <h1 class="admin-page-title">Dashboard</h1>
                <p class="admin-page-subtitle mb-0">
                    Welcome back, <?php echo htmlspecialchars($admin_name); ?>.
                    Here’s what’s happening in your store.
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

        <!-- Summary -->
        <div class="admin-panel mb-4">
            <div class="admin-panel-header">
                <h2 class="admin-panel-title mb-0">Summary</h2>

                <div class="d-flex align-items-center gap-2">
                    <label for="period" class="form-label mb-0 small text-muted">Period</label>
                    <select name="period" id="period" class="form-select form-select-sm" style="min-width:170px;">
                        <option value="last_30"  <?php echo $period === 'last_30'  ? 'selected' : ''; ?>>Last 30 days</option>
                        <option value="last_90"  <?php echo $period === 'last_90'  ? 'selected' : ''; ?>>Last 90 days</option>
                        <option value="this_year"<?php echo $period === 'this_year'? 'selected' : ''; ?>>This year</option>
                        <option value="all"      <?php echo $period === 'all'      ? 'selected' : ''; ?>>All time</option>
                    </select>
                </div>
            </div>

            <div class="admin-panel-body">
                <p class="text-muted small mb-3">
                    Showing metrics for: <strong id="periodLabel"><?php echo htmlspecialchars($periodLabel); ?></strong>
                </p>

                <div class="row g-3 summary-cards-row">
                    <div class="col-6 col-lg-3">
                        <div class="admin-stat-card">
                            <div class="admin-stat-icon revenue"><i class='bx bx-trending-up'></i></div>
                            <div class="admin-stat-body">
                                <p class="admin-stat-label">Revenue</p>
                                <h3 class="admin-stat-value" id="revVal"><?php echo money_fmt($revenue_total); ?></h3>
                                <p class="admin-stat-hint mb-0">Paid / shipped / completed</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-lg-3">
                        <div class="admin-stat-card">
                            <div class="admin-stat-icon cost"><i class='bx bx-coin-stack'></i></div>
                            <div class="admin-stat-body">
                                <p class="admin-stat-label">Cost</p>
                                <h3 class="admin-stat-value" id="costVal"><?php echo money_fmt($cost_total); ?></h3>
                                <p class="admin-stat-hint mb-0">Total product cost</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-lg-3">
                        <div class="admin-stat-card">
                            <div class="admin-stat-icon profit"><i class='bx bx-dollar-circle'></i></div>
                            <div class="admin-stat-body">
                                <p class="admin-stat-label">Profit</p>
                                <h3 class="admin-stat-value" id="profitVal"><?php echo money_fmt($profit_total); ?></h3>
                                <p class="admin-stat-hint mb-0"><span id="marginVal"><?php echo number_format($profit_margin, 1); ?></span>% margin</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-lg-3">
                        <div class="admin-stat-card">
                            <div class="admin-stat-icon soft orders"><i class='bx bxs-receipt'></i></div>
                            <div class="admin-stat-body">
                                <p class="admin-stat-label">Orders</p>
                                <h3 class="admin-stat-value" id="ordersVal"><?php echo number_format($total_orders_period); ?></h3>
                                <p class="admin-stat-hint mb-0">Completed / paid / shipped</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 summary-cards-row mt-2">
                    <div class="col-6 col-lg-3">
                        <div class="admin-stat-card small-card">
                            <div class="admin-stat-icon soft customers"><i class='bx bxs-user-detail'></i></div>
                            <div class="admin-stat-body">
                                <p class="admin-stat-label">Active Customers</p>
                                <h4 class="admin-stat-value" id="activeCustVal"><?php echo number_format($active_customers_filtered); ?></h4>
                                <p class="admin-stat-hint mb-0">Placed orders</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-lg-3">
                        <div class="admin-stat-card small-card">
                            <div class="admin-stat-icon soft products"><i class='bx bxs-t-shirt'></i></div>
                            <div class="admin-stat-body">
                                <p class="admin-stat-label">Active Products</p>
                                <h4 class="admin-stat-value" id="activeProdVal"><?php echo number_format($active_products_filtered); ?></h4>
                                <p class="admin-stat-hint mb-0">Sold in period</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-lg-3">
                        <div class="admin-stat-card small-card">
                            <div class="admin-stat-icon soft tickets"><i class='bx bxs-message-square-dots'></i></div>
                            <div class="admin-stat-body">
                                <p class="admin-stat-label">Open Tickets</p>
                                <h4 class="admin-stat-value" id="ticketsVal"><?php echo number_format($open_tickets_filtered); ?></h4>
                                <p class="admin-stat-hint mb-0">New / in progress</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-lg-3">
                        <div class="admin-stat-card small-card">
                            <div class="admin-stat-icon soft profit"><i class='bx bx-line-chart'></i></div>
                            <div class="admin-stat-body">
                                <p class="admin-stat-label">Profit Margin</p>
                                <h4 class="admin-stat-value"><span id="marginVal2"><?php echo number_format($profit_margin, 1); ?></span>%</h4>
                                <p class="admin-stat-hint mb-0">Profit / Revenue</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Charts -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="admin-panel report-chart-panel" data-chart="revenue_profit">
                    <div class="admin-panel-header">
                        <div class="vv-chart-head">
                            <div>
                                <h2 class="admin-panel-title mb-0">Revenue vs Profit</h2>
                                <p class="admin-panel-subtitle mb-0">Grouped by month</p>
                            </div>

                            <div class="vv-chart-actions">
                                <button type="button" class="btn btn-outline-secondary" data-action="details" data-chart="revenue_profit">
                                    <i class='bx bx-table'></i> Details
                                </button>
                                <a class="btn btn-outline-success" data-action="download" data-chart="revenue_profit" href="#">
                                    <i class='bx bx-download'></i> Download
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="admin-panel-body report-chart-container">
                        <canvas id="revenueChart"></canvas>
                        <div class="text-muted small mt-2" id="revChartEmpty" style="display:none;">Not enough data for this period.</div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="admin-panel report-chart-panel" data-chart="category_pie">
                    <div class="admin-panel-header">
                        <div class="vv-chart-head">
                            <div>
                                <h2 class="admin-panel-title mb-0">Sales by category</h2>
                                <p class="admin-panel-subtitle mb-0">Revenue share</p>
                            </div>

                            <div class="vv-chart-actions">
                                <button type="button" class="btn btn-outline-secondary" data-action="details" data-chart="category_pie">
                                    <i class='bx bx-table'></i> Details
                                </button>
                                <a class="btn btn-outline-success" data-action="download" data-chart="category_pie" href="#">
                                    <i class='bx bx-download'></i> Download
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="admin-panel-body report-chart-container">
                        <canvas id="categoryPieChart"></canvas>
                        <div class="text-muted small mt-2" id="pieChartEmpty" style="display:none;">No category breakdown for this period.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top products & customers -->
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="admin-panel report-bottom-panel">
                    <div class="admin-panel-header">
                        <div>
                            <h2 class="admin-panel-title mb-0">Top products</h2>
                            <p class="admin-panel-subtitle mb-0">
                                Ranked by quantity sold (<span id="periodLabel2"><?php echo htmlspecialchars($periodLabel); ?></span>)
                            </p>
                        </div>
                        <a href="products-ranking.php" class="btn btn-sm btn-outline-secondary rounded-pill">
                            View all
                        </a>
                    </div>
                    <div class="admin-panel-body report-bottom-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Revenue</th>
                                </tr>
                                </thead>
                                <tbody id="topProductsBody">
                                <?php $rank=1; foreach ($top_products as $row): ?>
                                    <tr>
                                        <td><?php echo $rank++; ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="report-product-thumb me-2">
                                                    <img src="<?php echo htmlspecialchars($row['image_src']); ?>"
                                                         alt=""
                                                         onerror="adminImgFallback(this,'<?php echo htmlspecialchars($row['name']); ?>')">
                                                </div>
                                                <a href="product-edit.php?id=<?php echo (int)$row['id']; ?>">
                                                    <?php echo htmlspecialchars($row['name']); ?>
                                                </a>
                                            </div>
                                        </td>
                                        <td class="text-end"><?php echo (int)$row['total_qty']; ?></td>
                                        <td class="text-end"><?php echo money_fmt($row['total_revenue']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="text-muted small" id="topProductsEmpty" style="display:none;">No product sales in this period.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="admin-panel report-bottom-panel">
                    <div class="admin-panel-header">
                        <div>
                            <h2 class="admin-panel-title mb-0">Top customers</h2>
                            <p class="admin-panel-subtitle mb-0">
                                Based on total spent (<span id="periodLabel3"><?php echo htmlspecialchars($periodLabel); ?></span>)
                            </p>
                        </div>
                        <a href="customer-ranking.php?sort=top" class="btn btn-sm btn-outline-secondary rounded-pill">
                            View all
                        </a>
                    </div>
                    <div class="admin-panel-body report-bottom-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Customer</th>
                                    <th class="text-end">Orders</th>
                                    <th class="text-end">Total spent</th>
                                </tr>
                                </thead>
                                <tbody id="topCustomersBody">
                                <?php $rank=1; foreach ($top_customers as $row): ?>
                                    <tr>
                                        <td><?php echo $rank++; ?></td>
                                        <td>
                                            <a href="customer-purchases.php?id=<?php echo (int)$row['id']; ?>">
                                                <?php echo htmlspecialchars($row['full_name']); ?>
                                            </a><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($row['email']); ?></small>
                                        </td>
                                        <td class="text-end"><?php echo (int)$row['orders_count']; ?></td>
                                        <td class="text-end"><?php echo money_fmt($row['total_spent']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="text-muted small" id="topCustomersEmpty" style="display:none;">No customer spend in this period.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<!-- Details Modal -->
<div class="modal fade" id="chartDataModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="chartDataTitle">Chart base data</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-2" id="chartDataSubtitle"></p>

        <div class="table-responsive">
          <table class="table table-sm align-middle" id="chartDataTable">
            <thead id="chartDataThead"></thead>
            <tbody id="chartDataTbody"></tbody>
          </table>
        </div>

        <div class="text-muted small" id="chartDataEmpty" style="display:none;">No data available.</div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
window.__dashData = <?php echo json_encode($seed, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

/* Image fallback */
function adminSvgPlaceholderDataUri(text) {
  const safe = String(text || 'Image').slice(0, 40).replace(/</g,'').replace(/>/g,'');
  const svg =
    `<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120">
      <rect width="120" height="120" rx="18" fill="#f4f3fb"/>
      <path d="M26 78l18-18 14 14 10-10 26 26H26z" fill="#d9d3ea"/>
      <circle cx="46" cy="44" r="7" fill="#d9d3ea"/>
      <text x="50%" y="92" text-anchor="middle" font-family="Poppins, Arial" font-size="10" fill="#a9a1bf">${safe}</text>
    </svg>`;
  return "data:image/svg+xml;charset=UTF-8," + encodeURIComponent(svg);
}
function adminImgFallback(img, label) {
  if (!img || img.dataset.fallbackApplied === "1") return;
  img.dataset.fallbackApplied = "1";
  img.src = adminSvgPlaceholderDataUri(label || 'Image');
}

/* Money */
function fmtMoney(v){
  const n = Number(v || 0);
  return '$' + n.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
}

/* Charts */
let revenueChart = null;
let pieChart = null;

function renderCharts(d){
  const rev = d.charts.revenue_profit || {};
  const pie = d.charts.category_pie || {};

  const isMobile = window.matchMedia('(max-width: 575.98px)').matches;

  // -------- Revenue vs Profit --------
  const revEmpty = document.getElementById('revChartEmpty');
  if (!rev.labels || !rev.labels.length){
    revEmpty.style.display = 'block';
    if (revenueChart){ revenueChart.destroy(); revenueChart = null; }
  } else {
    revEmpty.style.display = 'none';
    const ctx = document.getElementById('revenueChart').getContext('2d');
    if (revenueChart) revenueChart.destroy();

    revenueChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: rev.labels,
        datasets: [
          { label: 'Revenue', data: rev.revenue || [], borderWidth: 2, tension: 0.3 },
          { label: 'Profit',  data: rev.profit  || [], borderWidth: 2, borderDash: [5,5], tension: 0.3 }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { labels: { color: '#2b2b2b' } },
          tooltip: {
            backgroundColor: 'rgba(17,17,17,0.92)',
            titleColor: '#ffffff',
            bodyColor: '#ffffff',
            borderColor: 'rgba(255,255,255,0.15)',
            borderWidth: 1,
            callbacks: {
              label: (ctx) => `${ctx.dataset.label}: ${fmtMoney(ctx.parsed.y)}`
            }
          }
        },
        scales: {
          y: {
            min: 0,                               // always start at 0
            ticks: {
              color: '#2b2b2b',
              callback: (v) => Number(v).toLocaleString()
            },
            grid: { color: 'rgba(0,0,0,0.06)' }
          },
          x: {
            ticks: {
              color: '#2b2b2b',
              autoSkip: true,
              maxTicksLimit: isMobile ? 5 : 10,
              maxRotation: 0,
              minRotation: 0
            },
            grid: { color: 'rgba(0,0,0,0.06)' }
          }
        }
      }
    });
  }

  // -------- Category Pie --------
  const pieEmpty = document.getElementById('pieChartEmpty');
  if (!pie.labels || !pie.labels.length){
    pieEmpty.style.display = 'block';
    if (pieChart){ pieChart.destroy(); pieChart = null; }
  } else {
    pieEmpty.style.display = 'none';
    const ctx2 = document.getElementById('categoryPieChart').getContext('2d');
    if (pieChart) pieChart.destroy();

    pieChart = new Chart(ctx2, {
      type: 'pie',
      data: { labels: pie.labels, datasets: [{ data: pie.values || [] }] },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: '#2b2b2b',
              boxWidth: 12,
              padding: 12,
              font: { size: isMobile ? 10 : 11 }
            }
          },
          tooltip: {
            backgroundColor: 'rgba(17,17,17,0.92)',
            titleColor: '#ffffff',
            bodyColor: '#ffffff',
            borderColor: 'rgba(255,255,255,0.15)',
            borderWidth: 1,
            callbacks: {
              label: (context) => `${context.label}: ${fmtMoney(context.parsed)}`
            }
          }
        }
      }
    });
  }
}

/* Summary */
function renderSummary(d){
  document.getElementById('periodLabel').textContent  = d.periodLabel;
  document.getElementById('periodLabel2').textContent = d.periodLabel;
  document.getElementById('periodLabel3').textContent = d.periodLabel;

  document.getElementById('revVal').textContent      = fmtMoney(d.summary.revenue_total);
  document.getElementById('costVal').textContent     = fmtMoney(d.summary.cost_total);
  document.getElementById('profitVal').textContent   = fmtMoney(d.summary.profit_total);
  document.getElementById('ordersVal').textContent   = Number(d.summary.orders||0).toLocaleString();
  document.getElementById('activeCustVal').textContent = Number(d.summary.active_customers||0).toLocaleString();
  document.getElementById('activeProdVal').textContent = Number(d.summary.active_products||0).toLocaleString();
  document.getElementById('ticketsVal').textContent    = Number(d.summary.open_tickets||0).toLocaleString();

  const margin = Number(d.summary.profit_margin||0).toFixed(1);
  document.getElementById('marginVal').textContent  = margin;
  document.getElementById('marginVal2').textContent = margin;
}

/* Tables (JS refresh for AJAX) */
function renderTopProducts(d){
  const body  = document.getElementById('topProductsBody');
  const empty = document.getElementById('topProductsEmpty');
  body.innerHTML = '';

  if (!d.top_products || !d.top_products.length){
    empty.style.display = 'block';
    return;
  }
  empty.style.display = 'none';

  d.top_products.forEach((p, idx) => {
    const imgSrc = p.image_src || '';
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${idx+1}</td>
      <td>
        <div class="d-flex align-items-center">
          <div class="report-product-thumb me-2">
            <img src="${imgSrc}" alt="" onerror="adminImgFallback(this, ${JSON.stringify(p.name||'Product')})">
          </div>
          <a href="product-edit.php?id=${Number(p.id)}">${String(p.name||'').replace(/</g,'&lt;')}</a>
        </div>
      </td>
      <td class="text-end">${Number(p.total_qty||0)}</td>
      <td class="text-end">${fmtMoney(p.total_revenue||0)}</td>
    `;
    body.appendChild(tr);
  });
}

function renderTopCustomers(d){
  const body  = document.getElementById('topCustomersBody');
  const empty = document.getElementById('topCustomersEmpty');
  body.innerHTML = '';

  if (!d.top_customers || !d.top_customers.length){
    empty.style.display = 'block';
    return;
  }
  empty.style.display = 'none';

  d.top_customers.forEach((c, idx) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${idx+1}</td>
      <td>
        <a href="customer-purchases.php?id=${Number(c.id)}">${String(c.full_name||'').replace(/</g,'&lt;')}</a><br>
        <small class="text-muted">${String(c.email||'').replace(/</g,'&lt;')}</small>
      </td>
      <td class="text-end">${Number(c.orders_count||0)}</td>
      <td class="text-end">${fmtMoney(c.total_spent||0)}</td>
    `;
    body.appendChild(tr);
  });
}

/* Details modal */
const chartModalEl = document.getElementById('chartDataModal');
const chartModal   = new bootstrap.Modal(chartModalEl);

function openChartModal(chartKey, d){
  const title    = document.getElementById('chartDataTitle');
  const subtitle = document.getElementById('chartDataSubtitle');
  const thead    = document.getElementById('chartDataThead');
  const tbody    = document.getElementById('chartDataTbody');
  const empty    = document.getElementById('chartDataEmpty');

  thead.innerHTML = '';
  tbody.innerHTML = '';
  empty.style.display = 'none';

  if (chartKey === 'revenue_profit'){
    title.textContent    = 'Revenue vs Profit — base data';
    subtitle.textContent = 'Monthly totals used to draw the Revenue/Profit chart.';

    const rows = d.charts.revenue_profit.detail || [];
    if (!rows.length){ empty.style.display='block'; chartModal.show(); return; }

    thead.innerHTML = `<tr>
      <th>Month</th>
      <th class="text-end">Revenue</th>
      <th class="text-end">Cost</th>
      <th class="text-end">Profit</th>
    </tr>`;

    rows.forEach(r => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${String(r.label||'').replace(/</g,'&lt;')}</td>
        <td class="text-end">${fmtMoney(r.revenue)}</td>
        <td class="text-end">${fmtMoney(r.cost)}</td>
        <td class="text-end">${fmtMoney(r.profit)}</td>
      `;
      tbody.appendChild(tr);
    });

  } else if (chartKey === 'category_pie'){
    title.textContent    = 'Sales by Category — base data';
    subtitle.textContent = 'Category totals used to draw the pie chart.';

    const rows = d.charts.category_pie.detail || [];
    if (!rows.length){ empty.style.display='block'; chartModal.show(); return; }

    thead.innerHTML = `<tr>
      <th>Category</th>
      <th class="text-end">Unit price</th>
      <th class="text-end">Total sold</th>
      <th class="text-end">Revenue</th>
    </tr>`;

    rows.forEach(r => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${String(r.category||'').replace(/</g,'&lt;')}</td>
        <td class="text-end">${fmtMoney(r.unit_price)}</td>
        <td class="text-end">${Number(r.total_sold||0).toLocaleString()}</td>
        <td class="text-end">${fmtMoney(r.revenue)}</td>
      `;
      tbody.appendChild(tr);
    });
  }

  chartModal.show();
}

/* Export links */
function updateDownloadLinks(period){
  document.querySelectorAll('a[data-action="download"]').forEach(a => {
    const chart = a.getAttribute('data-chart');
    a.href = `ajax/export_dashboard.php?type=${encodeURIComponent(chart)}&period=${encodeURIComponent(period)}`;
  });
}

/* AJAX load */
async function fetchDashboard(period){
  document.body.classList.add('dash-is-loading');
  try{
    const res = await fetch(`ajax/dashboard_data.php?period=${encodeURIComponent(period)}`, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });
    const data = await res.json();
    if (!data || !data.success) throw new Error('Failed to load dashboard data');

    window.__dashData = data;
    renderSummary(data);
    renderCharts(data);
    renderTopProducts(data);
    renderTopCustomers(data);
    updateDownloadLinks(period);

    const url = new URL(window.location.href);
    url.searchParams.set('period', period);
    window.history.replaceState({}, '', url.toString());
  } finally {
    document.body.classList.remove('dash-is-loading');
  }
}

/* Mobile sidebar */
function setupMobileSidebar(){
  const openBtn  = document.getElementById('adminSidebarOpen');
  const closeBtn = document.getElementById('adminSidebarClose');
  const backdrop = document.getElementById('adminSidebarBackdrop');

  const open  = () => document.body.classList.add('admin-sidebar-open');
  const close = () => document.body.classList.remove('admin-sidebar-open');

  if (openBtn) openBtn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    open();
  });

  if (closeBtn) closeBtn.addEventListener('click', (e) => {
    e.preventDefault();
    close();
  });

  if (backdrop) backdrop.addEventListener('click', close);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });
}

/* Init */
document.addEventListener('DOMContentLoaded', () => {
  setupMobileSidebar();

  renderSummary(window.__dashData);
  renderCharts(window.__dashData);
  renderTopProducts(window.__dashData);
  renderTopCustomers(window.__dashData);
  updateDownloadLinks(document.getElementById('period').value);

  const periodSel = document.getElementById('period');
  periodSel.addEventListener('change', () => fetchDashboard(periodSel.value));

  document.querySelectorAll('[data-action="details"]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      openChartModal(btn.getAttribute('data-chart'), window.__dashData);
    });
  });

  document.querySelectorAll('a[data-action="download"]').forEach(a => {
    a.addEventListener('click', (e) => { e.stopPropagation(); });
  });

  window.addEventListener('resize', () => {
    if (revenueChart) revenueChart.resize();
    if (pieChart) pieChart.resize();
  });
});
</script>
</body>
</html>
