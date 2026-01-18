<?php
// admin/ajax/export_dashboard.php
session_start();
require_once '../../db.php';

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

$allowedTypes = ['revenue_profit', 'category_pie'];
$type = $_GET['type'] ?? '';
if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    echo "Invalid export type";
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
        $periodLabel = 'Last 30 days';
        break;
    case 'last_90':
        $dateFilterSql = " AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
        $periodLabel = 'Last 90 days';
        break;
    case 'this_year':
        $dateFilterSql = " AND YEAR(o.created_at) = YEAR(CURDATE())";
        $periodLabel = 'This year';
        break;
    default:
        $periodLabel = 'All time';
        break;
}

$filename = "dashboard_" . $type . "_" . $period . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<h3>Velvet Vogue — Dashboard Export</h3>";
echo "<p>Period: <strong>" . htmlspecialchars($periodLabel) . "</strong></p>";

echo "<table border='1' cellspacing='0' cellpadding='6' style='border-collapse:collapse;'>";

if ($type === 'revenue_profit') {
    echo "<thead style='background:#f2f2f2;font-weight:bold;'>
            <tr>
              <th>Month</th>
              <th>Revenue</th>
              <th>Cost</th>
              <th>Profit</th>
            </tr>
          </thead><tbody>";

    $sql = "
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

    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $rev  = (float)$row['revenue'];
            $cost = (float)$row['cost'];
            $prof = $rev - $cost;

            $dt = DateTime::createFromFormat('Y-m', $row['ym']);
            $label = $dt ? $dt->format('M Y') : $row['ym'];

            echo "<tr>
                    <td>" . htmlspecialchars($label) . "</td>
                    <td>" . number_format($rev, 2) . "</td>
                    <td>" . number_format($cost, 2) . "</td>
                    <td>" . number_format($prof, 2) . "</td>
                  </tr>";
        }
        $res->free();
    }

    echo "</tbody>";

} else { // category_pie
    echo "<thead style='background:#f2f2f2;font-weight:bold;'>
            <tr>
              <th>Category</th>
              <th>Unit price</th>
              <th>Total sold</th>
              <th>Revenue</th>
            </tr>
          </thead><tbody>";

    $sql = "
      SELECT
        COALESCE(cat.name, 'Uncategorized') AS category_label,
        COALESCE(SUM(oi.quantity), 0)       AS total_sold,
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

    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $revenue = (float)$row['total_revenue'];
            $sold    = (int)$row['total_sold'];
            $unit    = $sold > 0 ? ($revenue / $sold) : 0;

            echo "<tr>
                    <td>" . htmlspecialchars($row['category_label']) . "</td>
                    <td>" . number_format($unit, 2) . "</td>
                    <td>" . number_format($sold) . "</td>
                    <td>" . number_format($revenue, 2) . "</td>
                  </tr>";
        }
        $res->free();
    }

    echo "</tbody>";
}

echo "</table>";
echo "</body></html>";
