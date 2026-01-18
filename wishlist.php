<?php
// wishlist.php – AJAX API + wishlist page (Modern + responsive + mobile menu + image fallback)
session_start();
require_once 'db.php';

// ---------- ensure wishlist session array exists ----------
if (!isset($_SESSION['wishlist']) || !is_array($_SESSION['wishlist'])) {
    $_SESSION['wishlist'] = [];
}

/**
 * JSON response helper for AJAX
 */
function wishlist_json_response(array $data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Helper – format currency
 */
function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}

/* -------------------------------------------------
   1) AJAX HANDLER (POST) – add/remove wishlist item
   ------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $action    = $_POST['action'] ?? '';

    if ($productId <= 0 || !in_array($action, ['add', 'remove'], true)) {
        wishlist_json_response([
            'success' => false,
            'error'   => 'Invalid parameters.',
        ]);
    }

    // make sure wishlist is array of ints
    $wishlist = array_map('intval', $_SESSION['wishlist']);

    if ($action === 'add') {
        // optional: verify product exists
        $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();
        } else {
            $exists = false;
        }

        if (!$exists) {
            wishlist_json_response([
                'success' => false,
                'error'   => 'Product not found.',
            ]);
        }

        if (!in_array($productId, $wishlist, true)) {
            $wishlist[] = $productId;
        }
        $in_wishlist = true;
    } else { // remove
        $wishlist = array_values(array_filter(
            $wishlist,
            function ($id) use ($productId) {
                return (int)$id !== $productId;
            }
        ));
        $in_wishlist = false;
    }

    // save & respond
    $_SESSION['wishlist'] = $wishlist;
    $count = count($wishlist);

    wishlist_json_response([
        'success'     => true,
        'in_wishlist' => $in_wishlist,
        'count'       => $count,
    ]);
}

/* -------------------------------------------------
   2) PAGE RENDER (GET) – "My wishlist" UI
   ------------------------------------------------- */

// current wishlist IDs
$wishlist_ids = array_map('intval', $_SESSION['wishlist']);
$products     = [];

if ($wishlist_ids) {
    $placeholders = implode(',', array_fill(0, count($wishlist_ids), '?'));
    $types        = str_repeat('i', count($wishlist_ids));

    $sql = "
        SELECT id, name, sku, price, sale_price, on_sale,
               image_url, collection, is_new, is_hot, category_id
        FROM products
        WHERE id IN ($placeholders)
        ORDER BY id DESC
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$wishlist_ids);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $products[] = $row;
        }
        $stmt->close();
    }
}

// wishlist + cart counts for header
$wishlist_count = count($wishlist_ids);

$cart_items = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $pid => $qty) {
        $cart_items += (int)$qty;
    }
}

