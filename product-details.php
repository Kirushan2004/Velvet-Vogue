<?php
// product-details.php – responsive product page + LEFT mobile menu + smaller image
// + image fallback + wishlist AJAX (HEART ICON INSIDE IMAGE)
// ✅ STOCK DISPLAY + OUT OF STOCK MESSAGE when trying to increase over stock (client-side)
// ✅ FLASH MESSAGES (server-side)

session_start();
require_once 'db.php';

$isCustomerLoggedIn = !empty($_SESSION['customer_id']);
$customerName       = $_SESSION['customer_name'] ?? '';
$firstName          = $customerName ? explode(' ', $customerName)[0] : 'Account';

// flash messages from cart.php
$cartError   = $_SESSION['cart_error'] ?? '';
$cartSuccess = $_SESSION['cart_success'] ?? '';
unset($_SESSION['cart_error'], $_SESSION['cart_success']);

// ------- helpers -------
function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}

// wishlist & cart counts for header
$wishlist_ids   = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])
    ? $_SESSION['wishlist']
    : [];
$wishlist_count = count($wishlist_ids);

// cart count (supports cart structure with variants)
$cart_items = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        if (is_array($item)) {
            $cart_items += (int)($item['qty'] ?? 0);
        } else {
            $cart_items += (int)$item;
        }
    }
}

// ------- load product -------
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($product_id <= 0) {
    http_response_code(404);
    echo "Invalid product.";
    exit;
}

$stmt = $conn->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $product_id);
$stmt->execute();
$res = $stmt->get_result();
$product = $res->fetch_assoc();
$stmt->close();

if (!$product) {
    http_response_code(404);
    echo "Product not found.";
    exit;
}

// ✅ stock info
$stock   = isset($product['stock']) ? (int)$product['stock'] : 0;
$inStock = $stock > 0;

// derive color/size options (comma separated strings)
$colorRaw = $product['colors'] ?? '';
$sizeRaw  = $product['sizes'] ?? '';

$colorOptions = array_filter(array_map('trim', explode(',', (string)$colorRaw)));
$sizeOptions  = array_filter(array_map('trim', explode(',', (string)$sizeRaw)));

// badges flags
$isOnSale = !empty($product['on_sale']) && (int)$product['on_sale'] === 1;
$isNew    = !empty($product['is_new']) && (int)$product['is_new'] === 1;
$isHot    = !empty($product['is_hot']) && (int)$product['is_hot'] === 1;

// choose display price
$basePrice = (float)$product['price'];
$salePrice = (float)($product['sale_price'] ?? 0);
$hasSale   = $isOnSale && $salePrice > 0;

// wishlist state for this product
$isLiked = in_array($product_id, $wishlist_ids, true);

// ------- load reviews -------
$reviews = [];
$avgRating = 0;
$totalRating = 0;

