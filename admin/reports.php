<?php
// admin/reports.php
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

/* =========================
   Helpers
========================= */

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function money_fmt($v): string { return '$' . number_format((float)$v, 2); }
function pct_fmt($v, $dec = 1): string { return number_format((float)$v, $dec) . '%'; }

function excel_out_start(string $filename): void {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "<html><head><meta charset=\"UTF-8\"></head><body style=\"font-family: Arial, sans-serif;\">";
}
function excel_out_end(): void {
    echo "</body></html>";
}
function excel_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function excel_table(string $title, array $headers, array $rows, ?string $note = null): void {
    echo "<h3 style=\"margin:10px 0 6px;\">" . excel_h($title) . "</h3>";
    if ($note) {
        echo "<div style=\"margin:0 0 8px; color:#555; font-size:12px;\">" . excel_h($note) . "</div>";
    }
    echo "<table border=\"1\" cellpadding=\"6\" cellspacing=\"0\" style=\"border-collapse: collapse; width: 100%;\">";
    echo "<tr style=\"background:#f3f4f6; font-weight:bold;\">";
    foreach ($headers as $th) {
        echo "<td>" . excel_h($th) . "</td>";
    }
    echo "</tr>";
    foreach ($rows as $r) {
        echo "<tr>";
        foreach ($r as $cell) {
            echo "<td>" . excel_h($cell) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}

/* =========================
   Sales scope (real sales)
========================= */
$salesStatusesSql = "('paid','shipped','completed')";
$completedStatus  = 'completed';

/* =========================
   KPI Queries
========================= */

// Total revenue
$totalRevenue = 0.0;
$q = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS v FROM orders WHERE status IN {$salesStatusesSql}");
if ($q && ($row = $q->fetch_assoc())) $totalRevenue = (float)$row['v'];
if ($q) $q->free();

// Completed orders
$completedOrders = 0;
$q = $conn->query("SELECT COUNT(*) AS v FROM orders WHERE status = '{$completedStatus}'");
if ($q && ($row = $q->fetch_assoc())) $completedOrders = (int)$row['v'];
if ($q) $q->free();

// Average order value (completed only)
$avgOrderValue = 0.0;
$q = $conn->query("SELECT COALESCE(AVG(total_amount),0) AS v FROM orders WHERE status = '{$completedStatus}'");
if ($q && ($row = $q->fetch_assoc())) $avgOrderValue = (float)$row['v'];
if ($q) $q->free();

// Total buying customers
$totalBuyingCustomers = 0;
$q = $conn->query("SELECT COUNT(DISTINCT customer_id) AS v FROM orders WHERE status IN {$salesStatusesSql}");
if ($q && ($row = $q->fetch_assoc())) $totalBuyingCustomers = (int)$row['v'];
if ($q) $q->free();

// Orders per customer
$totalSalesOrders = 0;
$q = $conn->query("SELECT COUNT(*) AS v FROM orders WHERE status IN {$salesStatusesSql}");
if ($q && ($row = $q->fetch_assoc())) $totalSalesOrders = (int)$row['v'];
if ($q) $q->free();
$ordersPerCustomer = ($totalBuyingCustomers > 0) ? ($totalSalesOrders / $totalBuyingCustomers) : 0.0;

// Repeat buyers count (>=2 orders)
$repeatCustomers = 0;
$q = $conn->query("
    SELECT COUNT(*) AS v
    FROM (
        SELECT customer_id
        FROM orders
        WHERE status IN {$salesStatusesSql}
        GROUP BY customer_id
        HAVING COUNT(*) >= 2
    ) t
");
if ($q && ($row = $q->fetch_assoc())) $repeatCustomers = (int)$row['v'];
if ($q) $q->free();

$oneTimeCustomers = max(0, $totalBuyingCustomers - $repeatCustomers);
$retentionRate    = ($totalBuyingCustomers > 0) ? (($repeatCustomers / $totalBuyingCustomers) * 100.0) : 0.0;

// Sales from New Arrivals (% revenue from products.is_new = 1)
$newArrivalsRevenue = 0.0;
$totalItemsRevenue  = 0.0;
$q = $conn->query("
    SELECT
        COALESCE(SUM(oi.line_total),0) AS total_rev,
        COALESCE(SUM(CASE WHEN p.is_new = 1 THEN oi.line_total ELSE 0 END),0) AS new_rev
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE o.status IN {$salesStatusesSql}
");
if ($q && ($row = $q->fetch_assoc())) {
    $totalItemsRevenue  = (float)$row['total_rev'];
    $newArrivalsRevenue = (float)$row['new_rev'];
}
if ($q) $q->free();
$newArrivalsPct = ($totalItemsRevenue > 0) ? (($newArrivalsRevenue / $totalItemsRevenue) * 100.0) : 0.0;

// Active promotions (is_active + within dates)
$activePromotions = 0;
$q = $conn->query("
    SELECT COUNT(*) AS v
    FROM promotions
    WHERE is_active = 1
      AND (starts_at IS NULL OR starts_at <= NOW())
      AND (ends_at IS NULL OR ends_at >= NOW())
");
if ($q && ($row = $q->fetch_assoc())) $activePromotions = (int)$row['v'];
if ($q) $q->free();

// Featured on homepage (products.is_hot)
$featuredCount = 0;
$q = $conn->query("SELECT COUNT(*) AS v FROM products WHERE is_hot = 1 AND is_active = 1");
if ($q && ($row = $q->fetch_assoc())) $featuredCount = (int)$row['v'];
if ($q) $q->free();

// Discount rate
$discountRate = 0.0;
$discountSumAll = 0.0;
$subtotalSumAll = 0.0;
$q = $conn->query("
    SELECT
        COALESCE(SUM(discount_amount),0) AS disc_sum,
        COALESCE(SUM(subtotal),0) AS sub_sum
    FROM orders
    WHERE status IN {$salesStatusesSql}
");
if ($q && ($row = $q->fetch_assoc())) {
    $discountSumAll = (float)$row['disc_sum'];
    $subtotalSumAll = (float)$row['sub_sum'];
    $discountRate = ($subtotalSumAll > 0) ? (($discountSumAll / $subtotalSumAll) * 100.0) : 0.0;
}
if ($q) $q->free();

/* =========================
   Charts (DATA + DETAILS)
========================= */

// 1) Monthly Sales Trend (last 12 months) — base chart uses revenue
$monthLabels = [];
$monthMapRevenue = []; // 'YYYY-MM' => revenue
$start = new DateTime('first day of this month');
$start->modify('-11 months');

$tmp = clone $start;
for ($i=0; $i<12; $i++){
    $key = $tmp->format('Y-m');
    $monthLabels[] = $key;
    $monthMapRevenue[$key] = 0.0;
    $tmp->modify('+1 month');
}

$detailsMonthlySales = []; // month, orders_count, customers_count, revenue, discount_sum
$q = $conn->query("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS ym,
        COUNT(*) AS orders_count,
        COUNT(DISTINCT customer_id) AS customers_count,
        COALESCE(SUM(total_amount),0) AS revenue,
        COALESCE(SUM(discount_amount),0) AS discount_sum
    FROM orders
    WHERE status IN {$salesStatusesSql}
      AND created_at >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 11 MONTH)
    GROUP BY ym
    ORDER BY ym
");
$monthMapOrders = array_fill_keys($monthLabels, 0);
$monthMapCustomers = array_fill_keys($monthLabels, 0);
$monthMapDiscount = array_fill_keys($monthLabels, 0.0);

if ($q) {
    while ($row = $q->fetch_assoc()) {
        $ym = $row['ym'];
        if (isset($monthMapRevenue[$ym])) {
            $monthMapRevenue[$ym] = (float)$row['revenue'];
            $monthMapOrders[$ym] = (int)$row['orders_count'];
            $monthMapCustomers[$ym] = (int)$row['customers_count'];
            $monthMapDiscount[$ym] = (float)$row['discount_sum'];
        }
    }
    $q->free();
}
$monthlyRevenue = array_values($monthMapRevenue);

// build details (in the same label order)
foreach ($monthLabels as $m) {
    $detailsMonthlySales[] = [
        'month' => $m,
        'orders_count' => $monthMapOrders[$m],
        'customers_count' => $monthMapCustomers[$m],
        'revenue' => $monthMapRevenue[$m],
        'discount_sum' => $monthMapDiscount[$m],
    ];
}

// 2) Top 10 Best-Selling Products (by quantity)
$topProdLabels = [];
$topProdQty    = [];
$detailsTopProducts = []; // product, qty, revenue, orders_count, avg_unit_price

$q = $conn->query("
    SELECT
        oi.product_name,
        COALESCE(SUM(oi.quantity),0) AS qty,
        COALESCE(SUM(oi.line_total),0) AS revenue,
        COUNT(DISTINCT oi.order_id) AS orders_count,
        COALESCE(AVG(oi.unit_price),0) AS avg_unit_price
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE o.status IN {$salesStatusesSql}
    GROUP BY oi.product_name
    ORDER BY qty DESC
    LIMIT 10
");
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $topProdLabels[] = $row['product_name'];
        $topProdQty[]    = (int)$row['qty'];
        $detailsTopProducts[] = [
            'product_name' => $row['product_name'],
            'qty' => (int)$row['qty'],
            'revenue' => (float)$row['revenue'],
            'orders_count' => (int)$row['orders_count'],
            'avg_unit_price' => (float)$row['avg_unit_price'],
        ];
    }
    $q->free();
}

// 3) Top Royalty Customers (VIP) (top 10 by spend)
$vipLabels = [];
$vipSpent  = [];
$detailsVIP = []; // name, email, orders_count, spent, avg_order

$q = $conn->query("
    SELECT
        c.full_name,
        c.email,
        COUNT(o.id) AS orders_count,
        COALESCE(SUM(o.total_amount),0) AS spent,
        COALESCE(AVG(o.total_amount),0) AS avg_order
    FROM orders o
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE o.status IN {$salesStatusesSql}
    GROUP BY o.customer_id, c.full_name, c.email
    ORDER BY spent DESC
    LIMIT 10
");
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $vipLabels[] = $row['full_name'];
        $vipSpent[]  = (float)$row['spent'];
        $detailsVIP[] = [
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'orders_count' => (int)$row['orders_count'],
            'spent' => (float)$row['spent'],
            'avg_order' => (float)$row['avg_order'],
        ];
    }
    $q->free();
}

// 4) Retention (repeat vs one-time) — base graph uses counts
$detailsRetentionCustomers = []; // customer, email, orders_count, segment
$q = $conn->query("
    SELECT c.full_name, c.email, x.cnt
    FROM (
        SELECT customer_id, COUNT(*) AS cnt
        FROM orders
        WHERE status IN {$salesStatusesSql}
        GROUP BY customer_id
    ) x
    INNER JOIN customers c ON c.id = x.customer_id
    ORDER BY x.cnt DESC, c.full_name ASC
");
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $cnt = (int)$row['cnt'];
        $detailsRetentionCustomers[] = [
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'orders_count' => $cnt,
            'segment' => ($cnt >= 2) ? 'Repeat' : 'One-time',
        ];
    }
    $q->free();
}

// 5) Customer Purchase Frequency (1, 2-3, 4+)
$freq = ['1_time' => 0, '2_3' => 0, '4_plus' => 0];
$detailsFrequencyCustomers = []; // customer, email, orders_count, bucket

$q = $conn->query("
    SELECT c.full_name, c.email, x.cnt
    FROM (
        SELECT customer_id, COUNT(*) AS cnt
        FROM orders
        WHERE status IN {$salesStatusesSql}
        GROUP BY customer_id
    ) x
    INNER JOIN customers c ON c.id = x.customer_id
    ORDER BY x.cnt DESC, c.full_name ASC
");
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $cnt = (int)$row['cnt'];
        if ($cnt === 1) $freq['1_time']++;
        elseif ($cnt >= 2 && $cnt <= 3) $freq['2_3']++;
        else $freq['4_plus']++;

        $bucket = ($cnt === 1) ? '1 Time' : (($cnt <= 3) ? '2–3 Times' : '4+ Times (VIP)');
        $detailsFrequencyCustomers[] = [
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'orders_count' => $cnt,
            'bucket' => $bucket
        ];
    }
    $q->free();
}

// 6) Promotion vs Regular Sales (discount_amount > 0)
$promoRevenue = 0.0;
$regularRevenue = 0.0;
$q = $conn->query("
    SELECT
        COALESCE(SUM(CASE WHEN discount_amount > 0 THEN total_amount ELSE 0 END),0) AS promo_rev,
        COALESCE(SUM(CASE WHEN discount_amount <= 0 THEN total_amount ELSE 0 END),0) AS regular_rev
    FROM orders
    WHERE status IN {$salesStatusesSql}
");
if ($q && ($row = $q->fetch_assoc())) {
    $promoRevenue   = (float)$row['promo_rev'];
    $regularRevenue = (float)$row['regular_rev'];
}
if ($q) $q->free();

$detailsPromoOrders = []; // (detailed list used to build the two totals)
$q = $conn->query("
    SELECT
        o.order_number,
        o.created_at,
        o.status,
        o.subtotal,
        o.discount_amount,
        o.total_amount,
        c.full_name AS customer_name,
        c.email AS customer_email
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE o.status IN {$salesStatusesSql}
    ORDER BY o.created_at DESC
");
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $disc = (float)$row['discount_amount'];
        $detailsPromoOrders[] = [
            'order_number' => $row['order_number'],
            'created_at' => $row['created_at'],
            'status' => $row['status'],
            'customer_name' => $row['customer_name'] ?? 'Unknown',
            'customer_email' => $row['customer_email'] ?? '',
            'subtotal' => (float)$row['subtotal'],
            'discount_amount' => $disc,
            'total_amount' => (float)$row['total_amount'],
            'type' => ($disc > 0) ? 'With Promotion (Discount)' : 'Regular'
        ];
    }
    $q->free();
}

/* =========================
   EXCEL EXPORT (per chart)
========================= */
if (isset($_GET['export'])) {
    $export = strtolower((string)($_GET['export'] ?? ''));

    $allowed = [
        'sales_trend',
        'top_products',
        'vip_customers',
        'retention',
        'frequency',
        'promo_vs_regular',
    ];
    if (!in_array($export, $allowed, true)) {
        http_response_code(400);
        echo "Invalid export type.";
        exit;
    }

    $filename = "velvet_vogue_" . $export . "_" . date('Ymd_His') . ".xls";
    excel_out_start($filename);

    echo "<h2 style=\"margin:0 0 6px;\">Velvet Vogue — Reports Export</h2>";
    echo "<div style=\"margin:0 0 10px; color:#555; font-size:12px;\">";
    echo "Generated: " . excel_h(date('Y-m-d H:i:s')) . "<br>";
    echo "Sales statuses included: paid / shipped / completed";
    echo "</div>";

    if ($export === 'sales_trend') {
        $rows = [];
        foreach ($detailsMonthlySales as $r) {
            $rows[] = [
                $r['month'],
                $r['orders_count'],
                $r['customers_count'],
                number_format((float)$r['revenue'], 2, '.', ''),
                number_format((float)$r['discount_sum'], 2, '.', ''),
            ];
        }
        excel_table(
            "Monthly Sales Trend — Base Details",
            ['Month (YYYY-MM)', 'Orders', 'Customers', 'Revenue', 'Discount Total'],
            $rows,
            "These rows are grouped by month and are the source values used for the Monthly Sales Trend chart."
        );
    }

    if ($export === 'top_products') {
        $rows = [];
        foreach ($detailsTopProducts as $r) {
            $rows[] = [
                $r['product_name'],
                $r['qty'],
                $r['orders_count'],
                number_format((float)$r['avg_unit_price'], 2, '.', ''),
                number_format((float)$r['revenue'], 2, '.', ''),
            ];
        }
        excel_table(
            "Top 10 Best-Selling Products — Base Details",
            ['Product', 'Units Sold (Qty)', 'Orders Count', 'Avg Unit Price', 'Revenue (Line Total)'],
            $rows,
            "These rows are aggregated per product and are the base values used for the Top Products chart (sorted by Qty)."
        );
    }

    if ($export === 'vip_customers') {
        $rows = [];
        foreach ($detailsVIP as $r) {
            $rows[] = [
                $r['full_name'],
                $r['email'],
                $r['orders_count'],
                number_format((float)$r['avg_order'], 2, '.', ''),
                number_format((float)$r['spent'], 2, '.', ''),
            ];
        }
        excel_table(
            "Top Royalty Customers (VIP) — Base Details",
            ['Customer', 'Email', 'Orders', 'Avg Order Value', 'Total Spent'],
            $rows,
            "These rows are aggregated per customer and are the base values used for the VIP chart (sorted by Total Spent)."
        );
    }

    if ($export === 'retention') {
        excel_table(
            "Retention Summary — Base Numbers",
            ['Metric', 'Value'],
            [
                ['Total Buying Customers', (string)$totalBuyingCustomers],
                ['Repeat Customers (>= 2 orders)', (string)$repeatCustomers],
                ['One-time Customers (1 order)', (string)$oneTimeCustomers],
                ['Retention Rate', pct_fmt($retentionRate, 2)],
            ],
            "These totals are the base values used for the Retention (Repeat vs One-time) chart."
        );

        $rows = [];
        foreach ($detailsRetentionCustomers as $c) {
            $rows[] = [
                $c['full_name'],
                $c['email'],
                $c['orders_count'],
                $c['segment']
            ];
        }
        excel_table(
            "Retention Customer List — Detailed Breakdown",
            ['Customer', 'Email', 'Orders Count', 'Segment'],
            $rows,
            "This detailed list explains exactly how each customer is classified for the retention chart."
        );
    }

    if ($export === 'frequency') {
        excel_table(
            "Purchase Frequency Summary — Bucket Totals",
            ['Bucket', 'Customers'],
            [
                ['1 Time', (string)$freq['1_time']],
                ['2–3 Times', (string)$freq['2_3']],
                ['4+ Times (VIP)', (string)$freq['4_plus']],
            ],
            "These bucket totals are the base values used for the Customer Purchase Frequency chart."
        );

        $rows = [];
        foreach ($detailsFrequencyCustomers as $c) {
            $rows[] = [
                $c['full_name'],
                $c['email'],
                $c['orders_count'],
                $c['bucket'],
            ];
        }
        excel_table(
            "Purchase Frequency Customer List — Detailed Breakdown",
            ['Customer', 'Email', 'Orders Count', 'Bucket'],
            $rows,
            "This detailed list shows how each customer is bucketed (1, 2–3, 4+)."
        );
    }

    if ($export === 'promo_vs_regular') {
        excel_table(
            "Promotion vs Regular — Base Totals (Chart Source)",
            ['Type', 'Revenue Total'],
            [
                ['With Promotion (Discount > 0)', number_format($promoRevenue, 2, '.', '')],
                ['Regular (Discount <= 0)', number_format($regularRevenue, 2, '.', '')],
            ],
            "These two totals are the base values used for the Promotion vs Regular chart."
        );

        $rows = [];
        foreach ($detailsPromoOrders as $o) {
            $rows[] = [
                $o['order_number'],
                $o['created_at'],
                $o['status'],
                $o['customer_name'],
                $o['customer_email'],
                number_format((float)$o['subtotal'], 2, '.', ''),
                number_format((float)$o['discount_amount'], 2, '.', ''),
                number_format((float)$o['total_amount'], 2, '.', ''),
                $o['type'],
            ];
        }
        excel_table(
            "Promotion vs Regular — Detailed Orders List",
            ['Order #', 'Date', 'Status', 'Customer', 'Email', 'Subtotal', 'Discount', 'Total Amount', 'Class'],
            $rows,
            "This detailed list shows exactly which orders contributed to Promo vs Regular totals (based on discount_amount > 0)."
        );
    }

    excel_out_end();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports | Velvet Vogue Admin</title>
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
        :root{
            --vv-primary: #6B1F4F;
            --vv-bg: #f4f5fb;
            --vv-border: #e9e9f5;
            --vv-text: #111013;
            --vv-muted: #6b7280;
        }

        .summary-panel {
            background: #fff;
            border: 1px solid var(--vv-border);
            border-radius: 16px;
            padding: 16px;
        }
        .summary-title{
            font-family: "Playfair Display", serif;
            font-weight: 700;
            margin: 0;
        }
        .summary-sub{
            color: var(--vv-muted);
            margin: 2px 0 0 0;
            font-size: .92rem;
        }

        .kpi-card{
            background:#fff;
            border: 1px solid var(--vv-border);
            border-radius: 14px;
            padding: 14px;
            height: 100%;
            display:flex;
            align-items:center;
            gap: 12px;
        }
        .kpi-ic{
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size: 18px;
        }
        .kpi-ic.green { background: rgba(34,197,94,.12); color:#16a34a; }
        .kpi-ic.orange{ background: rgba(245,158,11,.14); color:#f59e0b; }
        .kpi-ic.blue  { background: rgba(59,130,246,.12); color:#2563eb; }
        .kpi-ic.purple{ background: rgba(124,58,237,.12); color:#7c3aed; }
        .kpi-ic.rose  { background: rgba(244,63,94,.12); color:#f43f5e; }
        .kpi-ic.teal  { background: rgba(20,184,166,.12); color:#14b8a6; }

        .kpi-label{
            letter-spacing: .08em;
            text-transform: uppercase;
            font-size: .72rem;
            color: #9aa0b4;
            margin: 0;
        }
        .kpi-value{
            margin: 2px 0 0 0;
            font-weight: 700;
            font-family: "Playfair Display", serif;
            font-size: 1.15rem;
            color: var(--vv-text);
        }
        .kpi-sub{
            margin: 0;
            color: var(--vv-muted);
            font-size: .82rem;
        }

        .chart-card{
            background:#fff;
            border: 1px solid var(--vv-border);
            border-radius: 16px;
            overflow:hidden;
            height: 100%;
        }
        .chart-head{
            padding: 14px 14px 0;
            display:flex;
            align-items:flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .chart-title{
            font-family: "Playfair Display", serif;
            font-weight: 700;
            margin: 0;
            font-size: 1.05rem;
        }
        .chart-subtitle{
            margin: 0;
            color: var(--vv-muted);
            font-size: .85rem;
        }
        .chart-actions{
            display:flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content:flex-end;
        }
        .chart-actions .btn{
            border-radius: 999px;
            padding: 6px 10px;
            font-weight: 600;
            font-size: .82rem;
            line-height: 1;
            display:inline-flex;
            align-items:center;
            gap: 6px;
            white-space: nowrap;
        }

        .chart-body{ padding: 10px 14px 16px; }

        /* ✅ fixed-height container; Chart.js will fit perfectly */
        .chart-wrap{
            position: relative;
            width: 100%;
            height: 260px;
            cursor: pointer;
        }
        .chart-wrap canvas{
            width: 100% !important;
            height: 100% !important;
            display:block;
        }
        @media (max-width: 991.98px){
            .chart-wrap{ height: 240px; }
        }

        .details-modal .modal-content{
            border-radius: 16px;
            overflow:hidden;
        }
        .details-modal .modal-header{
            background: #fff;
            border-bottom: 1px solid var(--vv-border);
            display:flex;
            align-items:flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .details-modal .modal-title{
            font-family: "Playfair Display", serif;
            font-weight: 700;
        }
        .details-modal .table thead th{
            background: #fafafa;
        }
        .modal-actions{
            margin-left: auto;
            display:flex;
            gap: 8px;
            align-items:center;
            justify-content:flex-end;
            flex-wrap: wrap;
        }
        .modal-actions .btn{
            border-radius: 999px;
            padding: 6px 10px;
            font-weight: 600;
            font-size: .82rem;
            line-height: 1;
            display:inline-flex;
            align-items:center;
            gap: 6px;
        }
        .table-scroll{
            max-height: 420px;
            overflow: auto;
        }

        /* ✅ Mobile menu button */
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
        .admin-mobile-menu-btn i{ font-size:1.35rem; color: var(--vv-primary); }

        /* ================= Mobile Sidebar (same behavior as dashboard/profile) ================= */
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

                overflow-y:auto;              /* ✅ ensures menu items show */
                -webkit-overflow-scrolling:touch;
            }
            body.admin-sidebar-open .sidebar{
                transform:translate3d(0,0,0);
            }
            body.admin-sidebar-open{ overflow:hidden; }
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
                <h1 class="admin-page-title">Reports</h1>
                <p class="admin-page-subtitle mb-0">Sales reports & analytics (based on orders, products and customers).</p>
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

        <!-- SUMMARY -->
        <div class="summary-panel mb-3">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                <div>
                    <h2 class="summary-title">Summary</h2>
                    <p class="summary-sub">Showing metrics for: <strong>All time</strong></p>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-12 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-ic green"><i class="bx bx-dollar-circle"></i></div>
                        <div>
                            <p class="kpi-label">Total revenue</p>
                            <p class="kpi-value"><?php echo money_fmt($totalRevenue); ?></p>
                            <p class="kpi-sub">Paid / shipped / completed</p>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-ic orange"><i class="bx bx-cart"></i></div>
                        <div>
                            <p class="kpi-label">Completed orders</p>
                            <p class="kpi-value"><?php echo number_format($completedOrders); ?></p>
                            <p class="kpi-sub">Completed only</p>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-ic teal"><i class="bx bx-line-chart"></i></div>
                        <div>
                            <p class="kpi-label">Avg order value</p>
                            <p class="kpi-value"><?php echo money_fmt($avgOrderValue); ?></p>
                            <p class="kpi-sub">Completed only</p>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-ic blue"><i class="bx bx-group"></i></div>
                        <div>
                            <p class="kpi-label">Buying customers</p>
                            <p class="kpi-value"><?php echo number_format($totalBuyingCustomers); ?></p>
                            <p class="kpi-sub">Distinct buyers</p>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-ic purple"><i class="bx bx-refresh"></i></div>
                        <div>
                            <p class="kpi-label">Orders / customer</p>
                            <p class="kpi-value"><?php echo number_format($ordersPerCustomer, 2); ?></p>
                            <p class="kpi-sub">Sales statuses only</p>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-ic rose"><i class="bx bxs-crown"></i></div>
                        <div>
                            <p class="kpi-label">Retention rate</p>
                            <p class="kpi-value"><?php echo pct_fmt($retentionRate, 1); ?></p>
                            <p class="kpi-sub"><?php echo number_format($repeatCustomers); ?> repeat buyers</p>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-ic teal"><i class="bx bx-bolt-circle"></i></div>
                        <div>
                            <p class="kpi-label">New arrivals sales</p>
                            <p class="kpi-value"><?php echo pct_fmt($newArrivalsPct, 1); ?></p>
                            <p class="kpi-sub"><?php echo money_fmt($newArrivalsRevenue); ?> revenue</p>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-ic orange"><i class="bx bx-purchase-tag"></i></div>
                        <div>
                            <p class="kpi-label">Active promotions</p>
                            <p class="kpi-value"><?php echo number_format($activePromotions); ?></p>
                            <p class="kpi-sub">Running now</p>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-ic blue"><i class="bx bx-home-alt"></i></div>
                        <div>
                            <p class="kpi-label">Featured products</p>
                            <p class="kpi-value"><?php echo number_format($featuredCount); ?></p>
                            <p class="kpi-sub">Hot products</p>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-ic orange"><i class="bx bx-trophy"></i></div>
                        <div>
                            <p class="kpi-label">Discount rate</p>
                            <p class="kpi-value"><?php echo '-' . pct_fmt($discountRate, 2); ?></p>
                            <p class="kpi-sub"><?php echo money_fmt($discountSumAll); ?> discounts</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CHARTS -->
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-6">
                <div class="chart-card">
                    <div class="chart-head">
                        <div>
                            <p class="chart-title">Monthly Sales Trend</p>
                            <p class="chart-subtitle">Revenue grouped by month (last 12 months)</p>
                        </div>
                        <div class="chart-actions">
                            <button type="button" class="btn btn-outline-secondary js-no-open"
                                    data-bs-toggle="modal" data-bs-target="#modalSalesTrend">
                                <i class="bx bx-table"></i> Details
                            </button>
                            <a class="btn btn-outline-success js-no-open"
                               href="reports.php?export=sales_trend">
                                <i class="bx bx-download"></i> Download Excel
                            </a>
                        </div>
                    </div>
                    <div class="chart-body">
                        <div class="chart-wrap" data-bs-toggle="modal" data-bs-target="#modalSalesTrend">
                            <canvas id="chartSalesTrend"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="chart-card">
                    <div class="chart-head">
                        <div>
                            <p class="chart-title">Top 10 Best-Selling Products</p>
                            <p class="chart-subtitle">Ranked by quantity sold</p>
                        </div>
                        <div class="chart-actions">
                            <button type="button" class="btn btn-outline-secondary js-no-open"
                                    data-bs-toggle="modal" data-bs-target="#modalTopProducts">
                                <i class="bx bx-table"></i> Details
                            </button>
                            <a class="btn btn-outline-success js-no-open"
                               href="reports.php?export=top_products">
                                <i class="bx bx-download"></i> Download Excel
                            </a>
                        </div>
                    </div>
                    <div class="chart-body">
                        <div class="chart-wrap" data-bs-toggle="modal" data-bs-target="#modalTopProducts">
                            <canvas id="chartTopProducts"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-6">
                <div class="chart-card">
                    <div class="chart-head">
                        <div>
                            <p class="chart-title">Top Royalty Customers (VIP)</p>
                            <p class="chart-subtitle">Ranked by total spent</p>
                        </div>
                        <div class="chart-actions">
                            <button type="button" class="btn btn-outline-secondary js-no-open"
                                    data-bs-toggle="modal" data-bs-target="#modalVIP">
                                <i class="bx bx-table"></i> Details
                            </button>
                            <a class="btn btn-outline-success js-no-open"
                               href="reports.php?export=vip_customers">
                                <i class="bx bx-download"></i> Download Excel
                            </a>
                        </div>
                    </div>
                    <div class="chart-body">
                        <div class="chart-wrap" data-bs-toggle="modal" data-bs-target="#modalVIP">
                            <canvas id="chartTopVIP"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="chart-card">
                    <div class="chart-head">
                        <div>
                            <p class="chart-title">Customer Retention</p>
                            <p class="chart-subtitle">Repeat vs one-time buyers</p>
                        </div>
                        <div class="chart-actions">
                            <button type="button" class="btn btn-outline-secondary js-no-open"
                                    data-bs-toggle="modal" data-bs-target="#modalRetention">
                                <i class="bx bx-table"></i> Details
                            </button>
                            <a class="btn btn-outline-success js-no-open"
                               href="reports.php?export=retention">
                                <i class="bx bx-download"></i> Download Excel
                            </a>
                        </div>
                    </div>
                    <div class="chart-body">
                        <div class="chart-wrap" data-bs-toggle="modal" data-bs-target="#modalRetention">
                            <canvas id="chartRetention"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-6">
                <div class="chart-card">
                    <div class="chart-head">
                        <div>
                            <p class="chart-title">Customer Purchase Frequency</p>
                            <p class="chart-subtitle">1 time, 2–3 times, 4+ times</p>
                        </div>
                        <div class="chart-actions">
                            <button type="button" class="btn btn-outline-secondary js-no-open"
                                    data-bs-toggle="modal" data-bs-target="#modalFrequency">
                                <i class="bx bx-table"></i> Details
                            </button>
                            <a class="btn btn-outline-success js-no-open"
                               href="reports.php?export=frequency">
                                <i class="bx bx-download"></i> Download Excel
                            </a>
                        </div>
                    </div>
                    <div class="chart-body">
                        <div class="chart-wrap" data-bs-toggle="modal" data-bs-target="#modalFrequency">
                            <canvas id="chartFrequency"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="chart-card">
                    <div class="chart-head">
                        <div>
                            <p class="chart-title">Promotion vs Regular Sales</p>
                            <p class="chart-subtitle">Classified by discount_amount &gt; 0</p>
                        </div>
                        <div class="chart-actions">
                            <button type="button" class="btn btn-outline-secondary js-no-open"
                                    data-bs-toggle="modal" data-bs-target="#modalPromo">
                                <i class="bx bx-table"></i> Details
                            </button>
                            <a class="btn btn-outline-success js-no-open"
                               href="reports.php?export=promo_vs_regular">
                                <i class="bx bx-download"></i> Download Excel
                            </a>
                        </div>
                    </div>
                    <div class="chart-body">
                        <div class="chart-wrap" data-bs-toggle="modal" data-bs-target="#modalPromo">
                            <canvas id="chartPromoVsRegular"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary rounded-pill">
            <i class='bx bx-arrow-back me-1'></i> Back to dashboard
        </a>
    </main>
</div>

<!-- =========================
     DETAILS MODALS
========================= -->

<!-- Monthly Sales Trend -->
<div class="modal fade details-modal" id="modalSalesTrend" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Monthly Sales Trend — Details</h5>
                    <small class="text-muted">Base table used to generate the chart (grouped by month)</small>
                </div>
                <div class="modal-actions">
                    <a class="btn btn-outline-success js-no-open" href="reports.php?export=sales_trend">
                        <i class="bx bx-download"></i> Download Excel
                    </a>
                    <button type="button" class="btn btn-outline-secondary js-no-open" data-bs-dismiss="modal">
                        <i class="bx bx-x"></i> Close
                    </button>
                </div>
            </div>
            <div class="modal-body">
                <div class="table-responsive table-scroll">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Month</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Customers</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Discount Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($detailsMonthlySales as $r): ?>
                            <tr>
                                <td><?php echo h($r['month']); ?></td>
                                <td class="text-end"><?php echo number_format((int)$r['orders_count']); ?></td>
                                <td class="text-end"><?php echo number_format((int)$r['customers_count']); ?></td>
                                <td class="text-end"><?php echo money_fmt($r['revenue']); ?></td>
                                <td class="text-end"><?php echo money_fmt($r['discount_sum']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted d-block mt-2">Orders included: paid / shipped / completed.</small>
            </div>
        </div>
    </div>
</div>

<!-- Top Products -->
<div class="modal fade details-modal" id="modalTopProducts" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Top 10 Best-Selling Products — Details</h5>
                    <small class="text-muted">Base table used to generate the chart (sorted by quantity)</small>
                </div>
                <div class="modal-actions">
                    <a class="btn btn-outline-success js-no-open" href="reports.php?export=top_products">
                        <i class="bx bx-download"></i> Download Excel
                    </a>
                    <button type="button" class="btn btn-outline-secondary js-no-open" data-bs-dismiss="modal">
                        <i class="bx bx-x"></i> Close
                    </button>
                </div>
            </div>
            <div class="modal-body">
                <div class="table-responsive table-scroll">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Avg Unit Price</th>
                            <th class="text-end">Revenue</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php $i=1; foreach ($detailsTopProducts as $r): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><?php echo h($r['product_name']); ?></td>
                                <td class="text-end"><?php echo number_format((int)$r['qty']); ?></td>
                                <td class="text-end"><?php echo number_format((int)$r['orders_count']); ?></td>
                                <td class="text-end"><?php echo money_fmt($r['avg_unit_price']); ?></td>
                                <td class="text-end"><?php echo money_fmt($r['revenue']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted d-block mt-2">Calculated from order_items joined with sales-status orders.</small>
            </div>
        </div>
    </div>
</div>

<!-- VIP -->
<div class="modal fade details-modal" id="modalVIP" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Top Royalty Customers (VIP) — Details</h5>
                    <small class="text-muted">Base table used to generate the chart (sorted by total spent)</small>
                </div>
                <div class="modal-actions">
                    <a class="btn btn-outline-success js-no-open" href="reports.php?export=vip_customers">
                        <i class="bx bx-download"></i> Download Excel
                    </a>
                    <button type="button" class="btn btn-outline-secondary js-no-open" data-bs-dismiss="modal">
                        <i class="bx bx-x"></i> Close
                    </button>
                </div>
            </div>
            <div class="modal-body">
                <div class="table-responsive table-scroll">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Email</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Avg Order</th>
                            <th class="text-end">Total Spent</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php $i=1; foreach ($detailsVIP as $r): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><?php echo h($r['full_name']); ?></td>
                                <td class="text-muted"><?php echo h($r['email']); ?></td>
                                <td class="text-end"><?php echo number_format((int)$r['orders_count']); ?></td>
                                <td class="text-end"><?php echo money_fmt($r['avg_order']); ?></td>
                                <td class="text-end"><?php echo money_fmt($r['spent']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted d-block mt-2">Orders included: paid / shipped / completed.</small>
            </div>
        </div>
    </div>
</div>

<!-- Retention -->
<div class="modal fade details-modal" id="modalRetention" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Customer Retention — Details</h5>
                    <small class="text-muted">Repeat (>=2 orders) vs One-time (1 order)</small>
                </div>
                <div class="modal-actions">
                    <a class="btn btn-outline-success js-no-open" href="reports.php?export=retention">
                        <i class="bx bx-download"></i> Download Excel
                    </a>
                    <button type="button" class="btn btn-outline-secondary js-no-open" data-bs-dismiss="modal">
                        <i class="bx bx-x"></i> Close
                    </button>
                </div>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-2">
                    <div class="col-12 col-lg-3">
                        <div class="kpi-card">
                            <div class="kpi-ic blue"><i class="bx bx-user"></i></div>
                            <div>
                                <p class="kpi-label">Buying customers</p>
                                <p class="kpi-value"><?php echo number_format($totalBuyingCustomers); ?></p>
                                <p class="kpi-sub">All sales-status buyers</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-3">
                        <div class="kpi-card">
                            <div class="kpi-ic teal"><i class="bx bx-repeat"></i></div>
                            <div>
                                <p class="kpi-label">Repeat</p>
                                <p class="kpi-value"><?php echo number_format($repeatCustomers); ?></p>
                                <p class="kpi-sub">>= 2 orders</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-3">
                        <div class="kpi-card">
                            <div class="kpi-ic orange"><i class="bx bx-user-pin"></i></div>
                            <div>
                                <p class="kpi-label">One-time</p>
                                <p class="kpi-value"><?php echo number_format($oneTimeCustomers); ?></p>
                                <p class="kpi-sub">1 order</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-3">
                        <div class="kpi-card">
                            <div class="kpi-ic rose"><i class="bx bx-line-chart"></i></div>
                            <div>
                                <p class="kpi-label">Retention rate</p>
                                <p class="kpi-value"><?php echo pct_fmt($retentionRate, 2); ?></p>
                                <p class="kpi-sub">Repeat / Buying</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive table-scroll">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Email</th>
                            <th class="text-end">Orders</th>
                            <th>Segment</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($detailsRetentionCustomers as $c): ?>
                            <tr>
                                <td><?php echo h($c['full_name']); ?></td>
                                <td class="text-muted"><?php echo h($c['email']); ?></td>
                                <td class="text-end"><?php echo number_format((int)$c['orders_count']); ?></td>
                                <td><?php echo h($c['segment']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted d-block mt-2">Classification is based only on sales statuses: paid / shipped / completed.</small>
            </div>
        </div>
    </div>
</div>

<!-- Frequency -->
<div class="modal fade details-modal" id="modalFrequency" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Customer Purchase Frequency — Details</h5>
                    <small class="text-muted">Customers bucketed by order count</small>
                </div>
                <div class="modal-actions">
                    <a class="btn btn-outline-success js-no-open" href="reports.php?export=frequency">
                        <i class="bx bx-download"></i> Download Excel
                    </a>
                    <button type="button" class="btn btn-outline-secondary js-no-open" data-bs-dismiss="modal">
                        <i class="bx bx-x"></i> Close
                    </button>
                </div>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-2">
                    <div class="col-12 col-lg-4">
                        <div class="kpi-card">
                            <div class="kpi-ic orange"><i class="bx bx-user"></i></div>
                            <div>
                                <p class="kpi-label">1 time</p>
                                <p class="kpi-value"><?php echo number_format($freq['1_time']); ?></p>
                                <p class="kpi-sub">Customers</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <div class="kpi-card">
                            <div class="kpi-ic blue"><i class="bx bx-user-check"></i></div>
                            <div>
                                <p class="kpi-label">2–3 times</p>
                                <p class="kpi-value"><?php echo number_format($freq['2_3']); ?></p>
                                <p class="kpi-sub">Customers</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <div class="kpi-card">
                            <div class="kpi-ic teal"><i class="bx bxs-crown"></i></div>
                            <div>
                                <p class="kpi-label">4+ times</p>
                                <p class="kpi-value"><?php echo number_format($freq['4_plus']); ?></p>
                                <p class="kpi-sub">VIP customers</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive table-scroll">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Email</th>
                            <th class="text-end">Orders</th>
                            <th>Bucket</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($detailsFrequencyCustomers as $c): ?>
                            <tr>
                                <td><?php echo h($c['full_name']); ?></td>
                                <td class="text-muted"><?php echo h($c['email']); ?></td>
                                <td class="text-end"><?php echo number_format((int)$c['orders_count']); ?></td>
                                <td><?php echo h($c['bucket']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted d-block mt-2">Bucketed using sales statuses only: paid / shipped / completed.</small>
            </div>
        </div>
    </div>
</div>

<!-- Promo vs Regular -->
<div class="modal fade details-modal" id="modalPromo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Promotion vs Regular Sales — Details</h5>
                    <small class="text-muted">Promo classification: discount_amount &gt; 0</small>
                </div>
                <div class="modal-actions">
                    <a class="btn btn-outline-success js-no-open" href="reports.php?export=promo_vs_regular">
                        <i class="bx bx-download"></i> Download Excel
                    </a>
                    <button type="button" class="btn btn-outline-secondary js-no-open" data-bs-dismiss="modal">
                        <i class="bx bx-x"></i> Close
                    </button>
                </div>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-2">
                    <div class="col-12 col-lg-6">
                        <div class="kpi-card">
                            <div class="kpi-ic teal"><i class="bx bx-purchase-tag"></i></div>
                            <div>
                                <p class="kpi-label">With promotion</p>
                                <p class="kpi-value"><?php echo money_fmt($promoRevenue); ?></p>
                                <p class="kpi-sub">discount_amount &gt; 0</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="kpi-card">
                            <div class="kpi-ic blue"><i class="bx bx-store"></i></div>
                            <div>
                                <p class="kpi-label">Regular</p>
                                <p class="kpi-value"><?php echo money_fmt($regularRevenue); ?></p>
                                <p class="kpi-sub">discount_amount = 0</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive table-scroll">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Customer</th>
                            <th class="text-end">Subtotal</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Total</th>
                            <th>Class</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($detailsPromoOrders as $o): ?>
                            <tr>
                                <td><?php echo h($o['order_number']); ?></td>
                                <td><?php echo $o['created_at'] ? h(date('Y-m-d H:i', strtotime($o['created_at']))) : '—'; ?></td>
                                <td class="text-capitalize"><?php echo h($o['status']); ?></td>
                                <td>
                                    <div style="font-weight:600;"><?php echo h($o['customer_name']); ?></div>
                                    <small class="text-muted"><?php echo h($o['customer_email']); ?></small>
                                </td>
                                <td class="text-end"><?php echo money_fmt($o['subtotal']); ?></td>
                                <td class="text-end"><?php echo money_fmt($o['discount_amount']); ?></td>
                                <td class="text-end"><?php echo money_fmt($o['total_amount']); ?></td>
                                <td><?php echo h($o['type']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <small class="text-muted d-block mt-2">
                    The chart totals are computed directly from these orders (promo = discount_amount &gt; 0).
                </small>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
/* ==========================================================
   Chart.js fit-container:
   - wrapper has fixed height (.chart-wrap)
   - responsive: true
   - maintainAspectRatio: false
========================================================== */

const monthLabels     = <?php echo json_encode($monthLabels, JSON_UNESCAPED_UNICODE); ?>;
const monthlyRevenue  = <?php echo json_encode($monthlyRevenue, JSON_UNESCAPED_UNICODE); ?>;

const topProdLabels   = <?php echo json_encode($topProdLabels, JSON_UNESCAPED_UNICODE); ?>;
const topProdQty      = <?php echo json_encode($topProdQty, JSON_UNESCAPED_UNICODE); ?>;

const vipLabels       = <?php echo json_encode($vipLabels, JSON_UNESCAPED_UNICODE); ?>;
const vipSpent        = <?php echo json_encode($vipSpent, JSON_UNESCAPED_UNICODE); ?>;

const repeatCustomers = <?php echo (int)$repeatCustomers; ?>;
const oneTimeCustomers = <?php echo (int)$oneTimeCustomers; ?>;

const freq1 = <?php echo (int)$freq['1_time']; ?>;
const freq2 = <?php echo (int)$freq['2_3']; ?>;
const freq3 = <?php echo (int)$freq['4_plus']; ?>;

const promoRevenue    = <?php echo json_encode($promoRevenue); ?>;
const regularRevenue  = <?php echo json_encode($regularRevenue); ?>;

function numFmt(v){
  try { return Number(v).toLocaleString(); } catch(e){ return v; }
}
function moneyFmt(v){
  try { return '$' + Number(v).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0}); }
  catch(e){ return '$' + v; }
}

const commonOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: true },
    tooltip: {
      callbacks: {
        label: function(ctx){
          const val = ctx.raw;
          return `${ctx.dataset.label ?? ctx.label}: ${moneyFmt(val)}`;
        }
      }
    }
  }
};

// 1) Monthly sales trend (line)
new Chart(document.getElementById('chartSalesTrend'), {
  type: 'line',
  data: {
    labels: monthLabels,
    datasets: [{ label: 'Revenue', data: monthlyRevenue, tension: 0.35, fill: false }]
  },
  options: {
    ...commonOptions,
    scales: {
      x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
      y: { beginAtZero: true, ticks: { maxTicksLimit: 12, callback: (v)=> numFmt(v) } }
    }
  }
});

// 2) Top products (horizontal bar)
new Chart(document.getElementById('chartTopProducts'), {
  type: 'bar',
  data: {
    labels: topProdLabels,
    datasets: [{ label: 'Units Sold', data: topProdQty }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: true },
      tooltip: { callbacks: { label: (ctx)=> `Units Sold: ${numFmt(ctx.raw)}` } }
    },
    indexAxis: 'y',
    scales: {
      x: { beginAtZero: true, ticks: { maxTicksLimit: 12, callback: (v)=> numFmt(v) } },
      y: { ticks: { autoSkip: false } }
    }
  }
});

// 3) VIP (bar)
new Chart(document.getElementById('chartTopVIP'), {
  type: 'bar',
  data: {
    labels: vipLabels,
    datasets: [{ label: 'Total Spent', data: vipSpent }]
  },
  options: {
    ...commonOptions,
    plugins: {
      ...commonOptions.plugins,
      tooltip: { callbacks: { label: (ctx)=> `Total Spent: ${moneyFmt(ctx.raw)}` } }
    },
    scales: {
      x: { ticks: { autoSkip: true, maxTicksLimit: 8 } },
      y: { beginAtZero: true, ticks: { maxTicksLimit: 12, callback: (v)=> numFmt(v) } }
    }
  }
});

// 4) Retention (doughnut)
new Chart(document.getElementById('chartRetention'), {
  type: 'doughnut',
  data: {
    labels: ['Repeat Customers', 'One-time Buyers'],
    datasets: [{ data: [repeatCustomers, oneTimeCustomers] }]
  },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});

// 5) Frequency (pie)
new Chart(document.getElementById('chartFrequency'), {
  type: 'pie',
  data: {
    labels: ['1 Time', '2–3 Times', '4+ Times (VIP)'],
    datasets: [{ data: [freq1, freq2, freq3] }]
  },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});

// 6) Promo vs regular (doughnut)
new Chart(document.getElementById('chartPromoVsRegular'), {
  type: 'doughnut',
  data: {
    labels: ['With Promotion', 'Regular'],
    datasets: [{ data: [promoRevenue, regularRevenue] }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom' },
      tooltip: { callbacks: { label: (ctx)=> `${ctx.label}: ${moneyFmt(ctx.raw)}` } }
    }
  }
});

/* ✅ Don’t open modal when clicking the buttons inside header */
document.querySelectorAll('.js-no-open').forEach(el => {
  el.addEventListener('click', (e) => e.stopPropagation());
});

/* ================= Mobile Sidebar (same fix used before) ================= */
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
    sidebar.querySelectorAll('a').forEach(a => {
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
