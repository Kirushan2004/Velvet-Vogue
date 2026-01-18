<?php
// cart.php – Shopping cart page + AJAX endpoint (Modern + responsive + mobile menu + image fallback)
// ✅ Header cart badge shows DISTINCT product lines (not total quantity)

session_start();
require_once 'db.php';

$isCustomerLoggedIn = !empty($_SESSION['customer_id']);
$customerName       = $_SESSION['customer_name'] ?? '';
$firstName          = $customerName ? (explode(' ', trim($customerName))[0] ?? 'Account') : 'Account';

/* -------------------------------------------------
   AJAX ADD-TO-CART HANDLER (fetch from index/shop/wishlist)
   ✅ Returns DISTINCT cart line count for header badge
   ------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json');

    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $qty        = isset($_POST['qty']) ? (int)$_POST['qty'] : 1;
    $color      = trim($_POST['color'] ?? '');
    $size       = trim($_POST['size'] ?? '');

    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product id']);
        exit;
    }
    if ($qty <= 0) $qty = 1;

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    // composite key: product|color|size
    $cartKey = $product_id . '|' . $color . '|' . $size;

    // if legacy value was just an int, convert it
    if (isset($_SESSION['cart'][$cartKey]) && !is_array($_SESSION['cart'][$cartKey])) {
        $_SESSION['cart'][$cartKey] = [
            'product_id' => $product_id,
            'color'      => $color,
            'size'       => $size,
            'qty'        => (int)$_SESSION['cart'][$cartKey],
        ];
    }

    if (isset($_SESSION['cart'][$cartKey]) && is_array($_SESSION['cart'][$cartKey])) {
        $_SESSION['cart'][$cartKey]['qty'] += $qty;
    } else {
        $_SESSION['cart'][$cartKey] = [
            'product_id' => $product_id,
            'color'      => $color,
            'size'       => $size,
            'qty'        => $qty,
        ];
    }

    // ✅ DISTINCT product lines count (product|color|size)
    $distinct_products = count($_SESSION['cart']);

    echo json_encode([
        'success'     => true,
        // keep the same key name your JS already uses
        'total_items' => $distinct_products,
    ]);
    exit;
}

// ------------- helpers -------------
function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}

// wishlist count for header
$wishlist_ids   = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist']) ? $_SESSION['wishlist'] : [];
$wishlist_count = count($wishlist_ids);

// ensure cart array exists
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
$cart = $_SESSION['cart'];

// ✅ Header badge should show DISTINCT products count
$cart_items = count($cart);

/* -------------------------------------------------
   NORMAL CART ACTIONS (non-ajax): add/update/remove
   ------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // if we get here, it's NOT the ajax=1 branch
    $action     = $_POST['action'] ?? '';
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $cart_key   = $_POST['cart_key'] ?? '';

    // ADD from product-details.php (with color/size)
    if ($action === 'add' && $product_id > 0) {
        $qty   = max(1, (int)($_POST['qty'] ?? 1));
        $color = trim($_POST['color'] ?? '');
        $size  = trim($_POST['size'] ?? '');

        $cartKey = $product_id . '|' . $color . '|' . $size;

        if (!isset($cart[$cartKey])) {
            $cart[$cartKey] = [
                'product_id' => $product_id,
                'color'      => $color,
                'size'       => $size,
                'qty'        => $qty,
            ];
        } else {
            // if legacy int, convert
            if (!is_array($cart[$cartKey])) {
                $cart[$cartKey] = [
                    'product_id' => $product_id,
                    'color'      => $color,
                    'size'       => $size,
                    'qty'        => (int)$cart[$cartKey] + $qty,
                ];
            } else {
                $cart[$cartKey]['qty'] += $qty;
            }
        }

        $_SESSION['cart'] = $cart;
        header('Location: cart.php');
        exit;
    }

    // UPDATE QTY / REMOVE – uses cart_key
    if (!empty($cart)) {
        if ($action === 'update_qty' && $cart_key !== '' && isset($cart[$cart_key])) {
            $newQty = max(1, (int)($_POST['qty'] ?? 1));
            if (is_array($cart[$cart_key])) {
                $cart[$cart_key]['qty'] = $newQty;
            } else {
                // legacy int
                $cart[$cart_key] = $newQty;
            }
        } elseif ($action === 'remove' && $cart_key !== '' && isset($cart[$cart_key])) {
            unset($cart[$cart_key]);
        }

        $_SESSION['cart'] = $cart;
    }

    header('Location: cart.php');
    exit;
}

/* -------------------------------------------------
   FETCH CART LINES (variant-aware)
   ------------------------------------------------- */
