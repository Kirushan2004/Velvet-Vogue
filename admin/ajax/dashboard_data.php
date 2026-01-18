<?php
// admin/ajax/dashboard_data.php
session_start();
require_once '../../db.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$allowedPeriods = ['last_30', 'last_90', 'this_year', 'all'];
$period = $_GET['period'] ?? 'last_90';
if (!in_array($period, $allowedPeriods, true)) $period = 'last_90';

$dateFilterSql = '';
$periodLabel = '';

switch ($period) {
    case 'last_30':
        $dateFilterSql = " AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $periodLabel   = 'Last 30 days';
        break;
    case 'last_90':
        $dateFilterSql = " AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
        $periodLabel   = 'Last 90 days';
        break;
    case 'this_year':
        $dateFilterSql = " AND YEAR(o.created_at) = YEAR(CURDATE())";
        $periodLabel   = 'This year';
        break;
    default:
        $periodLabel   = 'All time';
        break;
}

// Summary
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

// Orders
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

// Active customers
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

// Active products
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

// Tickets
$open_tickets_filtered = 0;
$ticketDateFilterSql = '';
switch ($period) {
    case 'last_30':
        $ticketDateFilterSql = " AND st.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        break;
    case 'last_90':
        $ticketDateFilterSql = " AND st.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
        break;
    case 'this_year':
        $ticketDateFilterSql = " AND YEAR(st.created_at) = YEAR(CURDATE())";
        break;
    default:
        $ticketDateFilterSql = '';
        break;
}
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

// Revenue/profit chart
$chart_labels  = [];
$chart_revenue = [];
$chart_profit  = [];
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

// Category pie
$pie_labels = [];
$pie_values = [];
$pie_detail = [];

$sqlPie = "
    SELECT
        COALESCE(cat.name, 'Uncategorized') AS category_label,
        COALESCE(SUM(oi.quantity), 0)       AS qty_sold,
        COALESCE(SUM(oi.line_total), 0)     AS total_revenue
    FROM order_items oi
    INNER JOIN orders o       ON oi.order_id   = o.id
    LEFT  JOIN products p     ON oi.product_id = p.id
    LEFT  JOIN categories cat ON p.category_id = cat.id
    WHERE o.status IN ('paid','shipped','completed')
    {$dateFilterSql}
    GROUP BY category_label
    ORDER BY total_revenue DESC
";
if ($res = $conn->query($sqlPie)) {
    while ($row = $res->fetch_assoc()) {
        $rev = round((float)$row['total_revenue'], 2);
        $qty = (int)$row['qty_sold'];
        $unit = ($qty > 0) ? round($rev / $qty, 2) : 0.0;

        $pie_labels[] = $row['category_label'];
        $pie_values[] = $rev;

        $pie_detail[] = [
            'category'   => $row['category_label'],
            'unit_price' => $unit,
            'qty_sold'   => $qty,
            'revenue'    => $rev,
        ];
    }
    $res->free();
}

// Top products
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

// Top customers
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

echo json_encode([
    'success' => true,
    'period' => $period,
    'periodLabel' => $periodLabel,
    'summary' => [
        'revenue_total' => $revenue_total,
        'cost_total' => $cost_total,
        'profit_total' => $profit_total,
        'profit_margin' => $profit_margin,
        'orders' => $total_orders_period,
        'active_customers' => $active_customers_filtered,
        'active_products' => $active_products_filtered,
        'open_tickets' => $open_tickets_filtered,
    ],
    'charts' => [
        'revenue_profit' => [
            'labels' => $chart_labels,
            'revenue' => $chart_revenue,
            'profit' => $chart_profit,
            'detail' => $chart_detail,
        ],
        'category_pie' => [
            'labels' => $pie_labels,
            'values' => $pie_values,
            'detail' => $pie_detail,
        ]
    ],
    'top_products' => $top_products,
    'top_customers' => $top_customers,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
