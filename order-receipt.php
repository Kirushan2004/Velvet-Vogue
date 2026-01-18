<?php
// order-receipt.php – Invoice / receipt with product photos, PDF download & print
// Modern + responsive + mobile menu + image fallback (browser + PDF)
session_start();
require_once 'db.php';

// ---------- Require login ----------
if (empty($_SESSION['customer_id'])) {
    $_SESSION['customer_login_notice'] = 'Please sign in to view your order receipts.';
    $redirect = 'customer-orders.php';
    header('Location: customer-login.php?redirect=' . urlencode($redirect));
    exit;
}

$customer_id    = (int)$_SESSION['customer_id'];
$customer_name  = $_SESSION['customer_name'] ?? '';
$customer_first = $customer_name ? (explode(' ', trim($customer_name))[0] ?? 'Account') : 'Account';

// ---------- Header counts ----------
$wishlist_ids   = (isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])) ? $_SESSION['wishlist'] : [];
$wishlist_count = count($wishlist_ids);

// cart items (supports both legacy int + new array items)
$cart_items = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        if (is_array($item)) $cart_items += (int)($item['qty'] ?? 0);
        else $cart_items += (int)$item;
    }
}

// ---------- Composer autoload (Dompdf) ----------
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die('Dompdf not installed. Run "composer require dompdf/dompdf" in your project root.');
}
require_once $autoloadPath;

use Dompdf\Dompdf;
use Dompdf\Options;

// ---------- Helpers ----------
function money_fmt($v) { return '$' . number_format((float)$v, 2); }

function order_status_label($status) {
    switch (strtolower((string)$status)) {
        case 'pending':   return 'Pending';
        case 'paid':      return 'Paid';
        case 'shipped':   return 'Shipped';
        case 'completed': return 'Completed';
        case 'cancelled': return 'Cancelled';
        case 'refunded':  return 'Refunded';
        default:          return ucfirst((string)$status);
    }
}

/**
 * Build a base URL like http://localhost/WDD_Final_System/
 * NOTE: Dompdf is happier when images are absolute URLs.
 */
function get_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // directory of this script
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . ($dir ? $dir . '/' : '/');
}

/**
 * Convert a product image path to an absolute URL for browser + PDF.
 * - Accepts absolute URLs (http/https) as-is
 * - If relative, prefixes with $baseUrl
 */
function to_abs_url(string $baseUrl, string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    return $baseUrl . ltrim($path, '/');
}

/**
 * A safe placeholder image (SVG) as a data URI (works in browser + dompdf).
 * Keep it simple (avoid heavy SVG features).
 */
function placeholder_svg_data_uri(string $label = 'Image'): string {
    $label = substr(preg_replace('/[<>]/', '', $label), 0, 28);
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
  <rect width="200" height="200" fill="#f4f3fb"/>
  <path d="M55 125l30-32 26 28 16-18 35 38H55z" fill="#d9d3ea"/>
  <circle cx="78" cy="78" r="10" fill="#d9d3ea"/>
  <text x="100" y="165" text-anchor="middle" font-family="Arial, sans-serif" font-size="12" fill="#b7b0c9">$label</text>
</svg>
SVG;
    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
}

// ---------- Load order & validate ownership ----------
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($order_id <= 0) {
    http_response_code(400);
    echo "Invalid order.";
    exit;
}

$sql = "SELECT *
        FROM orders
        WHERE id = ? AND customer_id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo "Could not prepare order query.";
    exit;
}
$stmt->bind_param('ii', $order_id, $customer_id);
$stmt->execute();
$res   = $stmt->get_result();
$order = $res->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(404);
    echo "Order not found.";
    exit;
}

// ---------- Load order items ----------
$sql = "
    SELECT
        oi.*,
        p.sku,
        p.image_url
    FROM order_items oi
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo "Could not prepare order items query.";
    exit;
}
$stmt->bind_param('i', $order_id);
$stmt->execute();
$res   = $stmt->get_result();
$items = [];
while ($row = $res->fetch_assoc()) $items[] = $row;
$stmt->close();

