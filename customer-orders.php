<?php 
// customer-orders.php – Customer order history, search, review & complaint (Modern + responsive + mobile menu + image fallback)
session_start();
require_once 'db.php';

// ---------- Require login ----------
if (empty($_SESSION['customer_id'])) {
    $_SESSION['customer_login_notice'] = 'Please sign in to view your orders.';
    $redirect = 'customer-orders.php';
    header('Location: customer-login.php?redirect=' . urlencode($redirect));
    exit;
}

$customer_id   = (int)$_SESSION['customer_id'];
$customer_name = $_SESSION['customer_name'] ?? '';

// ---------- Helpers ----------
function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}
function clean_str($v) {
    return trim($v ?? '');
}

// ---------- Header counts ----------
$wishlist_ids   = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist']) ? $_SESSION['wishlist'] : [];
$wishlist_count = count($wishlist_ids);

// ✅ Cart badge should show number of products/lines, NOT total quantity
$cart_items = (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? count($_SESSION['cart']) : 0;

// ---------- Customer first name (header pill) ----------
$customer_first = 'Account';
if (!empty($customer_name)) {
    $parts = preg_split('/\s+/', trim($customer_name));
    $customer_first = $parts[0] ?? 'Account';
}

// ---------- Flash (existing) ----------
$flash_message = $_SESSION['customer_orders_flash'] ?? '';
unset($_SESSION['customer_orders_flash']);

// ✅ Flash (from checkout.php success message)
$orderSuccess = $_SESSION['order_success'] ?? '';
unset($_SESSION['order_success']);

// ----------------------------------------------------------
// GET: list orders with search + status filter
// ----------------------------------------------------------

// DB enum: 'pending','paid','shipped','completed','cancelled','refunded'
$allowedStatuses = ['all', 'pending', 'paid', 'shipped', 'completed', 'cancelled', 'refunded'];
$statusFilter    = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$searchTerm = clean_str($_GET['q'] ?? '');
$hasSearch  = ($searchTerm !== '');

$orders = [];

// dynamic query
$sql    = "SELECT * FROM orders WHERE customer_id = ?";
$types  = 'i';
$params = [$customer_id];

if ($statusFilter !== 'all') {
    $sql     .= " AND status = ?";
    $types   .= 's';
    $params[] = $statusFilter;
}

if ($hasSearch) {
    $sql .= " AND (order_number LIKE ? OR id IN (
                SELECT DISTINCT order_id FROM order_items WHERE product_name LIKE ?
            ))";
    $like     = '%' . $searchTerm . '%';
    $types   .= 'ss';
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('DB error.');
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $row['items'] = [];
    $orders[(int)$row['id']] = $row;
}
$stmt->close();

// Reviews map
$reviewsByProduct = [];

if (!empty($orders)) {
    $orderIds = array_keys($orders);
    $inList   = implode(',', array_map('intval', $orderIds));

    // Load order items + product data
    $sql = "
        SELECT
            oi.id,
            oi.order_id,
            oi.product_id,
            oi.product_name,
            oi.category_name,
            oi.gender,
            oi.size,
            oi.color,
            oi.quantity,
            oi.unit_price,
            oi.line_total,
            p.image_url,
            p.collection
        FROM order_items oi
        INNER JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id IN ($inList)
        ORDER BY oi.order_id ASC, oi.id ASC
    ";
    $res = $conn->query($sql);

    $productIds = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $oid = (int)$row['order_id'];
            if (!isset($orders[$oid])) continue;
            $orders[$oid]['items'][] = $row;
            $productIds[] = (int)$row['product_id'];
        }
        $res->free();
    }

    // Load existing reviews for these products by this customer (by name)
    if (!empty($productIds) && $customer_name !== '') {
        $productIds = array_unique($productIds);
        $inProducts = implode(',', array_map('intval', $productIds));
        $sql = "
            SELECT id, product_id, rating, comment, created_at
            FROM product_reviews
            WHERE product_id IN ($inProducts)
              AND customer_name = ?
        ";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $customer_name);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($rev = $res->fetch_assoc()) {
                $pid = (int)$rev['product_id'];
                $reviewsByProduct[$pid] = $rev;
            }
            $stmt->close();
        }
    }
}