$products        = [];
$subtotal        = 0;
$total_qty_items = 0; // ✅ quantity sum (for display only)

if (!empty($cart)) {
    // collect unique product ids
    $ids = [];
    foreach ($cart as $key => $item) {
        if (is_array($item)) {
            $ids[] = (int)$item['product_id'];
        } else {
            // legacy: key might be product id
            $ids[] = (int)$key;
        }
    }
    $ids = array_unique(array_filter($ids));

    if (!empty($ids)) {
        $idList = implode(',', $ids);
        $sql = "
            SELECT id, name, sku, price, sale_price, on_sale,
                   image_url, collection
            FROM products
            WHERE id IN ($idList)
        ";

        $productMap = [];
        if ($res = $conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $productMap[(int)$row['id']] = $row;
            }
            $res->free();
        }

        foreach ($cart as $key => $item) {
            if (is_array($item)) {
                $pid   = (int)$item['product_id'];
                $qty   = (int)($item['qty'] ?? 0);
                $color = trim($item['color'] ?? '');
                $size  = trim($item['size'] ?? '');
            } else {
                // legacy: key is product id, value is qty
                $pid   = (int)$key;
                $qty   = (int)$item;
                $color = '';
                $size  = '';
            }

            if ($qty <= 0 || !isset($productMap[$pid])) continue;

            $row = $productMap[$pid];

            $unit = ((int)$row['on_sale'] === 1 && (float)$row['sale_price'] > 0)
                ? (float)$row['sale_price']
                : (float)$row['price'];

            $lineTotal = $unit * $qty;

            $row['qty']        = $qty;
            $row['unit_price'] = $unit;
            $row['line_total'] = $lineTotal;
            $row['color']      = $color;
            $row['size']       = $size;
            $row['cart_key']   = (string)$key;

            $products[] = $row;

            $subtotal        += $lineTotal;
            $total_qty_items += $qty;
        }
    }
}