// current customer (for pill)
$customer_id     = $_SESSION['customer_id']     ?? null;
$customer_name   = $_SESSION['customer_name']   ?? '';
$customer_first  = '';
if ($customer_name !== '') {
    $parts = preg_split('/\s+/', trim($customer_name));
    $customer_first = $parts[0] ?? $customer_name;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <!-- IMPORTANT for mobile -->
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>My wishlist | Velvet Vogue</title>

    <!-- Fonts & icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <!-- Site CSS -->
    <link rel="stylesheet" href="css/style.css">

    <!-- Image fallback logic (broken image -> SVG placeholder) -->
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
        * { box-sizing: border-box; }
        img { max-width: 100%; height: auto; display: block; }
        .vv-nav-wrapper { position: relative; }

        /* ==========================
           PRIMARY BUTTON HOVER FIX
           (prevents text being hidden)
           ========================== */
        .vv-btn-primary,
        .vv-btn.vv-btn-primary {
            position: relative;
            overflow: hidden;
            color: #fff !important;
            text-decoration: none;
            transition: transform 160ms ease, box-shadow 160ms ease, background-color 160ms ease, border-color 160ms ease, color 160ms ease;
        }
        .vv-btn-primary:hover,
        .vv-btn.vv-btn-primary:hover,
        .vv-btn-primary:focus,
        .vv-btn.vv-btn-primary:focus {
            color: #fff !important;
            background-color: var(--vv-accent) !important;
            border-color: var(--vv-accent) !important;
            box-shadow: 0 10px 26px rgba(25, 12, 64, 0.18) !important;
            transform: translateY(-1px);
        }
        .vv-btn-primary:active,
        .vv-btn.vv-btn-primary:active {
            transform: translateY(0);
            box-shadow: 0 6px 18px rgba(25, 12, 64, 0.16) !important;
        }
        .vv-btn-primary i,
        .vv-btn.vv-btn-primary i {
            color: inherit !important;
        }

        /* ==========================
           MOBILE NAV (hamburger LEFT)
           ========================== */
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

            /* LEFT dropdown on mobile */
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

        /* ==========================
           USER DROPDOWN
           ========================== */
        .vv-user-menu { position: relative; }
        .vv-user-toggle { cursor: pointer; white-space: nowrap; }
        .vv-user-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 0.35rem);
            min-width: 180px;
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.4rem 0.2rem;
            display: none;
            z-index: 60;
        }
        .vv-user-menu.open .vv-user-dropdown { display: block; }
        .vv-user-dropdown a {
            display: block;
            padding: 0.45rem 0.9rem;
            font-size: 0.82rem;
            color: var(--vv-text-main);
            text-decoration: none;
            border-radius: 12px;
            margin: 0 0.2rem;
        }
        .vv-user-dropdown a:hover { background: #f7f0fa; color: var(--vv-accent); }
        .vv-user-dropdown-separator { height: 1px; background: #eee4ff; margin: 0.25rem 0.6rem; }

        /* ==========================
           PAGE LOOK (modern)
           ========================== */
        .vv-wishlist-bg{
            background:
              radial-gradient(900px 420px at 10% -10%, rgba(156, 95, 255, 0.14), transparent 60%),
              radial-gradient(820px 380px at 90% 0%, rgba(255, 168, 229, 0.16), transparent 55%),
              linear-gradient(180deg, #ffffff 0%, #fbf8ff 45%, #ffffff 100%);
        }

        .vv-wl-top{
            background: rgba(255,255,255,0.72);
            border: 1px solid rgba(232, 222, 255, 0.75);
            border-radius: 22px;
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.95rem 1rem;
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap: 1rem;
            flex-wrap:wrap;
        }
        .vv-wl-kicker{
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
        .vv-wl-kicker i{ color: var(--vv-accent); font-size: 1rem; }
        .vv-wl-title{
            margin: 0.55rem 0 0.25rem;
            font-size: 1.55rem;
            line-height: 1.1;
        }
        .vv-wl-sub{
            margin:0;
            color: var(--vv-text-muted);
            font-size: 0.88rem;
        }

        /* =========================================================
           ✅ FIX: Single item should NOT stretch huge
           ✅ FIX: Images smaller (height reduced a lot)
           ========================================================= */

        /* Desktop/tablet grid: cards capped to 340px max (industry style) */
        .vv-product-grid{
            display:grid !important;
            grid-template-columns: repeat(auto-fit, minmax(220px, 340px)); /* 👈 prevents 1 item becoming huge */
            justify-content: center; /* center when there are few items */
            gap:14px !important;
            align-items:stretch;
            margin-top: 1rem;
        }

        /* Mobile: 2 columns */
        @media (max-width: 575.98px){
            .vv-product-grid{
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                justify-content: stretch;
            }
        }

        /* Very small phones: 1 column */
        @media (max-width: 360px){
            .vv-product-grid{
                grid-template-columns: 1fr !important;
            }
        }

        .vv-product-card{
            width:100% !important;     /* fills its column */
            max-width: 340px !important; /* safety cap */
            height:100%;
            display:flex;
            flex-direction:column;
        }

        /* ✅ Smaller image height */
        .vv-product-media-inner{
            position:relative;
            width:100%;
            height: clamp(110px, 13vw, 150px);  /* 👈 smaller than before */
            overflow:hidden;
            border-radius:18px;
            background:#f4f3fb;
        }
        @media (max-width: 575.98px){
            .vv-product-media-inner{
                height: clamp(95px, 26vw, 130px); /* 👈 smaller on phones */
            }
        }

        .vv-product-media-inner img{
            width:100% !important;
            height:100% !important;
            object-fit:cover;
        }
        .vv-product-media-inner img.vv-img-fallback{
            object-fit:contain !important;
            background:#f4f3fb;
        }

        .vv-product-body{
            flex:1;
            display:flex;
            flex-direction:column;
            padding:12px 12px 14px !important;
        }
        .vv-product-name{
            margin:8px 0 6px !important;
            line-height:1.15;
            display:-webkit-box;
            -webkit-line-clamp:2;
            -webkit-box-orient:vertical;
            overflow:hidden;
            min-height:2.4em;
        }
        .vv-product-meta{ margin:0 0 10px !important; }

        .vv-product-price-row{
            margin-top:auto;
            padding-top:6px;
        }

        .vv-product-actions{
            margin-top:10px !important;
            display:block;
        }
        .vv-product-actions .vv-btn,
        .vv-product-actions .vv-btn-sm{
            width:100% !important;
            display:flex !important;
            justify-content:center !important;
            align-items:center !important;
            white-space:nowrap !important;
            border-radius:14px !important;
            padding:10px 12px !important;
        }

        /* heart stays top-right */
        .vv-product-like-btn{
            position:absolute !important;
            top:10px;
            right:10px;
            z-index:3;
        }

        /* Smooth remove animation */
        .vv-card-removing{
            opacity: 0;
            transform: translateY(6px);
            transition: 180ms ease;
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
        .vv-empty h3{
            margin: 0 0 0.2rem;
            font-size: 1rem;
        }
        .vv-empty p{
            margin:0;
            color: var(--vv-text-muted);
            font-size: 0.88rem;
            line-height: 1.55;
        }
        .vv-empty-actions{
            margin-top: 0.75rem;
            display:flex;
            gap: 0.6rem;
            flex-wrap:wrap;
        }
    </style>
</head>

<body class="vv-body vv-wishlist-bg">

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
                <span class="vv-count-badge" id="cartCount"><?php echo $cart_items; ?></span>
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

<main>
    <section class="vv-section">
        <div class="vv-container">

            <div class="vv-wl-top">
                <div>
                    <span class="vv-wl-kicker"><i class="bx bx-bookmark-heart"></i> Saved items</span>
                    <h2 class="vv-wl-title">My wishlist</h2>
                    <p class="vv-wl-sub">
                        <span id="wishlistCountText"><?php echo $wishlist_count; ?> item(s) saved</span>
                    </p>
                </div>

                <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
                    <a href="shop.php" class="vv-btn vv-btn-secondary">
                        Continue shopping <i class="bx bx-right-arrow-alt"></i>
                    </a>
                </div>
            </div>

            <?php if (empty($products)): ?>
                <div class="vv-empty" id="emptyWishlistBlock">
                    <div class="vv-empty-icon"><i class="bx bx-heart"></i></div>
                    <div>
                        <h3>Your wishlist is empty</h3>
                        <p>Browse the shop and tap the heart icon to save items here for later.</p>
                        <div class="vv-empty-actions">
                            <a href="shop.php" class="vv-btn vv-btn-primary">Go to shop</a>
                            <a href="shop.php?view=new" class="vv-btn vv-btn-secondary">New arrivals</a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="vv-product-grid" id="wishlistGrid">
                    <?php foreach ($products as $p): ?>
                        <?php $prod_id = (int)$p['id']; ?>
                        <article class="vv-product-card" data-wishlist-card="<?php echo $prod_id; ?>">
                            <div class="vv-product-media">
                                <div class="vv-product-media-inner">
                                    <?php if (!empty($p['image_url'])): ?>
                                        <img
                                            src="<?php echo htmlspecialchars($p['image_url']); ?>"
                                            alt="<?php echo htmlspecialchars($p['name']); ?>"
                                            loading="lazy"
                                            data-fallback-text="Product image"
                                            onerror="vvImgFallback(this)"
                                        >
                                    <?php else: ?>
                                        <div class="vv-product-placeholder"><i class="bx bx-image-alt"></i></div>
                                    <?php endif; ?>

                                    <div class="vv-product-badges">
                                        <?php if ((int)$p['on_sale'] === 1 && (float)$p['sale_price'] > 0): ?>
                                            <span class="vv-badge vv-badge-sale">Sale</span>
                                        <?php endif; ?>
                                        <?php if ((int)$p['is_new'] === 1): ?>
                                            <span class="vv-badge vv-badge-new">New</span>
                                        <?php endif; ?>
                                        <?php if ((int)$p['is_hot'] === 1): ?>
                                            <span class="vv-badge vv-badge-hot">Hot</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Heart icon only -->
                                    <button
                                        type="button"
                                        class="vv-product-like-btn is-liked"
                                        data-product-id="<?php echo $prod_id; ?>"
                                        aria-label="Remove from wishlist"
                                    >
                                        <i class="bx bxs-heart"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="vv-product-body">
                                <h3 class="vv-product-name"><?php echo htmlspecialchars($p['name']); ?></h3>

                                <?php if (!empty($p['collection'])): ?>
                                    <p class="vv-product-meta"><?php echo htmlspecialchars($p['collection']); ?> collection</p>
                                <?php else: ?>
                                    <p class="vv-product-meta">SKU: <?php echo htmlspecialchars($p['sku']); ?></p>
                                <?php endif; ?>

                                <div class="vv-product-price-row">
                                    <?php if ((float)$p['sale_price'] > 0 && (int)$p['on_sale'] === 1): ?>
                                        <span class="vv-price-main"><?php echo money_fmt($p['sale_price']); ?></span>
                                        <span class="vv-price-old"><?php echo money_fmt($p['price']); ?></span>
                                    <?php else: ?>
                                        <span class="vv-price-main"><?php echo money_fmt($p['price']); ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="vv-product-actions">
                                    <a href="product-details.php?id=<?php echo $prod_id; ?>"
                                       class="vv-btn vv-btn-outline vv-btn-sm">
                                        View details
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <!-- Empty state placeholder (hidden initially) -->
                <div class="vv-empty" id="emptyWishlistBlock" style="display:none;">
                    <div class="vv-empty-icon"><i class="bx bx-heart"></i></div>
                    <div>
                        <h3>Your wishlist is empty</h3>
                        <p>Browse the shop and tap the heart icon to save items here for later.</p>
                        <div class="vv-empty-actions">
                            <a href="shop.php" class="vv-btn vv-btn-primary">Go to shop</a>
                            <a href="shop.php?view=new" class="vv-btn vv-btn-secondary">New arrivals</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </section>
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
                <li><a href="customer-orders.php">My orders</a></li>
                <li><a href="#">Shipping &amp; returns</a></li>
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

  // -------- Wishlist AJAX (event delegation) --------
  const wishlistCountEl   = document.getElementById('wishlistCount');
  const wishlistCountText = document.getElementById('wishlistCountText');
  const wishlistGrid      = document.getElementById('wishlistGrid');
  const emptyBlock        = document.getElementById('emptyWishlistBlock');

  function formBody(obj) {
    return Object.keys(obj).map(k => encodeURIComponent(k)+'='+encodeURIComponent(obj[k])).join('&');
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

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.vv-product-like-btn');
    if (!btn) return;

    const productId = btn.dataset.productId;
    if (!productId) return;

    btn.disabled = true;

    try {
      // Wishlist page: heart means "remove"
      const data = await postForm('wishlist.php', { product_id: productId, action: 'remove' });
      if (!data || !data.success) return;

      // Update counters
      if (wishlistCountEl && typeof data.count !== 'undefined') wishlistCountEl.textContent = data.count;
      if (wishlistCountText && typeof data.count !== 'undefined') wishlistCountText.textContent = data.count + ' item(s) saved';

      // Remove card smoothly
      const card = btn.closest('[data-wishlist-card]');
      if (card) {
        card.classList.add('vv-card-removing');
        setTimeout(() => {
          card.remove();

          // If grid is empty now, show empty state
          if (wishlistGrid && wishlistGrid.children.length === 0) {
            wishlistGrid.style.display = 'none';
            if (emptyBlock) emptyBlock.style.display = 'flex';
          }
        }, 180);
      }
    } catch (err) {
      console.error(err);
    } finally {
      btn.disabled = false;
    }
  });
});
</script>

</body>
</html>