function order_status_label($status) {
    switch (strtolower($status)) {
        case 'pending':   return 'Pending';
        case 'paid':      return 'Paid';
        case 'shipped':   return 'Shipped';
        case 'completed': return 'Completed';
        case 'cancelled': return 'Cancelled';
        case 'refunded':  return 'Refunded';
        case 'all':       return 'All';
        default:          return ucfirst((string)$status);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My orders | Velvet Vogue</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Boxicons -->
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <!-- Global CSS -->
    <link rel="stylesheet" href="css/style.css">

    <!-- Image fallback logic -->
    <script>
      function vvSvgPlaceholderDataUri(text) {
        const safe = String(text || 'Image unavailable').slice(0, 40);
        const svg =
          `<svg xmlns="http://www.w3.org/2000/svg" width="800" height="520" viewBox="0 0 800 520">
            <rect width="800" height="520" fill="#f4f3fb"/>
            <g opacity="0.9">
              <path d="M255 320l85-90 70 75 45-50 90 95H255z" fill="#d9d3ea"/>
              <circle cx="315" cy="210" r="28" fill="#d9d3ea"/>
            </g>
            <text x="50%" y="72%" text-anchor="middle" font-family="Poppins, Arial, sans-serif"
                  font-size="22" fill="#b7b0c9">` + safe.replace(/</g,'').replace(/>/g,'') + `</text>
          </svg>`;
        return "data:image/svg+xml;charset=UTF-8," + encodeURIComponent(svg);
      }
      function vvImgFallback(img) {
        if (!img || img.dataset.fallbackApplied === "1") return;
        img.dataset.fallbackApplied = "1";
        const label = img.getAttribute("data-fallback-text") || "Image unavailable";
        img.src = vvSvgPlaceholderDataUri(label);
        img.classList.add("vv-img-fallback");
      }
    </script>

    <style>
        *{ box-sizing:border-box; }
        img{ max-width:100%; height:auto; display:block; }

        /* ===== Primary hover fix (text never disappears) ===== */
        .vv-btn-primary,
        .vv-btn.vv-btn-primary{
            position:relative;
            overflow:hidden;
            color:#fff !important;
            text-decoration:none;
            transition: transform 160ms ease, box-shadow 160ms ease, background-color 160ms ease, border-color 160ms ease, color 160ms ease;
        }
        .vv-btn-primary:hover,
        .vv-btn.vv-btn-primary:hover,
        .vv-btn-primary:focus,
        .vv-btn.vv-btn-primary:focus{
            color:#fff !important;
            background-color: var(--vv-accent) !important;
            border-color: var(--vv-accent) !important;
            box-shadow: 0 10px 26px rgba(25,12,64,0.18) !important;
            transform: translateY(-1px);
        }

        /* ===== Modern background ===== */
        .vv-orders-bg{
            background:
              radial-gradient(900px 420px at 10% -10%, rgba(156, 95, 255, 0.14), transparent 60%),
              radial-gradient(820px 380px at 90% 0%, rgba(255, 168, 229, 0.16), transparent 55%),
              linear-gradient(180deg, #ffffff 0%, #fbf8ff 45%, #ffffff 100%);
        }

        /* ===== Header: mobile menu LEFT ===== */
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
                z-index:60;
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

        /* ===== User dropdown ===== */
        .vv-user-menu{ position:relative; }
        .vv-user-toggle{ cursor:pointer; white-space:nowrap; }
        .vv-user-dropdown{
            position:absolute;
            right:0;
            top:calc(100% + 0.35rem);
            min-width:180px;
            background:#fff;
            border-radius:14px;
            border:1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding:0.4rem 0.2rem;
            display:none;
            z-index:80;
        }
        .vv-user-menu.open .vv-user-dropdown{ display:block; }
        .vv-user-dropdown a{
            display:block;
            padding:0.45rem 0.9rem;
            font-size:0.82rem;
            color: var(--vv-text-main);
            text-decoration:none;
            border-radius:12px;
            margin: 0 0.2rem;
        }
        .vv-user-dropdown a:hover{ background:#f7f0fa; color: var(--vv-accent); }
        .vv-user-dropdown-separator{ height:1px; background:#eee4ff; margin:0.25rem 0.6rem; }

        /* ===== Page spacing ===== */
        .vv-orders-main{ padding: 1.6rem 0 2.2rem; }
        .vv-orders-header{ margin-bottom: 0.85rem; }
        .vv-orders-header h1{ margin-bottom: 0.2rem; }
        .vv-orders-summary{ font-size: 0.9rem; color: var(--vv-text-soft); }

        /* ✅ FIX GAP: use GRID instead of wrapped flex */
        .vv-orders-filters{
            display:grid !important;
            grid-template-columns: 1fr auto;
            gap: 0.75rem;
            align-items:center;
            justify-content:stretch;
            margin: 0.9rem 0 1rem;
            height:auto !important;
            min-height:0 !important;
            align-content:start !important;
        }
        @media (max-width: 767.98px){
            .vv-orders-filters{ grid-template-columns: 1fr; }
            .vv-orders-status-select{ justify-self:start; }
        }

        .vv-orders-search form{
            display:grid;
            grid-template-columns: 1fr auto;
            gap: 0.5rem;
            align-items:center;
        }
        .vv-orders-search-input{
            width:100%;
            border-radius:999px;
            border:1px solid var(--vv-border-strong);
            padding:0.52rem 0.95rem;
            font-size:0.86rem;
            background:#fff;
        }
        .vv-orders-search-btn{
            padding:0.52rem 0.95rem;
            border-radius:999px;
            border:1px solid var(--vv-border-soft);
            background:#fff;
            font-size:0.82rem;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:0.35rem;
            white-space:nowrap;
        }
        .vv-orders-search-btn:hover{ background:#f7f0fa; color: var(--vv-accent); }

        .vv-orders-status-select{
            font-size:0.82rem;
            color: var(--vv-text-soft);
            justify-self:end;
        }
        .vv-orders-status-select select{
            border-radius:999px;
            border:1px solid var(--vv-border-strong);
            padding:0.45rem 0.85rem;
            background:#fff;
            font-size:0.82rem;
        }

        /* Cards */
        .vv-order-card{
            background:#fff;
            border-radius:22px;
            border:1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.95rem 1.05rem 1.05rem;
            margin-bottom: 1rem;
        }
        .vv-order-header-row{
            display:flex;
            justify-content:space-between;
            gap: 0.9rem;
            align-items:flex-start;
            margin-bottom:0.65rem;
            flex-wrap:wrap;
        }
        .vv-order-id{ font-size:0.9rem; font-weight:600; }
        .vv-order-meta{ font-size:0.8rem; color: var(--vv-text-soft); margin-top:0.1rem; }

        .vv-order-status-badge{
            padding: 0.2rem 0.65rem;
            border-radius:999px;
            font-size:0.78rem;
            font-weight:600;
            display:inline-flex;
            align-items:center;
            gap:0.35rem;
        }
        .vv-order-status-badge span.dot{
            width:7px;height:7px;border-radius:999px;display:inline-block;
        }
        .vv-order-status-pending{ background:#fff7e0; color:#8d6b1f; }
        .vv-order-status-pending .dot{ background:#fbc02d; }
        .vv-order-status-paid{ background:#e3f2fd; color:#1e88e5; }
        .vv-order-status-paid .dot{ background:#1e88e5; }
        .vv-order-status-shipped{ background:#e0f7fa; color:#00796b; }
        .vv-order-status-shipped .dot{ background:#009688; }
        .vv-order-status-completed{ background:#e8f5e9; color:#2e7d32; }
        .vv-order-status-completed .dot{ background:#43a047; }
        .vv-order-status-cancelled{ background:#ffebee; color:#c62828; }
        .vv-order-status-cancelled .dot{ background:#e53935; }
        .vv-order-status-refunded{ background:#f3e5f5; color:#6a1b9a; }
        .vv-order-status-refunded .dot{ background:#8e24aa; }

        .vv-order-items{
            border-top: 1px dashed var(--vv-border-soft);
            padding-top: 0.7rem;
            margin-top: 0.5rem;
        }

        .vv-order-item-row{
            display:flex;
            gap:0.75rem;
            padding:0.55rem 0;
            align-items:flex-start;
            flex-wrap:wrap;
        }
        .vv-order-item-row + .vv-order-item-row{ border-top:1px solid #f1ecfb; }

        .vv-order-thumb{
            width:72px;
            aspect-ratio: 1 / 1;
            border-radius:14px;
            overflow:hidden;
            background:#f4f3fb;
            flex: 0 0 auto;
        }
        .vv-order-thumb img{
            width:100%;
            height:100%;
            object-fit:cover;
        }
        .vv-order-thumb img.vv-img-fallback{ object-fit:contain !important; }

        .vv-order-item-main{
            flex: 1 1 240px;
            min-width: 200px;
            font-size:0.84rem;
        }
        .vv-order-item-name a{
            font-size:0.92rem;
            font-weight:600;
            text-decoration:none;
            color: var(--vv-text-main);
        }
        .vv-order-item-name a:hover{ text-decoration:underline; }

        .vv-order-item-meta{
            color: var(--vv-text-soft);
            margin:0.18rem 0 0.2rem;
        }
        .vv-order-item-meta span + span::before{
            content:"·";
            margin:0 0.35rem;
            color:#c0b7d4;
        }

        .vv-order-item-price-row{
            display:flex;
            align-items:baseline;
            gap:0.35rem;
            margin-bottom:0.25rem;
        }
        .vv-order-item-line-total{ font-weight:600; }

        .vv-order-item-actions{
            margin-top:0.35rem;
            display:flex;
            gap:0.35rem;
            flex-wrap:wrap;
        }
        .vv-btn-xxs{
            padding:0.24rem 0.65rem;
            font-size:0.76rem;
            border-radius:999px;
            line-height:1.2;
        }

        .vv-order-item-right{
            margin-left:auto;
            text-align:right;
            font-size:0.82rem;
            min-width: 120px;
        }
        @media (max-width: 767.98px){
            .vv-order-item-right{ text-align:left; width:100%; margin-left:0; }
        }

        .vv-order-total-row{
            margin-top:0.55rem;
            display:flex;
            justify-content:space-between;
            font-size:0.86rem;
            color: var(--vv-text-soft);
        }
        .vv-order-total-row strong{ font-size:0.98rem; color: var(--vv-text-main); }

        /* ✅ Success alert style (existing) */
        .vv-alert{
            padding:0.55rem 0.85rem;
            border-radius:999px;
            font-size:0.82rem;
            margin-bottom: 0.9rem;
            display:inline-flex;
            align-items:center;
            gap:0.4rem;
        }
        .vv-alert-success{
            background:#e8f5e9;
            color:#2e7d32;
            border:1px solid #c8e6c9;
        }
    </style>
</head>

<body class="vv-body vv-orders-bg">

<header class="vv-header">
    <div class="vv-container vv-nav-wrapper">

        <!-- Mobile toggle LEFT -->
        <button type="button" class="vv-nav-toggle" id="vvNavToggle" aria-expanded="false" aria-controls="vvMobileNav">
            <i class="bx bx-menu" style="font-size:1.3rem;"></i>
        </button>

        <a href="index.php" class="vv-logo" style="text-decoration:none;color:inherit;">
            <span class="vv-logo-main">Velvet</span>
            <span class="vv-logo-sub">Vogue</span>
        </a>

        <!-- Desktop Nav -->
        <nav class="vv-nav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="wishlist.php">Wishlist</a>
            <a href="customer-orders.php" class="active">My orders</a>
        </nav>

        <!-- Mobile nav -->
        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="wishlist.php">Wishlist</a>
            <a href="customer-orders.php">My orders</a>
        </div>

        <div class="vv-nav-actions">
            <a href="wishlist.php" class="vv-icon-btn" aria-label="Wishlist">
                <i class="bx bx-heart"></i>
                <?php if ($wishlist_count > 0): ?>
                    <span class="vv-count-badge" id="wishlistCount"><?php echo $wishlist_count; ?></span>
                <?php endif; ?>
            </a>

            <a href="cart.php" class="vv-icon-btn" aria-label="Cart">
                <i class="bx bx-shopping-bag"></i>
                <?php if ($cart_items > 0): ?>
                    <span class="vv-count-badge" id="cartCount"><?php echo $cart_items; ?></span>
                <?php endif; ?>
            </a>

            <!-- User pill -->
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
        </div>

    </div>
</header>

<main class="vv-orders-main">
    <div class="vv-container">

        <div class="vv-orders-header">
            <h1>My orders</h1>
            <p class="vv-orders-summary">
                <?php echo count($orders); ?> order(s) found
                <?php if ($statusFilter !== 'all'): ?>
                    · Status: <?php echo htmlspecialchars(order_status_label($statusFilter)); ?>
                <?php endif; ?>
                <?php if ($hasSearch): ?>
                    · Search: “<?php echo htmlspecialchars($searchTerm); ?>”
                <?php endif; ?>
            </p>
        </div>

        <!-- ✅ NEW: payment success message from checkout.php -->
        <?php if (!empty($orderSuccess)): ?>
            <div class="vv-alert vv-alert-success">
                <i class="bx bx-check-circle"></i>
                <?php echo htmlspecialchars($orderSuccess); ?>
            </div>
        <?php endif; ?>

        <!-- Existing flash message (keep if you use it elsewhere) -->
        <?php if ($flash_message): ?>
            <div class="vv-alert vv-alert-success">
                <i class="bx bx-check-circle"></i>
                <?php echo htmlspecialchars($flash_message); ?>
            </div>
        <?php endif; ?>

        <!-- ✅ Filters (NO GAP) -->
        <div class="vv-orders-filters">
            <!-- Search -->
            <div class="vv-orders-search">
                <form method="get">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                    <input type="text"
                           name="q"
                           class="vv-orders-search-input"
                           placeholder="Search by order number or product name..."
                           value="<?php echo htmlspecialchars($searchTerm); ?>">
                    <button type="submit" class="vv-orders-search-btn">
                        <i class="bx bx-search"></i> Search
                    </button>
                </form>
            </div>

            <!-- Status -->
            <div class="vv-orders-status-select">
                <form method="get">
                    <input type="hidden" name="q" value="<?php echo htmlspecialchars($searchTerm); ?>">
                    <label>
                        Status:
                        <select name="status" onchange="this.form.submit()">
                            <?php foreach ($allowedStatuses as $st): ?>
                                <option value="<?php echo htmlspecialchars($st); ?>"
                                    <?php echo $statusFilter === $st ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(order_status_label($st)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            </div>
        </div>

        <?php if (empty($orders)): ?>
            <p class="text-muted">
                You haven't placed any orders yet. <a href="shop.php">Browse the shop</a> to get started.
            </p>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <?php
                $oid         = (int)$order['id'];
                $statusRaw   = strtolower($order['status'] ?? 'pending');
                $known       = ['pending','paid','shipped','completed','cancelled','refunded'];
                $status      = in_array($statusRaw, $known, true) ? $statusRaw : 'pending';
                $statusClass = 'vv-order-status-' . $status;

                $orderNumber   = $order['order_number'] ?? ('#' . $oid);
                $createdAt     = $order['created_at'] ?? '';
                $totalAmount   = $order['total_amount'] ?? 0;
                $paymentMethod = $order['payment_method'] ?? '';
                ?>
                <article class="vv-order-card" id="order-<?php echo $oid; ?>">
                    <div class="vv-order-header-row">
                        <div>
                            <div class="vv-order-id">Order <?php echo htmlspecialchars($orderNumber); ?></div>
                            <div class="vv-order-meta">
                                Placed on <?php echo htmlspecialchars($createdAt); ?>
                                <?php if ($paymentMethod): ?>
                                    · Payment method: <?php echo htmlspecialchars(strtoupper($paymentMethod)); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <div class="vv-order-status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                <span class="dot"></span>
                                <?php echo htmlspecialchars(order_status_label($status)); ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($order['items'])): ?>
                        <div class="vv-order-items">
                            <?php foreach ($order['items'] as $item): ?>
                                <?php
                                $itemId     = (int)$item['id'];
                                $productId  = (int)$item['product_id'];
                                $name       = $item['product_name'] ?: 'Product';
                                $qty        = (int)$item['quantity'];
                                $unit       = (float)$item['unit_price'];
                                $lineTotal  = (float)$item['line_total'];
                                $size       = $item['size'] ?? '';
                                $color      = $item['color'] ?? '';
                                $imageUrl   = $item['image_url'] ?? '';
                                $collection = $item['collection'] ?? '';

                                $existingReview = $reviewsByProduct[$productId] ?? null;
                                $canReviewCompl = ($status === 'completed');
                                ?>
                                <div class="vv-order-item-row">
                                    <div class="vv-order-thumb">
                                        <a href="product-details.php?id=<?php echo $productId; ?>">
                                            <?php if (!empty($imageUrl)): ?>
                                                <img
                                                    src="<?php echo htmlspecialchars($imageUrl); ?>"
                                                    alt="<?php echo htmlspecialchars($name); ?>"
                                                    loading="lazy"
                                                    data-fallback-text="Product image"
                                                    onerror="vvImgFallback(this)"
                                                >
                                            <?php else: ?>
                                                <img
                                                    src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="
                                                    alt="<?php echo htmlspecialchars($name); ?>"
                                                    data-fallback-text="Product image"
                                                    onerror="vvImgFallback(this)"
                                                >
                                                <script>
                                                  document.currentScript.previousElementSibling && vvImgFallback(document.currentScript.previousElementSibling);
                                                </script>
                                            <?php endif; ?>
                                        </a>
                                    </div>

                                    <div class="vv-order-item-main">
                                        <div class="vv-order-item-name">
                                            <a href="product-details.php?id=<?php echo $productId; ?>">
                                                <?php echo htmlspecialchars($name); ?>
                                            </a>
                                        </div>

                                        <div class="vv-order-item-meta">
                                            <span>Qty: <?php echo $qty; ?></span>
                                            <?php if ($color): ?><span>Color: <?php echo htmlspecialchars($color); ?></span><?php endif; ?>
                                            <?php if ($size): ?><span>Size: <?php echo htmlspecialchars($size); ?></span><?php endif; ?>
                                            <?php if ($collection): ?><span><?php echo htmlspecialchars($collection); ?> collection</span><?php endif; ?>
                                        </div>

                                        <div class="vv-order-item-price-row">
                                            <span class="vv-price-main"><?php echo money_fmt($unit); ?></span>
                                            <span class="vv-order-item-line-total">· Line total: <?php echo money_fmt($lineTotal); ?></span>
                                        </div>

                                        <div class="vv-order-item-actions">
                                            <?php if ($canReviewCompl): ?>
                                                <a href="add-review.php?order_id=<?php echo $oid; ?>&order_item_id=<?php echo $itemId; ?>&product_id=<?php echo $productId; ?>"
                                                   class="vv-btn vv-btn-primary vv-btn-xxs">
                                                    <?php echo $existingReview ? 'Edit review' : 'Add review'; ?>
                                                </a>
                                                <a href="add-complaint.php?order_id=<?php echo $oid; ?>&order_item_id=<?php echo $itemId; ?>&product_id=<?php echo $productId; ?>"
                                                   class="vv-btn vv-btn-secondary vv-btn-xxs">
                                                    Add complaint
                                                </a>
                                            <?php endif; ?>

                                            <a href="product-details.php?id=<?php echo $productId; ?>"
                                               class="vv-btn vv-btn-outline vv-btn-xxs">
                                                View product
                                            </a>
                                            <a href="order-receipt.php?order_id=<?php echo $oid; ?>"
                                               class="vv-btn vv-btn-xxs">
                                                View receipt
                                            </a>
                                            <a href="order-receipt.php?order_id=<?php echo $oid; ?>&download=1"
                                               class="vv-btn vv-btn-xxs">
                                                Download receipt
                                            </a>
                                        </div>
                                    </div>

                                    <div class="vv-order-item-right">
                                        <div>Qty: <?php echo $qty; ?></div>
                                        <div class="vv-order-item-line-total"><?php echo money_fmt($lineTotal); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="vv-order-total-row">
                                <span>Total for this order</span>
                                <strong><?php echo money_fmt($totalAmount); ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</main>

<footer class="vv-footer">
    <div class="vv-container vv-footer-grid">
        <div class="vv-footer-brand">
            <div class="vv-logo">
                <span class="vv-logo-main">Velvet</span>
                <span class="vv-logo-sub">Vogue</span>
            </div>
            <p class="vv-footer-copy">Curated pieces, considered details and a smoother online shopping experience.</p>
            <p class="vv-footer-copy-small">&copy; <?php echo date('Y'); ?> Velvet Vogue. All rights reserved.</p>
        </div>

        <div class="vv-footer-column">
            <h4>Shop</h4>
            <ul class="vv-footer-list">
                <li><a href="shop.php">All products</a></li>
                <li><a href="shop.php?view=new">New arrivals</a></li>
                <li><a href="shop.php?view=sale">On sale</a></li>
            </ul>
        </div>

        <div class="vv-footer-column">
            <h4>Support</h4>
            <ul class="vv-footer-list">
                <li><a href="contactsupport.php">Contact support</a></li>
                <li><a href="#">Shipping &amp; returns</a></li>
                <li><a href="#">Size guide</a></li>
            </ul>
            <div class="vv-footer-social">
                <a href="#"><i class="bx bxl-instagram"></i></a>
                <a href="#"><i class="bx bxl-facebook"></i></a>
                <a href="#"><i class="bx bxl-pinterest"></i></a>
            </div>
            <div class="vv-footer-legal">
                <a href="#">Privacy</a>
                <span>·</span>
                <a href="#">Terms</a>
            </div>
        </div>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
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

  // User dropdown
  const userMenu = document.querySelector('.vv-user-menu');
  if (userMenu) {
    const toggle = userMenu.querySelector('.vv-user-toggle');
    toggle.addEventListener('click', (e) => {
      e.stopPropagation();
      userMenu.classList.toggle('open');
    });
    document.addEventListener('click', () => userMenu.classList.remove('open'));
  }
});
</script>

</body>
</html>