if ($stmt = $conn->prepare("
    SELECT customer_name, rating, comment, created_at
    FROM product_reviews
    WHERE product_id = ?
    ORDER BY created_at DESC
")) {
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $reviews[] = $row;
        $totalRating += (int)$row['rating'];
    }
    $stmt->close();

    if (count($reviews) > 0) {
        $avgRating = round($totalRating / count($reviews), 1);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- IMPORTANT for mobile -->
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?php echo htmlspecialchars($product['name']); ?> | Velvet Vogue</title>

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
          `<svg xmlns="http://www.w3.org/2000/svg" width="900" height="900" viewBox="0 0 900 900">
            <rect width="900" height="900" fill="#f4f3fb"/>
            <g opacity="0.9">
              <path d="M250 560l130-150 110 120 70-85 170 180H250z" fill="#d9d3ea"/>
              <circle cx="355" cy="345" r="38" fill="#d9d3ea"/>
            </g>
            <text x="50%" y="72%" text-anchor="middle" font-family="Poppins, Arial, sans-serif"
                  font-size="24" fill="#b7b0c9">` + safe.replace(/</g,'').replace(/>/g,'') + `</text>
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
        * { box-sizing: border-box; }
        img { max-width: 100%; height: auto; display: block; }

        /* =========================================================
           ✅ MOBILE MENU ON LEFT (PHONE + TABLET)
           ========================================================= */
        .vv-nav-wrapper { position: relative; }

        .vv-nav-toggle{
            display:none;
            border:1px solid var(--vv-border-soft);
            background:#fff;
            border-radius:12px;
            padding:0.45rem 0.6rem;
            cursor:pointer;
            line-height: 1;
        }
        .vv-mobile-nav{ display:none; }

        @media (max-width: 991.98px){
            .vv-nav { display:none; }

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
                right:auto;
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

        /* ===== Account dropdown ===== */
        .vv-account-menu { position: relative; }
        .vv-account-trigger { cursor: pointer; white-space: nowrap; }
        .vv-account-dropdown {
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
        .vv-account-menu.open .vv-account-dropdown { display: block; }
        .vv-account-dropdown a {
            display: flex;
            align-items:center;
            gap:0.45rem;
            padding: 0.45rem 0.9rem;
            font-size: 0.82rem;
            color: var(--vv-text-main);
            text-decoration: none;
            border-radius: 12px;
        }
        .vv-account-dropdown a:hover { background: #f7f0fa; color: var(--vv-accent); }

        /* =========================================================
           ✅ PRODUCT DETAILS LAYOUT
           ========================================================= */
        .vv-pd-main { padding: 1.8rem 0 2.4rem; }

        .vv-pd-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 1.4fr);
            gap: 1.4rem;
            align-items: stretch;
        }

        .vv-pd-gallery,
        .vv-pd-info {
            background: #fff;
            border-radius: 22px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
        }

        .vv-pd-gallery{
            padding: 0.9rem;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .vv-pd-image-wrap{
            flex: 1;
            border-radius: 18px;
            overflow: hidden;
            background: #f4f3fb;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.85rem;
            min-height: 260px;
            position: relative;
        }

        .vv-pd-image-wrap img{
            width: auto;
            height: auto;
            max-width: 100%;
            max-height: 340px;
            object-fit: contain;
        }
        .vv-pd-image-wrap img.vv-img-fallback{
            max-height: 320px;
        }

        .vv-pd-like-btn{
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            width: 42px;
            height: 42px;
            border-radius: 999px;
            border: 1px solid var(--vv-border-soft);
            background: rgba(255,255,255,0.92);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: var(--vv-shadow-subtle);
            z-index: 5;
        }
        .vv-pd-like-btn i{
            font-size: 1.25rem;
        }
        .vv-pd-like-btn.is-liked{
            border-color: var(--vv-accent);
            color: var(--vv-accent);
        }

        @media (max-width: 575.98px){
            .vv-pd-image-wrap{
                min-height: 220px;
                padding: 0.7rem;
            }
            .vv-pd-image-wrap img{
                max-height: 260px;
            }
            .vv-pd-like-btn{
                top: 0.6rem;
                right: 0.6rem;
                width: 40px;
                height: 40px;
            }
        }

        .vv-pd-info{
            padding: 1.1rem 1.2rem 1.2rem;
            height: 100%;
        }

        .vv-pd-title { font-size: 1.3rem; margin-bottom: 0.2rem; }
        .vv-pd-subline { font-size: 0.8rem; color: var(--vv-text-soft); margin-bottom: 0.5rem; }

        .vv-pd-rating-row {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            margin-bottom: 0.7rem;
            font-size: 0.8rem;
            color: var(--vv-text-soft);
        }
        .vv-pd-stars { color: #f6b042; font-size: 1rem; }

        .vv-pd-badges-row {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 0.3rem;
            margin-bottom: 0.7rem;
        }

        .vv-pd-price-row {
            display: flex;
            align-items: baseline;
            gap: 0.4rem;
            margin-bottom: 0.8rem;
        }

        .vv-pd-description {
            font-size: 0.86rem;
            color: var(--vv-text-muted);
            margin-bottom: 0.9rem;
        }

        .vv-pd-option-group { margin-bottom: 0.8rem; }
        .vv-pd-option-label {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--vv-text-soft);
            margin-bottom: 0.25rem;
        }
        .vv-pd-color-list, .vv-pd-size-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
        }
        .vv-pd-color-pill, .vv-pd-size-pill {
            border-radius: 999px;
            border: 1px solid var(--vv-border-strong);
            background: #fff;
            padding: 0.3rem 0.75rem;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .vv-pd-color-pill.active, .vv-pd-size-pill.active {
            background: var(--vv-accent);
            border-color: var(--vv-accent);
            color: #fff;
        }

        .vv-pd-actions-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.6rem;
            margin-top: 0.9rem;
        }
        .vv-pd-qty-input {
            width: 78px;
            border-radius: 999px;
            border: 1px solid var(--vv-border-soft);
            padding: 0.35rem 0.6rem;
            font-size: 0.82rem;
        }
        @media (max-width: 575.98px){
            .vv-pd-actions-row .vv-btn { width: 100%; justify-content:center; }
            .vv-pd-actions-row label { width: 100%; }
            .vv-pd-qty-input { width: 100%; }
        }

        .vv-flash{
            margin:0.6rem 0;
            padding:0.6rem 0.8rem;
            border-radius:12px;
            font-size:0.85rem;
            border:1px solid var(--vv-border-soft);
            background:#f7f0fa;
            color: var(--vv-text-main);
        }
        .vv-flash.error{
            border-color:#fda29b;
            background:#fffbfa;
            color:#b42318;
        }
        .vv-flash.success{
            border-color:#86efac;
            background:#f0fdf4;
            color:#166534;
        }

        /* reviews */
        .vv-pd-reviews { margin-top: 2rem; }
        .vv-pd-reviews-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 1rem;
            margin-bottom: 0.6rem;
            flex-wrap: wrap;
        }
        .vv-pd-reviews-header h3 { font-size: 1rem; }
        .vv-pd-review-list { list-style: none; padding-left: 0; margin: 0; }
        .vv-pd-review-item {
            background: #fff;
            border-radius: 18px;
            border: 1px solid var(--vv-border-soft);
            padding: 0.7rem 0.9rem;
            box-shadow: var(--vv-shadow-subtle);
            margin-bottom: 0.6rem;
            font-size: 0.82rem;
        }
        .vv-pd-review-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 0.25rem;
            flex-wrap: wrap;
        }
        .vv-pd-review-name { font-weight: 500; }
        .vv-pd-review-stars { color: #f6b042; font-size: 0.9rem; }
        .vv-pd-review-date { font-size: 0.7rem; color: var(--vv-text-soft); }

        @media (max-width: 991.98px) {
            .vv-pd-layout { grid-template-columns: minmax(0, 1fr); }
        }
    </style>
</head>
<body class="vv-body">

<header class="vv-header">
    <div class="vv-container vv-nav-wrapper">

        <button type="button" class="vv-nav-toggle" id="vvNavToggle"
                aria-expanded="false" aria-controls="vvMobileNav">
            <i class="bx bx-menu" style="font-size:1.3rem;"></i>
        </button>

        <a href="index.php" class="vv-logo" style="text-decoration:none;color:inherit;">
            <span class="vv-logo-main">Velvet</span>
            <span class="vv-logo-sub">Vogue</span>
        </a>

        <nav class="vv-nav">
            <a href="index.php">Home</a>
            <a href="shop.php" class="active">Shop</a>
            <a href="index.php#about">About</a>
        </nav>

        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php">Home</a>
            <a href="shop.php" class="active">Shop</a>
            <a href="index.php#about">About</a>
        </div>

        <div class="vv-nav-actions">
            <a href="wishlist.php" class="vv-icon-btn" aria-label="Wishlist">
                <i class="bx bx-heart"></i>
                <span class="vv-count-badge" id="wishlistCount"><?php echo $wishlist_count; ?></span>
            </a>

            <a href="cart.php" class="vv-icon-btn" aria-label="Cart">
                <i class="bx bx-shopping-bag"></i>
                <span class="vv-count-badge" id="cartCount"><?php echo $cart_items; ?></span>
            </a>

            <?php if (!$isCustomerLoggedIn): ?>
                <a href="customer-login.php" class="vv-pill-link">
                    <i class="bx bx-user"></i> Sign in
                </a>
            <?php else: ?>
                <div class="vv-account-menu">
                    <button type="button" class="vv-pill-link vv-account-trigger">
                        <i class="bx bx-user-circle"></i>
                        <span><?php echo htmlspecialchars($firstName); ?></span>
                        <i class="bx bx-chevron-down vv-account-caret"></i>
                    </button>
                    <div class="vv-account-dropdown">
                        <a href="customer-profile.php"><i class="bx bx-id-card"></i> My profile</a>
                        <a href="customer-orders.php"><i class="bx bx-package"></i> My orders</a>
                        <a href="customer-logout.php"><i class="bx bx-log-out"></i> Log out</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="vv-pd-main">
    <div class="vv-container">
        <div class="vv-pd-layout">

            <!-- LEFT: image -->
            <section class="vv-pd-gallery">
                <div class="vv-pd-image-wrap">

                    <!-- ✅ Wishlist heart INSIDE image -->
                    <button
                        type="button"
                        class="vv-pd-like-btn <?php echo $isLiked ? 'is-liked' : ''; ?>"
                        id="pdWishlistBtn"
                        data-product-id="<?php echo (int)$product_id; ?>"
                        aria-pressed="<?php echo $isLiked ? 'true' : 'false'; ?>"
                        aria-label="<?php echo $isLiked ? 'Remove from wishlist' : 'Add to wishlist'; ?>"
                        title="<?php echo $isLiked ? 'Remove from wishlist' : 'Add to wishlist'; ?>"
                    >
                        <i class="bx <?php echo $isLiked ? 'bxs-heart' : 'bx-heart'; ?>"></i>
                    </button>

                    <?php if (!empty($product['image_url'])): ?>
                        <img
                            src="<?php echo htmlspecialchars($product['image_url']); ?>"
                            alt="<?php echo htmlspecialchars($product['name']); ?>"
                            loading="lazy"
                            data-fallback-text="Product image"
                            onerror="vvImgFallback(this)"
                        >
                    <?php else: ?>
                        <img
                            src="data:image/svg+xml;charset=UTF-8,<?php echo rawurlencode('<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;900&quot; height=&quot;900&quot; viewBox=&quot;0 0 900 900&quot;><rect width=&quot;900&quot; height=&quot;900&quot; fill=&quot;#f4f3fb&quot;/><text x=&quot;50%&quot; y=&quot;55%&quot; text-anchor=&quot;middle&quot; font-family=&quot;Poppins, Arial, sans-serif&quot; font-size=&quot;24&quot; fill=&quot;#b7b0c9&quot;>No image</text></svg>'); ?>"
                            alt="No image"
                        >
                    <?php endif; ?>
                </div>
            </section>

            <!-- RIGHT: details -->
            <section class="vv-pd-info">
                <h1 class="vv-pd-title"><?php echo htmlspecialchars($product['name']); ?></h1>

                <p class="vv-pd-subline">
                    SKU: <?php echo htmlspecialchars($product['sku']); ?>
                    <?php if (!empty($product['collection'])): ?>
                        · <?php echo htmlspecialchars($product['collection']); ?> collection
                    <?php endif; ?>
                </p>

                <?php if (!empty($cartError)): ?>
                    <div class="vv-flash error"><?php echo htmlspecialchars($cartError); ?></div>
                <?php endif; ?>
                <?php if (!empty($cartSuccess)): ?>
                    <div class="vv-flash success"><?php echo htmlspecialchars($cartSuccess); ?></div>
                <?php endif; ?>

                <!-- rating -->
                <div class="vv-pd-rating-row">
                    <?php if ($avgRating > 0): ?>
                        <span class="vv-pd-stars">
                            <?php
                            $fullStars = floor($avgRating);
                            $halfStar  = ($avgRating - $fullStars) >= 0.5;
                            for ($i = 0; $i < $fullStars; $i++) echo '<i class="bx bxs-star"></i>';
                            if ($halfStar) echo '<i class="bx bxs-star-half"></i>';
                            for ($i = $fullStars + ($halfStar ? 1 : 0); $i < 5; $i++) echo '<i class="bx bx-star"></i>';
                            ?>
                        </span>
                        <span><?php echo $avgRating; ?>/5 · <?php echo count($reviews); ?> review(s)</span>
                    <?php else: ?>
                        <span>No reviews yet</span>
                    <?php endif; ?>
                </div>

                <!-- badges -->
                <div class="vv-pd-badges-row">
                    <?php if ($isOnSale): ?><span class="vv-badge vv-badge-sale">Sale</span><?php endif; ?>
                    <?php if ($isNew):    ?><span class="vv-badge vv-badge-new">New</span><?php endif; ?>
                    <?php if ($isHot):    ?><span class="vv-badge vv-badge-hot">Hot</span><?php endif; ?>
                </div>

                <!-- price -->
                <div class="vv-pd-price-row">
                    <?php if ($hasSale): ?>
                        <span class="vv-price-main"><?php echo money_fmt($salePrice); ?></span>
                        <span class="vv-price-old"><?php echo money_fmt($basePrice); ?></span>
                    <?php else: ?>
                        <span class="vv-price-main"><?php echo money_fmt($basePrice); ?></span>
                    <?php endif; ?>
                </div>

                <!-- ✅ stock -->
                <div style="margin-bottom:0.7rem;">
                    <span style="font-size:0.82rem;color:var(--vv-text-soft);">
                        Availability:
                        <strong>
                            <?php if ($inStock): ?>
                                In stock (<?php echo (int)$stock; ?>)
                            <?php else: ?>
                                Out of stock
                            <?php endif; ?>
                        </strong>
                    </span>
                    <div id="pdStockMsg" style="margin-top:0.35rem;font-size:0.82rem;color:#b42318;" aria-live="polite"></div>
                </div>

                <!-- description -->
                <div class="vv-pd-description">
                    <?php if (!empty($product['description'])): ?>
                        <?php echo nl2br(htmlspecialchars($product['description'])); ?>
                    <?php else: ?>
                        Elegant, versatile and designed to mix seamlessly with the rest of your wardrobe.
                    <?php endif; ?>
                </div>

                <!-- color -->
                <?php if (!empty($colorOptions)): ?>
                    <div class="vv-pd-option-group">
                        <div class="vv-pd-option-label">Color</div>
                        <div class="vv-pd-color-list">
                            <?php foreach ($colorOptions as $idx => $color): ?>
                                <button type="button"
                                        class="vv-pd-color-pill<?php echo $idx === 0 ? ' active' : ''; ?>"
                                        data-color="<?php echo htmlspecialchars($color); ?>">
                                    <?php echo htmlspecialchars($color); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- size -->
                <?php if (!empty($sizeOptions)): ?>
                    <div class="vv-pd-option-group">
                        <div class="vv-pd-option-label">Size</div>
                        <div class="vv-pd-size-list">
                            <?php foreach ($sizeOptions as $idx => $size): ?>
                                <button type="button"
                                        class="vv-pd-size-pill<?php echo $idx === 0 ? ' active' : ''; ?>"
                                        data-size="<?php echo htmlspecialchars($size); ?>">
                                    <?php echo htmlspecialchars($size); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- add to cart -->
                <form method="post" action="cart.php" class="vv-pd-actions-row" id="pdForm">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">

                    <input type="hidden" name="color" id="pdSelectedColor"
                           value="<?php echo !empty($colorOptions) ? htmlspecialchars(reset($colorOptions)) : ''; ?>">
                    <input type="hidden" name="size" id="pdSelectedSize"
                           value="<?php echo !empty($sizeOptions) ? htmlspecialchars(reset($sizeOptions)) : ''; ?>">

                    <!-- ✅ pass stock to JS -->
                    <input type="hidden" id="pdStock" value="<?php echo (int)$stock; ?>">

                    <label style="font-size:0.78rem;color:var(--vv-text-soft);">
                        Qty
                        <input
                            type="number"
                            id="pdQty"
                            name="qty"
                            min="1"
                            value="1"
                            class="vv-pd-qty-input"
                            <?php echo $inStock ? '' : 'disabled'; ?>
                        >
                    </label>

                    <button type="submit" class="vv-btn vv-btn-primary" id="pdAddToCartBtn" <?php echo $inStock ? '' : 'disabled'; ?>>
                        <i class="bx bx-shopping-bag"></i> Add to cart
                    </button>

                    <a href="shop.php" class="vv-btn vv-btn-outline">
                        Back to shop
                    </a>
                </form>
            </section>
        </div>

        <!-- reviews -->
        <section class="vv-pd-reviews">
            <div class="vv-pd-reviews-header">
                <h3>Customer reviews</h3>
                <?php if ($avgRating > 0): ?>
                    <div style="font-size:0.8rem;color:var(--vv-text-soft);">
                        Average rating: <?php echo $avgRating; ?>/5 (<?php echo count($reviews); ?> review(s))
                    </div>
                <?php endif; ?>
            </div>

            <?php if (empty($reviews)): ?>
                <p class="text-muted" style="font-size:0.85rem;">
                    This product doesn’t have any reviews yet.
                </p>
            <?php else: ?>
                <ul class="vv-pd-review-list">
                    <?php foreach ($reviews as $rev): ?>
                        <li class="vv-pd-review-item">
                            <div class="vv-pd-review-top">
                                <div>
                                    <span class="vv-pd-review-name"><?php echo htmlspecialchars($rev['customer_name']); ?></span>
                                    <span class="vv-pd-review-stars">
                                        <?php
                                        $r = (int)$rev['rating'];
                                        for ($i = 0; $i < $r; $i++) echo '<i class="bx bxs-star"></i>';
                                        for ($i = $r; $i < 5; $i++) echo '<i class="bx bx-star"></i>';
                                        ?>
                                    </span>
                                </div>
                                <span class="vv-pd-review-date">
                                    <?php
                                    $date = !empty($rev['created_at']) ? date('Y-m-d', strtotime($rev['created_at'])) : '';
                                    echo htmlspecialchars($date);
                                    ?>
                                </span>
                            </div>
                            <div><?php echo nl2br(htmlspecialchars($rev['comment'])); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
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
document.addEventListener('DOMContentLoaded', function () {
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

    // -------- Account dropdown --------
    const accountMenu = document.querySelector('.vv-account-menu');
    if (accountMenu) {
        const trigger = accountMenu.querySelector('.vv-account-trigger');
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            accountMenu.classList.toggle('open');
        });
        document.addEventListener('click', function () {
            accountMenu.classList.remove('open');
        });
    }

    // -------- Color/size selection -> update hidden inputs --------
    const colorButtons = document.querySelectorAll('.vv-pd-color-pill');
    const sizeButtons  = document.querySelectorAll('.vv-pd-size-pill');
    const colorInput   = document.getElementById('pdSelectedColor');
    const sizeInput    = document.getElementById('pdSelectedSize');

    if (colorButtons.length && colorInput) {
        colorButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                colorButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                colorInput.value = this.dataset.color || '';
            });
        });
    }

    if (sizeButtons.length && sizeInput) {
        sizeButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                sizeButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                sizeInput.value = this.dataset.size || '';
            });
        });
    }

    // -------- Wishlist AJAX (heart inside image) --------
    const wishlistBtn = document.getElementById('pdWishlistBtn');
    const wishlistCountEl = document.getElementById('wishlistCount');

    function formBody(obj) {
        return Object.keys(obj).map(k => encodeURIComponent(k) + '=' + encodeURIComponent(obj[k])).join('&');
    }

    async function postForm(url, data) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formBody(data),
            credentials: 'same-origin'
        });
        return res.json();
    }

    if (wishlistBtn) {
        wishlistBtn.addEventListener('click', async function () {
            const productId = this.dataset.productId;
            if (!productId) return;

            const isLiked = this.classList.contains('is-liked');
            const action  = isLiked ? 'remove' : 'add';

            this.disabled = true;

            try {
                const data = await postForm('wishlist.php', { product_id: productId, action });
                if (!data || !data.success) return;

                const icon = this.querySelector('i');

                if (data.in_wishlist) {
                    this.classList.add('is-liked');
                    this.setAttribute('aria-pressed', 'true');
                    this.setAttribute('aria-label', 'Remove from wishlist');
                    this.setAttribute('title', 'Remove from wishlist');
                    if (icon) { icon.classList.remove('bx-heart'); icon.classList.add('bxs-heart'); }
                } else {
                    this.classList.remove('is-liked');
                    this.setAttribute('aria-pressed', 'false');
                    this.setAttribute('aria-label', 'Add to wishlist');
                    this.setAttribute('title', 'Add to wishlist');
                    if (icon) { icon.classList.add('bx-heart'); icon.classList.remove('bxs-heart'); }
                }

                if (wishlistCountEl && typeof data.count !== 'undefined') {
                    wishlistCountEl.textContent = data.count;
                }
            } catch (err) {
                console.error(err);
            } finally {
                this.disabled = false;
            }
        });
    }

    // ✅ Stock / qty guard (client side)
    const stockEl = document.getElementById('pdStock');
    const qtyEl   = document.getElementById('pdQty');
    const msgEl   = document.getElementById('pdStockMsg');
    const addBtn  = document.getElementById('pdAddToCartBtn');
    const formEl  = document.getElementById('pdForm');

    const stock = stockEl ? parseInt(stockEl.value || '0', 10) : 0;

    function showMsg(text) {
      if (!msgEl) return;
      msgEl.textContent = text || '';
    }

    function normalizeQty() {
      let q = parseInt(qtyEl?.value || '1', 10);
      if (isNaN(q) || q < 1) q = 1;
      return q;
    }

    function clampIfNeeded(showLimitMsg) {
      if (!qtyEl || !addBtn) return;

      if (stock <= 0) {
        showMsg('Out of stock.');
        qtyEl.value = 1;
        qtyEl.disabled = true;
        addBtn.disabled = true;
        return;
      }

      let q = normalizeQty();

      if (q > stock) {
        qtyEl.value = String(stock);
        if (showLimitMsg) showMsg(`Out of stock for that quantity. Only ${stock} available.`);
      } else {
        qtyEl.value = String(q);
        showMsg('');
      }

      qtyEl.disabled = false;
      addBtn.disabled = false;
    }

    function showLimitIfAtMax() {
      if (!qtyEl) return;
      const q = normalizeQty();
      if (stock > 0 && q >= stock) {
        showMsg(`Out of stock for that quantity. Only ${stock} available.`);
      }
    }

    if (qtyEl) {
      // when value changes (typing/spinner changes)
      qtyEl.addEventListener('input', () => clampIfNeeded(true));
      qtyEl.addEventListener('change', () => clampIfNeeded(true));

      // detect attempt to increase when already at max
      qtyEl.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowUp') {
          const q = normalizeQty();
          if (stock > 0 && q >= stock) {
            e.preventDefault();
            showLimitIfAtMax();
          }
        }
      });

      // click/tap on spinner area (many browsers)
      qtyEl.addEventListener('click', () => showLimitIfAtMax());
      qtyEl.addEventListener('pointerup', () => showLimitIfAtMax());

      // mouse wheel over input (some setups)
      qtyEl.addEventListener('wheel', () => {
        // let the browser attempt happen, then clamp and show msg
        setTimeout(() => clampIfNeeded(true), 0);
      }, { passive: true });
    }

    if (formEl) {
      formEl.addEventListener('submit', function (e) {
        if (stock <= 0) {
          e.preventDefault();
          showMsg('Out of stock.');
          return;
        }
        const q = normalizeQty();
        if (q > stock) {
          e.preventDefault();
          qtyEl.value = String(stock);
          showMsg(`Out of stock for that quantity. Only ${stock} available.`);
        }
      });
    }

    // initial state
    clampIfNeeded(false);
});
</script>
</body>
</html>