// ---------- Build receipt HTML ----------
function build_receipt_html(array $order, array $items, string $baseUrl, bool $forPdf = false): string
{
    global $wishlist_count, $cart_items, $customer_first, $customer_id;

    $orderNumber   = htmlspecialchars($order['order_number'] ?? ('#' . $order['id']));
    $statusLabel   = order_status_label($order['status'] ?? '');
    $createdAtRaw  = $order['created_at'] ?? '';
    $orderDateTime = $createdAtRaw ? date('Y-m-d H:i', strtotime($createdAtRaw)) : '';
    $paymentMethod = strtoupper($order['payment_method'] ?? '');
    $shippingAddr  = nl2br(htmlspecialchars($order['shipping_address'] ?? ''));

    $subtotal = (float)($order['subtotal'] ?? 0);
    $discount = (float)($order['discount_amount'] ?? 0);
    $shipping = (float)($order['shipping_cost'] ?? 0);
    $tax      = (float)($order['tax_amount'] ?? 0);
    $grand    = (float)($order['total_amount'] ?? 0);

    $customerName  = htmlspecialchars($order['full_name'] ?? '');
    $customerEmail = htmlspecialchars($order['email'] ?? '');
    $customerPhone = htmlspecialchars($order['phone'] ?? '');

    // Company details (adjust as needed)
    $companyName    = 'Velvet Vogue';
    $companyAddress = "123 Fashion Street<br>Colombo, Sri Lanka";
    $companyEmail   = "support@velvetvogue.test";
    $companyPhone   = "+94 11 234 5678";

    // same soft background for view & PDF
    $bodyBg = '#f3f0ff';

    // placeholder image
    $placeholderImg = placeholder_svg_data_uri('No image');

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php if (!$forPdf): ?>
        <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php endif; ?>
    <title>Receipt <?php echo $orderNumber; ?> | Velvet Vogue</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <?php if (!$forPdf): ?>
        <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
        <link rel="stylesheet" href="css/style.css">
    <?php endif; ?>

    <style>
        :root {
            --vv-bg: #f6f4fb;
            --vv-bg-muted: #faf9fe;
            --vv-surface: #ffffff;
            --vv-border-soft: #ece5f5;
            --vv-border-strong: #ddd4ef;

            --vv-text-main: #2b2438;
            --vv-text-muted: #7b738e;
            --vv-text-soft: #a49bb6;

            --vv-accent: #6b1f4f;
            --vv-accent-soft: rgba(107, 31, 79, 0.08);
            --vv-accent-strong: #4d1137;
            --vv-shadow-subtle: 0 10px 30px rgba(27, 20, 78, 0.06);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0;
            background: <?php echo $bodyBg; ?>;
            font-family: "Poppins", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #222;
            font-size: 12px;
        }

        .vv-page { padding: 18px; }

        /* ===== Browser-only: mobile menu + top actions ===== */
        <?php if (!$forPdf): ?>
        .vv-nav-wrapper{ position:relative; }

        .vv-nav-toggle{
            display:none;
            border:1px solid var(--vv-border-soft);
            background:#fff;
            border-radius:12px;
            padding:0.45rem 0.6rem;
            cursor:pointer;
            line-height:1;
        }
        .vv-mobile-nav{ display:none; }

        @media (max-width: 991.98px){
            .vv-nav{ display:none; }
            .vv-nav-wrapper{
                display:flex;
                align-items:center;
                gap:0.6rem;
            }
            .vv-nav-toggle{
                display:inline-flex;
                align-items:center;
                justify-content:center;
                order:0;
            }
            .vv-logo{
                order:1;
                margin-right:auto;
            }
            .vv-nav-actions{
                order:2;
                display:flex;
                align-items:center;
                gap:0.6rem;
                flex-wrap:wrap;
                justify-content:flex-end;
            }

            .vv-mobile-nav{
                display:block;
                position:absolute;
                left:0.75rem;
                top:calc(100% + 0.75rem);
                width:min(360px, calc(100% - 1.5rem));
                background:#fff;
                border:1px solid var(--vv-border-soft);
                border-radius:18px;
                box-shadow: var(--vv-shadow-subtle);
                padding:0.6rem;
                z-index:40;
                transform: translateY(-8px);
                opacity:0;
                pointer-events:none;
                transition:160ms ease;
            }
            body.vv-nav-open .vv-mobile-nav{
                transform: translateY(0);
                opacity:1;
                pointer-events:auto;
            }
            .vv-mobile-nav a{
                display:block;
                padding:0.6rem 0.8rem;
                border-radius:12px;
                text-decoration:none;
                color: var(--vv-text-main);
            }
            .vv-mobile-nav a:hover{
                background:#f7f0fa;
                color: var(--vv-accent);
            }
        }

        .vv-topbar {
            max-width: 960px;
            margin: 0 auto 12px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .vv-topbar-left { display: flex; align-items: center; gap: 6px; }
        .vv-topbar-actions { display: flex; gap: 6px; flex-wrap: wrap; }

        .vv-btn {
            border-radius: 999px;
            border: 1px solid var(--vv-border-strong);
            padding: 6px 14px;
            font-size: 11px;
            cursor: pointer;
            background: #fff;
            color: var(--vv-text-main);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .vv-btn-primary {
            background: var(--vv-accent);
            color: #fff;
            border-color: var(--vv-accent);
        }
        .vv-btn:hover { filter: brightness(0.965); }

        /* account dropdown (header) */
        .vv-user-menu { position: relative; }
        .vv-user-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 0.35rem);
            min-width: 170px;
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.4rem 0.2rem;
            display: none;
            z-index: 30;
        }
        .vv-user-menu.open .vv-user-dropdown { display: block; }
        .vv-user-dropdown a {
            display: block;
            padding: 0.35rem 0.9rem;
            font-size: 0.82rem;
            color: var(--vv-text-main);
            text-decoration: none;
        }
        .vv-user-dropdown a:hover { background: #f7f0fa; color: var(--vv-accent); }
        .vv-user-dropdown-separator { height: 1px; background: #eee4ff; margin: 0.25rem 0.4rem; }
        <?php endif; ?>

        /* ===== Receipt card ===== */
        .vv-receipt-wrap {
            max-width: 960px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 0 18px rgba(0,0,0,0.04);
            padding: 20px 22px;
        }

        .vv-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            border-bottom: 1px solid #ece7ff;
            padding-bottom: 12px;
            margin-bottom: 12px;
        }

        /* Receipt logo */
        .vv-receipt-logo {
            display: inline-flex;
            align-items: baseline;
            gap: 0.28rem;
            line-height: 1.1;
        }
        .vv-receipt-logo-main,
        .vv-receipt-logo-sub {
            font-family: "Playfair Display", serif;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            font-size: 1.05rem;
        }
        .vv-receipt-logo-sub { color: var(--vv-accent); }

        .vv-company-meta {
            margin-top: 8px;
            font-size: 10.5px;
            color: var(--vv-text-muted);
        }
        .vv-company-meta div { margin-bottom: 2px; }

        .vv-invoice-meta {
            text-align: right;
            font-size: 11px;
        }
        .vv-invoice-meta div { margin-bottom: 4px; }
        .vv-invoice-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--vv-text-main);
        }
        .vv-badge-status {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 600;
            border: 1px solid #d4c9ff;
            background: #f2eeff;
            color: #4b3a9b;
        }

        .vv-two-cols {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
            align-items: stretch;
        }
        .vv-two-cols > div {
            flex: 1 1 0;
            display: flex;
            flex-direction: column;
        }

        .vv-section-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--vv-text-muted);
            margin-bottom: 6px;
            font-weight: 600;
        }

        .vv-box {
            background: #faf8ff;
            border-radius: 8px;
            padding: 8px 10px;
            border: 1px solid #ebe4ff;
            font-size: 11px;
            flex: 1;
        }
        .vv-box-row { margin-bottom: 3px; }
        .vv-box-label { font-weight: 600; margin-right: 4px; }

        .vv-items-title {
            font-size: 11.5px;
            font-weight: 600;
            margin: 8px 0 6px;
            color: var(--vv-text-main);
        }

        table.vv-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        table.vv-items-table thead th {
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            text-align: left;
            padding: 6px 8px;
            background: #f3f0ff;
            border-bottom: 1px solid #e0dafb;
        }
        table.vv-items-table tbody td {
            padding: 7px 8px;
            border-bottom: 1px solid #f0ecff;
            vertical-align: top;
            font-size: 11px;
        }
        table.vv-items-table tbody tr:last-child td {
            border-bottom: 1px solid #e0dafb;
        }
        .vv-col-num { width: 30px; text-align: center; }
        .vv-col-qty { width: 50px; text-align: center; }
        .vv-col-price, .vv-col-total { width: 90px; text-align: right; }

        .vv-item-product { display: flex; gap: 8px; align-items: flex-start; }
        .vv-item-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            overflow: hidden;
            background: #f4f2ff;
            flex-shrink: 0;
            border: 1px solid #eee6ff;
        }
        .vv-item-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .vv-item-text-name {
            font-size: 11.5px;
            font-weight: 600;
            margin-bottom: 3px;
            color: var(--vv-text-main);
        }
        .vv-item-text-meta { font-size: 10px; color: var(--vv-text-muted); }
        .vv-item-text-meta span + span::before { content: "•"; margin: 0 4px; color: #c0b7e0; }

        .vv-summary-row { display: flex; justify-content: flex-end; margin-top: 10px; }
        table.vv-summary-table { border-collapse: collapse; font-size: 11px; width: 260px; }
        table.vv-summary-table td { padding: 4px 6px; }
        table.vv-summary-table td:first-child { text-align: left; }
        table.vv-summary-table td:last-child { text-align: right; }
        .vv-summary-table .vv-total-row td {
            border-top: 1px solid #ddd;
            padding-top: 6px;
            font-weight: 700;
            font-size: 12px;
        }

        .vv-footer-note {
            margin-top: 18px;
            font-size: 10px;
            color: var(--vv-text-muted);
            text-align: center;
        }

        @media print {
            body { background: #ffffff; }
            .vv-page { padding: 0; }
            .vv-receipt-wrap { box-shadow: none; border-radius: 0; }
            <?php if (!$forPdf): ?>
            .vv-topbar, header.vv-header { display: none !important; }
            <?php endif; ?>
        }

        <?php if (!$forPdf): ?>
        @media (max-width: 767.98px){
            .vv-header-row { flex-direction: column; align-items: flex-start; }
            .vv-invoice-meta { text-align: left; }
            .vv-two-cols { flex-direction: column; }
        }
        <?php endif; ?>
    </style>
</head>
<body>

<?php if (!$forPdf): ?>
<header class="vv-header">
    <div class="vv-container vv-nav-wrapper">
        <!-- mobile toggle -->
        <button type="button" class="vv-nav-toggle" id="vvNavToggle" aria-expanded="false" aria-controls="vvMobileNav">
            <i class="bx bx-menu" style="font-size:1.3rem;"></i>
        </button>

        <a href="index.php" class="vv-logo" style="text-decoration:none;color:inherit;">
            <span class="vv-logo-main">Velvet</span>
            <span class="vv-logo-sub">Vogue</span>
        </a>

        <nav class="vv-nav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="index.php#about">About</a>
        </nav>

        <!-- mobile nav -->
        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="customer-orders.php">My orders</a>
            <a href="wishlist.php">Wishlist</a>
            <a href="customer-profile.php">My profile</a>
        </div>

        <div class="vv-nav-actions">
            <a href="wishlist.php" class="vv-icon-btn" aria-label="Wishlist">
                <i class="bx bx-heart"></i>
                <span class="vv-count-badge" id="wishlistCount"><?php echo (int)$wishlist_count; ?></span>
            </a>

            <a href="cart.php" class="vv-icon-btn" aria-label="Cart">
                <i class="bx bx-shopping-bag"></i>
                <span class="vv-count-badge" id="cartCount"><?php echo (int)$cart_items; ?></span>
            </a>

            <?php if ($customer_id): ?>
                <div class="vv-user-menu">
                    <button type="button" class="vv-pill-link vv-user-toggle">
                        <i class="bx bx-user-circle"></i>
                        <span><?php echo htmlspecialchars($customer_first); ?></span>
                        <i class="bx bx-chevron-down vv-user-caret"></i>
                    </button>
                    <div class="vv-user-dropdown">
                        <a href="customer-profile.php">
                            <i class="bx bx-id-card" style="font-size:1rem;margin-right:0.25rem;"></i>
                            My profile
                        </a>
                        <a href="customer-orders.php">
                            <i class="bx bx-package" style="font-size:1rem;margin-right:0.25rem;"></i>
                            My orders
                        </a>
                        <div class="vv-user-dropdown-separator"></div>
                        <a href="customer-logout.php">
                            <i class="bx bx-log-out" style="font-size:1rem;margin-right:0.25rem;"></i>
                            Log out
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <a href="customer-login.php" class="vv-pill-link">
                    <i class="bx bx-user"></i> Sign in / Register
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>
<?php endif; ?>

<div class="vv-page">

    <?php if (!$forPdf): ?>
    <div class="vv-topbar">
        <div class="vv-topbar-left">
            <a href="customer-orders.php" class="vv-btn">
                <i class="bx bx-arrow-back"></i> Back to My Orders
            </a>
        </div>
        <div class="vv-topbar-actions">
            <a href="order-receipt.php?order_id=<?php echo (int)$order['id']; ?>&download=1" class="vv-btn vv-btn-primary">
                <i class="bx bxs-download"></i> Download PDF
            </a>
            <button type="button" class="vv-btn" onclick="window.print();">
                <i class="bx bx-printer"></i> Print
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div class="vv-receipt-wrap">
        <div class="vv-header-row">
            <div>
                <div class="vv-receipt-logo">
                    <span class="vv-receipt-logo-main">Velvet</span>
                    <span class="vv-receipt-logo-sub">Vogue</span>
                </div>
                <div class="vv-company-meta">
                    <div><?php echo $companyAddress; ?></div>
                    <div>Email: <?php echo htmlspecialchars($companyEmail); ?></div>
                    <div>Phone: <?php echo htmlspecialchars($companyPhone); ?></div>
                </div>
            </div>
            <div class="vv-invoice-meta">
                <div class="vv-invoice-title">Order Receipt</div>
                <div><strong>Order #:</strong> <?php echo $orderNumber; ?></div>
                <?php if ($orderDateTime): ?>
                    <div><strong>Date &amp; time:</strong> <?php echo htmlspecialchars($orderDateTime); ?></div>
                <?php endif; ?>
                <?php if ($paymentMethod): ?>
                    <div><strong>Payment:</strong> <?php echo htmlspecialchars($paymentMethod); ?></div>
                <?php endif; ?>
                <div>
                    <strong>Status:</strong>
                    <span class="vv-badge-status"><?php echo htmlspecialchars($statusLabel); ?></span>
                </div>
            </div>
        </div>

        <div class="vv-two-cols">
            <div>
                <div class="vv-section-title">Customer details</div>
                <div class="vv-box">
                    <div class="vv-box-row"><span class="vv-box-label">Name:</span> <span><?php echo $customerName; ?></span></div>
                    <?php if ($customerEmail): ?>
                        <div class="vv-box-row"><span class="vv-box-label">Email:</span> <span><?php echo $customerEmail; ?></span></div>
                    <?php endif; ?>
                    <?php if ($customerPhone): ?>
                        <div class="vv-box-row"><span class="vv-box-label">Phone:</span> <span><?php echo $customerPhone; ?></span></div>
                    <?php endif; ?>
                    <div class="vv-box-row"><span class="vv-box-label">Order #:</span> <span><?php echo $orderNumber; ?></span></div>
                </div>
            </div>

            <div>
                <div class="vv-section-title">Shipping address</div>
                <div class="vv-box">
                    <div class="vv-box-row"><?php echo $shippingAddr; ?></div>
                </div>
            </div>
        </div>

        <div class="vv-items-title">Order items</div>
        <table class="vv-items-table">
            <thead>
            <tr>
                <th class="vv-col-num">#</th>
                <th>Product</th>
                <th class="vv-col-qty">Qty</th>
                <th class="vv-col-price">Unit price</th>
                <th class="vv-col-total">Line total</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="5">No items found for this order.</td></tr>
            <?php else: ?>
                <?php
                $i = 1;
                foreach ($items as $it):
                    $pname  = $it['product_name'] ?: ('Product #' . (int)$it['product_id']);
                    $qty    = (int)$it['quantity'];
                    $unit   = (float)$it['unit_price'];
                    $line   = (float)$it['line_total'];
                    $size   = trim($it['size'] ?? '');
                    $color  = trim($it['color'] ?? '');
                    $sku    = trim($it['sku'] ?? '');
                    $imgRel = trim($it['image_url'] ?? '');

                    $imageFull = $imgRel ? to_abs_url($baseUrl, $imgRel) : '';
                    $safeAlt   = htmlspecialchars($pname);
                ?>
                <tr>
                    <td class="vv-col-num"><?php echo $i++; ?></td>
                    <td>
                        <div class="vv-item-product">
                            <div class="vv-item-thumb">
                                <?php
                                  // For PDF, always use a valid src (placeholder if missing).
                                  // For browser, we still use placeholder fallback via onerror.
                                  $imgSrc = $imageFull ?: $placeholderImg;
                                ?>
                                <img
                                    src="<?php echo htmlspecialchars($imgSrc); ?>"
                                    alt="<?php echo $safeAlt; ?>"
                                    <?php if (!$forPdf): ?>
                                        onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($placeholderImg); ?>';"
                                    <?php endif; ?>
                                >
                            </div>
                            <div>
                                <div class="vv-item-text-name"><?php echo $safeAlt; ?></div>
                                <div class="vv-item-text-meta">
                                    <?php if ($sku): ?><span>SKU: <?php echo htmlspecialchars($sku); ?></span><?php endif; ?>
                                    <?php if ($color): ?><span>Color: <?php echo htmlspecialchars($color); ?></span><?php endif; ?>
                                    <?php if ($size): ?><span>Size: <?php echo htmlspecialchars($size); ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="vv-col-qty"><?php echo $qty; ?></td>
                    <td class="vv-col-price"><?php echo money_fmt($unit); ?></td>
                    <td class="vv-col-total"><?php echo money_fmt($line); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="vv-summary-row">
            <table class="vv-summary-table">
                <tr><td>Subtotal</td><td><?php echo money_fmt($subtotal); ?></td></tr>
                <tr><td>Discount</td><td>- <?php echo money_fmt($discount); ?></td></tr>
                <tr><td>Shipping</td><td><?php echo money_fmt($shipping); ?></td></tr>
                <tr><td>Tax</td><td><?php echo money_fmt($tax); ?></td></tr>
                <tr class="vv-total-row"><td>Total</td><td><?php echo money_fmt($grand); ?></td></tr>
            </table>
        </div>

        <div class="vv-footer-note">
            Thank you for shopping with <?php echo htmlspecialchars($companyName); ?>.
            If you have any questions about this receipt, please contact our support team
            and include your order number <strong><?php echo $orderNumber; ?></strong>.
        </div>
    </div>
</div>

<?php if (!$forPdf): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Mobile nav toggle
    const navToggle = document.getElementById('vvNavToggle');
    const mobileNav = document.getElementById('vvMobileNav');
    if (navToggle && mobileNav) {
        navToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = document.body.classList.toggle('vv-nav-open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', () => {
            document.body.classList.remove('vv-nav-open');
            navToggle.setAttribute('aria-expanded', 'false');
        });
        mobileNav.addEventListener('click', (e) => e.stopPropagation());
    }

    // Account dropdown
    const menu   = document.querySelector('.vv-user-menu');
    const toggle = document.querySelector('.vv-user-toggle');
    if (menu && toggle) {
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            menu.classList.toggle('open');
        });
        document.addEventListener('click', function () {
            menu.classList.remove('open');
        });
    }
});
</script>
<?php endif; ?>

</body>
</html>
    <?php
    return ob_get_clean();
}

// ---------- Decide: view (HTML) or download (PDF) ----------
$download = isset($_GET['download']) && $_GET['download'] == '1';
$baseUrl  = get_base_url();

if ($download) {
    $htmlForPdf = build_receipt_html($order, $items, $baseUrl, true);

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans'); // safer fallback for PDF rendering
    $dompdf = new Dompdf($options);

    $dompdf->loadHtml($htmlForPdf);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $fileName = 'order-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($order['order_number'] ?? $order_id)) . '.pdf';
    $dompdf->stream($fileName, ['Attachment' => true]);
    exit;
}

// Browser view
echo build_receipt_html($order, $items, $baseUrl, false);