// ✅ Recalculate header badge count after building (still distinct)
$cart_items = (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? count($_SESSION['cart']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <!-- IMPORTANT for mobile scaling -->
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Your cart | Velvet Vogue</title>

    <!-- Fonts & icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        /* ===== Primary button hover fix (text never disappears) ===== */
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
        .vv-btn-primary:active,
        .vv-btn.vv-btn-primary:active{
            transform: translateY(0);
            box-shadow: 0 6px 18px rgba(25,12,64,0.16) !important;
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
            z-index:60;
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

        /* ===== Page background ===== */
        .vv-cart-bg{
            background:
              radial-gradient(900px 420px at 10% -10%, rgba(156, 95, 255, 0.14), transparent 60%),
              radial-gradient(820px 380px at 90% 0%, rgba(255, 168, 229, 0.16), transparent 55%),
              linear-gradient(180deg, #ffffff 0%, #fbf8ff 45%, #ffffff 100%);
        }

        /* ===== Cart layout ===== */
        .vv-cart-main{ padding: 1.6rem 0 2.2rem; }
        .vv-cart-top{
            background: rgba(255,255,255,0.74);
            border: 1px solid rgba(232, 222, 255, 0.75);
            border-radius: 22px;
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.95rem 1rem;
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap: 1rem;
            flex-wrap:wrap;
            margin-bottom: 1rem;
        }
        .vv-cart-kicker{
            display:inline-flex;
            align-items:center;
            gap:0.45rem;
            padding:0.35rem 0.75rem;
            border-radius:999px;
            border:1px solid var(--vv-border-soft);
            background:#fff;
            color: var(--vv-text-soft);
            font-size:0.78rem;
        }
        .vv-cart-kicker i{ color: var(--vv-accent); font-size: 1rem; }
        .vv-cart-top h1{
            margin: 0.55rem 0 0.25rem;
            font-size: 1.55rem;
            line-height: 1.1;
        }
        .vv-cart-sub{
            margin:0;
            color: var(--vv-text-muted);
            font-size: 0.88rem;
        }

        .vv-cart-layout{
            display:grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 340px);
            gap: 1.2rem;
            align-items:start;
        }
        @media (max-width: 991.98px){
            .vv-cart-layout{ grid-template-columns: 1fr; }
        }

        /* ===== Item cards ===== */
        .vv-cart-item-card{
            background:#fff;
            border-radius:22px;
            border:1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.9rem 0.95rem;
            display:flex;
            gap:0.85rem;
            margin-bottom: 0.85rem;
            align-items:flex-start;
        }

        /* ✅ Image not too big */
        .vv-cart-thumb{
            width: clamp(92px, 18vw, 120px);
            aspect-ratio: 4 / 5;
            border-radius: 18px;
            overflow:hidden;
            background:#f4f3fb;
            flex: 0 0 auto;
        }
        .vv-cart-thumb img{
            width:100%;
            height:100%;
            object-fit:cover;
        }
        .vv-cart-thumb img.vv-img-fallback{
            object-fit:contain !important;
            background:#f4f3fb;
        }

        .vv-cart-body{
            flex:1;
            display:flex;
            flex-direction:column;
            gap:0.55rem;
            min-width: 0;
        }

        .vv-cart-name{
            font-size: 0.98rem;
            margin:0;
            line-height:1.2;
        }
        .vv-cart-meta{
            font-size:0.78rem;
            color: var(--vv-text-soft);
            margin: 0.15rem 0 0;
        }

        .vv-cart-price-row{
            display:flex;
            align-items:baseline;
            gap:0.4rem;
            margin-top: 0.25rem;
        }
        .vv-cart-line{
            font-size:0.82rem;
            color: var(--vv-text-muted);
            margin-top: 0.15rem;
        }

        /* Qty stepper */
        .vv-cart-qty-row{
            display:flex;
            align-items:center;
            gap:0.55rem;
            margin-top: 0.35rem;
            flex-wrap:wrap;
        }
        .vv-cart-qty-label{
            font-size:0.8rem;
            font-weight:500;
            color: var(--vv-text-main);
        }
        .vv-cart-qty-controls{
            display:inline-flex;
            align-items:center;
            gap:0.3rem;
        }
        .vv-cart-qty-btn{
            width:28px;
            height:28px;
            border-radius:999px;
            border:1px solid var(--vv-border-soft);
            background:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:1rem;
            cursor:pointer;
            padding:0;
            transition: 120ms ease;
        }
        .vv-cart-qty-btn:hover{ background:#f7f0fa; }
        .vv-cart-qty-value{
            min-width: 40px;
            text-align:center;
            padding: 0.2rem 0.55rem;
            border-radius:999px;
            background:#f5f0ff;
            border:1px solid var(--vv-border-soft);
            font-size:0.78rem;
        }

        .vv-cart-actions{
            display:flex;
            gap:0.55rem;
            flex-wrap:wrap;
            margin-top: 0.2rem;
        }
        .vv-btn-xs{
            padding: 0.28rem 0.78rem;
            font-size: 0.76rem;
            border-radius: 999px;
            line-height: 1.2;
        }

        /* ===== Summary ===== */
        .vv-cart-summary-box{
            background:#fff;
            border-radius: 18px;
            border:1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.95rem 1rem 1rem;
            position: sticky;
            top: 1rem;
        }
        @media (max-width: 991.98px){
            .vv-cart-summary-box{ position: static; }
        }

        .vv-cart-summary-row{
            display:flex;
            justify-content:space-between;
            margin-bottom: 0.45rem;
            font-size: 0.9rem;
        }
        .vv-cart-summary-total{
            font-weight: 600;
            font-size: 1.02rem;
        }
        .vv-cart-note{
            font-size:0.78rem;
            color: var(--vv-text-soft);
            margin: 0.35rem 0 0;
            line-height: 1.45;
        }

        /* Empty state */
        .vv-empty{
            margin-top: 1rem;
            background:#fff;
            border-radius: 22px;
            border:1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 1.1rem 1.1rem;
            display:flex;
            gap: 0.9rem;
            align-items:flex-start;
        }
        .vv-empty-icon{
            width: 44px;
            height: 44px;
            border-radius: 999px;
            background: var(--vv-accent-soft);
            color: var(--vv-accent);
            display:flex;
            align-items:center;
            justify-content:center;
            font-size: 1.35rem;
            flex: 0 0 auto;
        }
        .vv-empty h3{ margin: 0 0 0.2rem; font-size: 1rem; }
        .vv-empty p{ margin:0; color: var(--vv-text-muted); font-size: 0.88rem; line-height: 1.55; }
        .vv-empty-actions{ margin-top: 0.75rem; display:flex; gap: 0.6rem; flex-wrap:wrap; }
    </style>
</head>

<body class="vv-body vv-cart-bg">

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
            <a href="index.php#about">About</a>
        </nav>

        <!-- Mobile nav -->
        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="index.php#about">About</a>
        </div>

        <div class="vv-nav-actions">
            <a href="wishlist.php" class="vv-icon-btn" aria-label="Wishlist">
                <i class="bx bx-heart"></i>
                <span class="vv-count-badge" id="wishlistCount"><?php echo $wishlist_count; ?></span>
            </a>

            <a href="cart.php" class="vv-icon-btn" aria-label="Cart">
                <i class="bx bx-shopping-bag"></i>
                <!-- ✅ DISTINCT products count -->
                <span class="vv-count-badge" id="cartCount"><?php echo $cart_items; ?></span>
            </a>

            <?php if (!$isCustomerLoggedIn): ?>
                <a href="customer-login.php" class="vv-pill-link">
                    <i class="bx bx-user"></i> Sign in
                </a>
            <?php else: ?>
                <div class="vv-user-menu">
                    <button type="button" class="vv-pill-link vv-user-toggle">
                        <i class="bx bx-user-circle"></i>
                        <span><?php echo htmlspecialchars($firstName); ?></span>
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
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="vv-cart-main">
    <div class="vv-container">

        <div class="vv-cart-top">
            <div>
                <span class="vv-cart-kicker"><i class="bx bx-shopping-bag"></i> Cart</span>
                <h1>Your cart</h1>
                <p class="vv-cart-sub">
                    <?php echo (int)$cart_items; ?> product(s) · <?php echo (int)$total_qty_items; ?> item(s) · Subtotal <?php echo money_fmt($subtotal); ?>
                </p>
            </div>

            <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
                <a href="shop.php" class="vv-btn vv-btn-secondary">
                    Continue shopping <i class="bx bx-right-arrow-alt"></i>
                </a>
            </div>
        </div>

        <?php if (empty($products)): ?>
            <div class="vv-empty">
                <div class="vv-empty-icon"><i class="bx bx-cart"></i></div>
                <div>
                    <h3>Your cart is empty</h3>
                    <p>Browse the shop and add items to your cart. Your saved items stay here while you shop.</p>
                    <div class="vv-empty-actions">
                        <a href="shop.php" class="vv-btn vv-btn-primary">Go to shop</a>
                        <a href="shop.php?view=new" class="vv-btn vv-btn-secondary">New arrivals</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="vv-cart-layout">
                <!-- LEFT: items -->
                <section>
                    <?php foreach ($products as $p): ?>
                        <?php
                        $pid      = (int)$p['id'];
                        $qty      = (int)$p['qty'];
                        $unit     = (float)$p['unit_price'];
                        $line     = (float)$p['line_total'];
                        $cart_key = (string)$p['cart_key'];
                        ?>
                        <article class="vv-cart-item-card">
                            <div class="vv-cart-thumb">
                                <?php if (!empty($p['image_url'])): ?>
                                    <img
                                        src="<?php echo htmlspecialchars($p['image_url']); ?>"
                                        alt="<?php echo htmlspecialchars($p['name']); ?>"
                                        loading="lazy"
                                        data-fallback-text="Product image"
                                        onerror="vvImgFallback(this)"
                                    >
                                <?php else: ?>
                                    <img
                                        src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="
                                        alt="<?php echo htmlspecialchars($p['name']); ?>"
                                        data-fallback-text="Product image"
                                        onerror="vvImgFallback(this)"
                                    >
                                    <script>
                                      document.currentScript.previousElementSibling && vvImgFallback(document.currentScript.previousElementSibling);
                                    </script>
                                <?php endif; ?>
                            </div>

                            <div class="vv-cart-body">
                                <div>
                                    <h2 class="vv-cart-name"><?php echo htmlspecialchars($p['name']); ?></h2>

                                    <p class="vv-cart-meta">
                                        SKU: <?php echo htmlspecialchars($p['sku']); ?>
                                        <?php if (!empty($p['collection'])): ?>
                                            · <?php echo htmlspecialchars($p['collection']); ?> collection
                                        <?php endif; ?>
                                    </p>

                                    <?php if (!empty($p['color']) || !empty($p['size'])): ?>
                                        <p class="vv-cart-meta">
                                            <?php if (!empty($p['color'])): ?>
                                                Color: <?php echo htmlspecialchars($p['color']); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($p['color']) && !empty($p['size'])): ?> · <?php endif; ?>
                                            <?php if (!empty($p['size'])): ?>
                                                Size: <?php echo htmlspecialchars($p['size']); ?>
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>

                                    <div class="vv-cart-price-row">
                                        <span class="vv-price-main"><?php echo money_fmt($unit); ?></span>
                                        <?php if ((int)$p['on_sale'] === 1 && (float)$p['sale_price'] > 0): ?>
                                            <span class="vv-price-old"><?php echo money_fmt($p['price']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="vv-cart-line">Line total: <?php echo money_fmt($line); ?></div>

                                    <form method="post" class="vv-cart-qty-row">
                                        <input type="hidden" name="cart_key" value="<?php echo htmlspecialchars($cart_key); ?>">
                                        <span class="vv-cart-qty-label">Qty:</span>

                                        <div class="vv-cart-qty-controls">
                                            <button type="submit"
                                                    name="action"
                                                    value="update_qty"
                                                    class="vv-cart-qty-btn"
                                                    aria-label="Decrease quantity"
                                                    onclick="this.form.qty.value = Math.max(1, parseInt(this.form.qty.value || '1') - 1);">
                                                −
                                            </button>

                                            <span class="vv-cart-qty-value"><?php echo $qty; ?></span>

                                            <button type="submit"
                                                    name="action"
                                                    value="update_qty"
                                                    class="vv-cart-qty-btn"
                                                    aria-label="Increase quantity"
                                                    onclick="this.form.qty.value = parseInt(this.form.qty.value || '1') + 1;">
                                                +
                                            </button>

                                            <input type="hidden" name="qty" value="<?php echo $qty; ?>">
                                        </div>
                                    </form>
                                </div>

                                <div class="vv-cart-actions">
                                    <a href="product-details.php?id=<?php echo $pid; ?>" class="vv-btn vv-btn-outline vv-btn-xs">
                                        View details
                                    </a>

                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="cart_key" value="<?php echo htmlspecialchars($cart_key); ?>">
                                        <button type="submit" name="action" value="remove" class="vv-btn vv-btn-secondary vv-btn-xs">
                                            Remove
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>

                <!-- RIGHT: summary -->
                <aside>
                    <div class="vv-cart-summary-box">
                        <div class="vv-cart-summary-row">
                            <span>Products (distinct)</span>
                            <span><?php echo (int)$cart_items; ?></span>
                        </div>
                        <div class="vv-cart-summary-row">
                            <span>Total quantity</span>
                            <span><?php echo (int)$total_qty_items; ?></span>
                        </div>
                        <div class="vv-cart-summary-row vv-cart-summary-total">
                            <span>Subtotal</span>
                            <span><?php echo money_fmt($subtotal); ?></span>
                        </div>

                        <p class="vv-cart-note">Taxes and shipping are calculated at checkout.</p>

                        <a href="checkout.php" class="vv-btn vv-btn-primary" style="margin-top:0.75rem;width:100%;">
                            Proceed to checkout
                        </a>
                    </div>
                </aside>
            </div>
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
                <li><a href="#">Shipping &amp; returns</a></li>
                <li><a href="mailto:support@velvetvogue.test">Contact support</a></li>
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
  // -------- Mobile nav toggle --------
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

  // -------- User dropdown --------
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

