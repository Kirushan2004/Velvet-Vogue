<?php
// index.php – Storefront home page
session_start();
require_once 'db.php';

// ----------------- helpers -----------------
function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}

// --------------- login / header data ---------------

// wishlist & cart counts for header
$wishlist_ids   = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])
    ? $_SESSION['wishlist']
    : [];
$wishlist_count = count($wishlist_ids);

$cart_items = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $pid => $entry) {
        if (is_array($entry)) {
            $cart_items += (int)($entry['qty'] ?? 0);
        } else {
            $cart_items += (int)$entry;
        }
    }
}

// current customer (storefront user)
$customer_id    = $_SESSION['customer_id']   ?? null;
$customer_name  = $_SESSION['customer_name'] ?? '';
$customer_first = '';
if ($customer_name !== '') {
    $parts = preg_split('/\s+/', trim($customer_name));
    $customer_first = $parts[0] ?? $customer_name;
}

// --- load PROMOTIONS for home page ---
$promotions = [];
$promoSql = "
    SELECT
        id,
        title,
        subtitle,
        description,
        badge_label,
        image_url,
        cta_label,
        cta_link
    FROM promotions
    WHERE is_active = 1
      AND (starts_at IS NULL OR starts_at <= NOW())
      AND (ends_at   IS NULL OR ends_at   >= NOW())
    ORDER BY starts_at DESC, id DESC
    LIMIT 3
";
if ($res = $conn->query($promoSql)) {
    while ($row = $res->fetch_assoc()) {
        $promotions[] = $row;
    }
    $res->free();
}

// --- load some featured products for the home (“picks”) ---
$featuredProducts = [];
$featSql = "
    SELECT
        id,
        name,
        sku,
        price,
        sale_price,
        on_sale,
        image_url,
        collection,
        is_new,
        is_hot
    FROM products
    WHERE is_active = 1
    ORDER BY is_hot DESC, is_new DESC, on_sale DESC, id DESC
    LIMIT 10
";
if ($res = $conn->query($featSql)) {
    while ($row = $res->fetch_assoc()) {
        $featuredProducts[] = $row;
    }
    $res->free();
}

// --- NEW ARRIVALS grid ---
$newArrivals = [];
$newSql = "
    SELECT
        id,
        name,
        sku,
        price,
        sale_price,
        on_sale,
        image_url,
        collection,
        is_new,
        is_hot
    FROM products
    WHERE is_active = 1
      AND is_new = 1
    ORDER BY id DESC
    LIMIT 8
";
if ($res = $conn->query($newSql)) {
    while ($row = $res->fetch_assoc()) {
        $newArrivals[] = $row;
    }
    $res->free();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <!-- IMPORTANT for mobile scaling -->
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Velvet Vogue | Modern Wardrobe Essentials</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Boxicons -->
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <!-- Site CSS -->
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
        * { box-sizing: border-box; }
        img { max-width: 100%; height: auto; display: block; }

        /* =========================================================
           ✅ SAME HEADER / LEFT MOBILE MENU AS product-details.php
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

        /* ======= User dropdown ======= */
        .vv-user-menu { position: relative; }
        .vv-user-toggle { cursor: pointer; white-space: nowrap; }
        .vv-user-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 0.35rem);
            min-width: 160px;
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

        /* ======= Promotions strip ======= */
        /* .vv-promo-section { background: #f9f5ff; }
        .vv-promo-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; }
        .vv-promo-card {
            background: #fff;
            border-radius: 20px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .vv-promo-media { position: relative; height: 160px; overflow: hidden; }
        .vv-promo-media img { width: 100%; height: 100%; object-fit: cover; }
        .vv-promo-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f4f3fb;
            color: #c0b7d4;
            font-size: 2.2rem;
        }
        .vv-promo-badge {
            position: absolute;
            left: 0.75rem;
            top: 0.75rem;
            padding: 0.18rem 0.6rem;
            border-radius: 999px;
            background: #ffffff;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--vv-accent);
        }
        .vv-promo-body { padding: 0.75rem 0.9rem 0.9rem; font-size: 0.86rem; }
        .vv-promo-body h3 { font-size: 1rem; margin-bottom: 0.2rem; }
        .vv-promo-subtitle { font-size: 0.8rem; color: var(--vv-text-soft); margin-bottom: 0.25rem; }
        .vv-promo-text { font-size: 0.8rem; color: var(--vv-text-main); margin-bottom: 0.45rem; }
        @media (max-width: 767.98px) { .vv-promo-media { height: 140px; } } */

        /* ======= Promotions strip (FIX: banner image should NOT crop) ======= */
/* ======= Promotions strip (uniform image size in all cards) ======= */
.vv-promo-section { background: #f9f5ff; }

.vv-promo-row{
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1rem;
  align-items: stretch;
}

.vv-promo-card{
  background: #fff;
  border-radius: 20px;
  border: 1px solid var(--vv-border-soft);
  box-shadow: var(--vv-shadow-subtle);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  height: 100%;
}

/* ✅ This makes ALL promo image boxes the SAME size */
.vv-promo-media{
  position: relative;
  width: 100%;
  height: 190px;              /* <- SAME height always */
  background: #f4f3fb;
  overflow: hidden;
}

/* ✅ Image fits INSIDE the fixed box (no cropping) */
.vv-promo-media img{
  width: 100%;
  height: 100%;
  object-fit: fill;         /* <- keep full image visible */
  object-position: center;
  display: block;
}

/* placeholder uses same box size */
.vv-promo-placeholder{
  width: 100%;
  height: 100%;
  display:flex;
  align-items:center;
  justify-content:center;
  background:#f4f3fb;
  color:#c0b7d4;
  font-size:2.2rem;
}

.vv-promo-badge{
  position: absolute;
  left: 0.75rem;
  top: 0.75rem;
  padding: 0.18rem 0.6rem;
  border-radius: 999px;
  background: #ffffff;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--vv-accent);
  z-index: 2;
}

/* Keep content heights aligned too */
.vv-promo-body{
  padding: 0.75rem 0.9rem 0.9rem;
  font-size: 0.86rem;
  display: flex;
  flex-direction: column;
  flex: 1;
}

.vv-promo-body h3{ font-size: 1rem; margin-bottom: 0.2rem; }
.vv-promo-subtitle{ font-size: 0.8rem; color: var(--vv-text-soft); margin-bottom: 0.25rem; }
.vv-promo-text{ font-size: 0.8rem; color: var(--vv-text-main); margin-bottom: 0.45rem; }

/* ✅ Button always goes to bottom, so all cards look equal */
.vv-promo-body .vv-btn{
  margin-top: auto;
}

/* Responsive: keep same rules, just slightly smaller height on mobile */
@media (max-width: 767.98px){
  .vv-promo-media{ height: 150px; }
}


        /* ===== Buttons in cards full width ===== */
        .vv-product-actions { margin-top: 0.6rem; }
        .vv-product-actions .vv-btn {
            display: flex;
            justify-content: center;
            width: 100%;
            white-space: nowrap;
            border-radius: 14px;
        }

        /* Lookbook button */
        .vv-btn-lookbook {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            padding: 0.5rem 1.2rem;
            border-radius: 999px;
            font-size: 0.85rem;
            line-height: 1;
            white-space: nowrap;
        }
        @media (max-width: 575.98px) { .vv-btn-lookbook { width: 100%; } }

        /* =========================================================
           ✅ PRODUCT GRID + CARD FIX
           ========================================================= */
        .vv-product-grid{
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px !important;
            align-items: stretch;
        }

        @media (max-width: 575.98px){
            .vv-product-grid{
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
        }

        @media (max-width: 360px){
            .vv-product-grid{
                grid-template-columns: 1fr !important;
            }
        }

        .vv-product-card{
            width: 100% !important;
            max-width: none !important;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .vv-product-media-inner{
            position: relative;
            width: 100%;
            aspect-ratio: 4 / 5;
            overflow: hidden;
            border-radius: 18px;
            background: #f4f3fb;
        }

        .vv-product-media-inner img{
            width: 100% !important;
            height: 100% !important;
            object-fit: cover;
        }

        .vv-product-media-inner img.vv-img-fallback{
            object-fit: contain !important;
            background: #f4f3fb;
        }

        .vv-product-body{
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 12px 12px 14px !important;
        }

        .vv-product-name{
            margin: 8px 0 6px !important;
            line-height: 1.15;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 2.4em;
        }

        .vv-product-meta{ margin: 0 0 10px !important; }

        .vv-product-price-row{
            margin-top: auto;
            padding-top: 6px;
        }

        .vv-product-actions{ margin-top: 10px !important; }

        .vv-product-actions .vv-btn,
        .vv-product-actions .vv-btn-sm,
        .vv-product-actions .vv-btn-primary{
            width: 100% !important;
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
            white-space: nowrap !important;
            border-radius: 14px !important;
            padding: 10px 12px !important;
        }

        .vv-product-like-btn{
            position: absolute !important;
            top: 10px;
            right: 10px;
            z-index: 3;
        }
    </style>
</head>

<body class="vv-body">

<header class="vv-header">
    <div class="vv-container vv-nav-wrapper">

        <!-- ✅ LEFT mobile menu button (same behavior as product-details.php) -->
        <button type="button" class="vv-nav-toggle" id="vvNavToggle"
                aria-expanded="false" aria-controls="vvMobileNav">
            <i class="bx bx-menu" style="font-size:1.3rem;"></i>
        </button>

        <!-- Logo -->
        <a href="index.php" class="vv-logo" style="text-decoration:none;color:inherit;">
            <span class="vv-logo-main">Velvet</span>
            <span class="vv-logo-sub">Vogue</span>
        </a>

        <!-- Desktop nav -->
        <nav class="vv-nav">
            <a href="index.php" class="active">Home</a>
            <a href="shop.php">Shop</a>
            <a href="about.php">About</a>
        </nav>

        <!-- Mobile nav (opens on left) -->
        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php" class="active">Home</a>
            <a href="shop.php">Shop</a>
            <a href="about.php">About</a>
        </div>

        <!-- Header actions -->
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
                        <a href="customer-complaints.php">
                            <i class="bx bx-error-circle" style="font-size:1rem;margin-right:0.25rem;"></i>
                            My complaints
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
                    <i class="bx bx-user"></i> Sign In / Up
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main>
    <!-- HERO -->
    <section class="vv-hero">
        <div class="vv-container vv-hero-grid">
            <div class="vv-hero-copy">
                <p class="vv-eyebrow">New season · New you</p>
                <h1>Timeless pieces for modern wardrobes.</h1>
                <p class="vv-hero-sub">
                    Discover curated dresses, suits, accessories and more – crafted to elevate
                    every moment from desk to dinner.
                </p>

                <div class="vv-hero-cta">
                    <a href="shop.php" class="vv-btn vv-btn-primary">Shop all products</a>
                    <a href="shop.php?view=new" class="vv-btn vv-btn-secondary">View new arrivals</a>
                </div>

                <div class="vv-hero-meta">
                    <div>
                        <span class="vv-meta-label">Free delivery</span>
                        <span class="vv-meta-text">On orders over $150</span>
                    </div>
                    <div>
                        <span class="vv-meta-label">Easy returns</span>
                        <span class="vv-meta-text">Within 14 days</span>
                    </div>
                    <div>
                        <span class="vv-meta-label">5k+ customers</span>
                        <span class="vv-meta-text">Loved across Sri Lanka</span>
                    </div>
                </div>
            </div>

            <div class="vv-hero-media">
                <div class="vv-hero-card">
                    <div class="vv-hero-card-media">
                        <div class="vv-hero-image-mask"></div>
                        <div class="vv-hero-label"><span class="dot"></span> Trending now</div>
                        <div class="vv-hero-pill">Velvet dresses · Statement blazers · Purses &amp; wallets</div>
                    </div>
                    <div class="vv-hero-card-footer">
                        <div>
                            <p class="vv-hero-small">This week’s edit</p>
                            <p class="vv-hero-title">Evening silhouettes &amp; tailored details.</p>
                        </div>
                        <a href="shop.php?view=hot" class="vv-btn vv-btn-secondary vv-btn-lookbook">
                            View lookbook <i class="bx bx-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- PROMOTIONS -->
    <?php if (!empty($promotions)): ?>
        <section class="vv-section vv-promo-section">
            <div class="vv-container">
                <div class="vv-section-header vv-section-header-tight">
                    <div>
                        <h2>Current offers &amp; promotions</h2>
                        <p class="vv-section-sub">Limited-time deals and bundles picked for you.</p>
                    </div>
                </div>

                <div class="vv-promo-row">
                    <?php foreach ($promotions as $promo): ?>
                        <?php
                        $promoLink  = $promo['cta_link'] ?: 'shop.php';
                        $promoLabel = $promo['cta_label'] ?: 'Shop this offer';
                        ?>
                        <article class="vv-promo-card">
                            <div class="vv-promo-media">
                                <?php if (!empty($promo['image_url'])): ?>
                                    <img
                                        src="<?php echo htmlspecialchars($promo['image_url']); ?>"
                                        alt="<?php echo htmlspecialchars($promo['title']); ?>"
                                        loading="lazy"
                                        data-fallback-text="Promotion image"
                                        onerror="vvImgFallback(this)"
                                    >
                                <?php else: ?>
                                    <div class="vv-promo-placeholder"><i class="bx bx-purchase-tag-alt"></i></div>
                                <?php endif; ?>

                                <?php if (!empty($promo['badge_label'])): ?>
                                    <span class="vv-promo-badge"><?php echo htmlspecialchars($promo['badge_label']); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="vv-promo-body">
                                <h3><?php echo htmlspecialchars($promo['title']); ?></h3>
                                <?php if (!empty($promo['subtitle'])): ?>
                                    <p class="vv-promo-subtitle"><?php echo htmlspecialchars($promo['subtitle']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($promo['description'])): ?>
                                    <p class="vv-promo-text"><?php echo htmlspecialchars($promo['description']); ?></p>
                                <?php endif; ?>
                                <a href="<?php echo htmlspecialchars($promoLink); ?>" class="vv-btn vv-btn-secondary-sm">
                                    <?php echo htmlspecialchars($promoLabel); ?>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- NEW ARRIVALS GRID -->
    <?php if (!empty($newArrivals)): ?>
        <section class="vv-section">
            <div class="vv-container">
                <div class="vv-section-header vv-section-header-tight">
                    <div>
                        <h2>New arrivals</h2>
                        <p class="vv-section-sub">Fresh pieces just added to the boutique.</p>
                    </div>
                    <a href="shop.php?view=new" class="vv-link-reset">
                        View all new arrivals <i class="bx bx-right-arrow-alt"></i>
                    </a>
                </div>

                <div class="vv-product-grid">
                    <?php foreach ($newArrivals as $p): ?>
                        <?php
                        $prod_id   = (int)$p['id'];
                        $isLiked   = in_array($prod_id, $wishlist_ids, true);
                        $likeClass = $isLiked ? 'is-liked' : '';
                        $likeIcon  = $isLiked ? 'bxs-heart' : 'bx-heart';
                        ?>
                        <article class="vv-product-card">
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
                                        <?php if ((int)$p['is_new'] === 1): ?>
                                            <span class="vv-badge vv-badge-new">New</span>
                                        <?php endif; ?>
                                        <?php if ((int)$p['on_sale'] === 1 && (float)$p['sale_price'] > 0): ?>
                                            <span class="vv-badge vv-badge-sale">Sale</span>
                                        <?php endif; ?>
                                    </div>

                                    <button
                                        type="button"
                                        class="vv-product-like-btn <?php echo $likeClass; ?>"
                                        data-product-id="<?php echo $prod_id; ?>"
                                        aria-label="Toggle wishlist"
                                    >
                                        <i class="bx <?php echo $likeIcon; ?>"></i>
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
                                       class="vv-btn vv-btn-primary vv-btn-sm">
                                        View product
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- FEATURED PICKS -->
    <section class="vv-section vv-section-muted">
        <div class="vv-container">
            <div class="vv-section-header vv-section-header-tight">
                <div>
                    <h2>Featured picks</h2>
                    <p class="vv-section-sub">A handful of pieces we’re loving this week.</p>
                </div>
                <a href="shop.php" class="vv-link-reset">
                    View all products <i class="bx bx-right-arrow-alt"></i>
                </a>
            </div>

            <?php if (empty($featuredProducts)): ?>
                <p class="text-muted">No products available yet.</p>
            <?php else: ?>
                <div class="vv-product-grid">
                    <?php foreach ($featuredProducts as $p): ?>
                        <?php
                        $prod_id   = (int)$p['id'];
                        $isLiked   = in_array($prod_id, $wishlist_ids, true);
                        $likeClass = $isLiked ? 'is-liked' : '';
                        $likeIcon  = $isLiked ? 'bxs-heart' : 'bx-heart';
                        ?>
                        <article class="vv-product-card">
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
                                        <?php if ((int)$p['on_sale'] === 1): ?><span class="vv-badge vv-badge-sale">Sale</span><?php endif; ?>
                                        <?php if ((int)$p['is_new'] === 1): ?><span class="vv-badge vv-badge-new">New</span><?php endif; ?>
                                        <?php if ((int)$p['is_hot'] === 1): ?><span class="vv-badge vv-badge-hot">Hot</span><?php endif; ?>
                                    </div>

                                    <button
                                        type="button"
                                        class="vv-product-like-btn <?php echo $likeClass; ?>"
                                        data-product-id="<?php echo $prod_id; ?>"
                                        aria-label="Toggle wishlist"
                                    >
                                        <i class="bx <?php echo $likeIcon; ?>"></i>
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
                                       class="vv-btn vv-btn-primary vv-btn-sm">
                                        View product
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- WHY SHOP WITH US -->
    <section class="vv-section">
        <div class="vv-container">
            <div class="vv-section-header vv-section-header-centered">
                <div>
                    <h2>Why shop with Velvet Vogue?</h2>
                    <p class="vv-section-sub">Boutique-level styling, with the ease of an online experience.</p>
                </div>
            </div>

            <div class="vv-feature-grid">
                <div class="vv-feature-card">
                    <div class="vv-feature-icon"><i class="bx bx-badge-check"></i></div>
                    <h3>Curated quality</h3>
                    <p>Every piece is handpicked for fabric, fit, and finish – so your wardrobe feels premium, not crowded.</p>
                </div>
                <div class="vv-feature-card">
                    <div class="vv-feature-icon"><i class="bx bx-package"></i></div>
                    <h3>Fast dispatch</h3>
                    <p>Local orders are carefully packed and dispatched within 24–48 hours with tracked delivery partners.</p>
                </div>
                <div class="vv-feature-card">
                    <div class="vv-feature-icon"><i class="bx bx-refresh"></i></div>
                    <h3>Easy exchanges</h3>
                    <p>Not quite right? Enjoy simple, human support for size changes or store credit within 14 days.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FEATURED EDITS -->
    <section class="vv-section vv-section-muted">
        <div class="vv-container">
            <div class="vv-section-header">
                <div>
                    <h2>Featured edits</h2>
                    <p class="vv-section-sub">Explore quick edits crafted around how you actually get dressed.</p>
                </div>
            </div>

            <div class="vv-collection-row">
                <article class="vv-collection-card">
                    <div class="vv-collection-media vv-collection-media--dresses"></div>
                    <div class="vv-collection-body">
                        <h3>Evening dresses</h3>
                        <p>Velvet, satin and flowing silhouettes for weddings, dinners and late nights out.</p>
                        <a href="shop.php?q=dress" class="vv-btn vv-btn-secondary-sm">View dresses</a>
                    </div>
                </article>
                <article class="vv-collection-card">
                    <div class="vv-collection-media vv-collection-media--tailoring"></div>
                    <div class="vv-collection-body">
                        <h3>Tailored &amp; suited</h3>
                        <p>Blazers, trousers and full suits that move with you – from meetings to celebrations.</p>
                        <a href="shop.php?q=suit" class="vv-btn vv-btn-secondary-sm">View suits</a>
                    </div>
                </article>
                <article class="vv-collection-card">
                    <div class="vv-collection-media vv-collection-media--accessories"></div>
                    <div class="vv-collection-body">
                        <h3>Finishing touches</h3>
                        <p>Purses, wallets and belts that pull every look together in one effortless step.</p>
                        <a href="shop.php?q=wallet" class="vv-btn vv-btn-secondary-sm">Shop accessories</a>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <!-- ABOUT + SUPPORT -->
    <section id="about" class="vv-section">
        <div class="vv-container vv-bottom-grid">
            <div>
                <h2>About Velvet Vogue</h2>
                <p class="vv-section-sub">
                    Velvet Vogue is a modern boutique bringing carefully selected fashion pieces from independent designers.
                    Every item is chosen for quality, fit and timeless style.
                </p>
                <p class="vv-body-text">
                    We believe shopping should feel effortless – from browsing online to unboxing at home.
                    That’s why we focus on detailed product information, curated collections and responsive customer support.
                </p>
            </div>

            <aside class="vv-help-card">
                <h3>Need some help with your order?</h3>
                <p class="vv-help-intro">Our small team is happy to answer questions about sizing, styling and delivery.</p>
                <ul class="vv-help-list">
                    <li><i class="bx bx-phone"></i><span>Call / WhatsApp: <strong>+94 77 123 4567</strong></span></li>
                    <li><i class="bx bx-envelope"></i><span>Email: <strong>support@velvetvogue.test</strong></span></li>
                    <li><i class="bx bx-time-five"></i><span>Hours: Mon–Sat, 9.00am – 7.00pm</span></li>
                </ul>
                <div class="vv-help-actions">
                    <a href="contactsupport.php" class="vv-btn vv-btn-secondary w-100">
                        <i class="bx bx-message-dots"></i> Contact support
                    </a>
                </div>
            </aside>
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
                <li><a href="shop.php?new=1">New arrivals</a></li>
                <li><a href="shop.php?sale=1">On sale</a></li>
            </ul>
        </div>

        <div class="vv-footer-column">
            <h4>Support</h4>
            <ul class="vv-footer-list">
                <li><a href="#">Shipping &amp; returns</a></li>
                <li><a href="contactsupport.php">Contact support</a></li>
                <li><a href="about.php">About</a></li>
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
  // ✅ SAME mobile nav toggle behavior as product-details.php
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

  // user dropdown
  const userMenu = document.querySelector('.vv-user-menu');
  if (userMenu) {
    const toggle = userMenu.querySelector('.vv-user-toggle');
    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      userMenu.classList.toggle('open');
    });
    document.addEventListener('click', function () {
      userMenu.classList.remove('open');
    });
  }

  // wishlist ajax (delegation)
  const wishlistCountEl = document.getElementById('wishlistCount');

  function formBody(obj) {
    return Object.keys(obj)
      .map(k => encodeURIComponent(k) + '=' + encodeURIComponent(obj[k]))
      .join('&');
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

    const isLiked = btn.classList.contains('is-liked');
    const action  = isLiked ? 'remove' : 'add';

    btn.disabled = true;

    try {
      const data = await postForm('wishlist.php', { product_id: productId, action });
      if (!data || !data.success) return;

      const icon = btn.querySelector('i');

      if (data.in_wishlist) {
        btn.classList.add('is-liked');
        if (icon) { icon.classList.remove('bx-heart'); icon.classList.add('bxs-heart'); }
      } else {
        btn.classList.remove('is-liked');
        if (icon) { icon.classList.add('bx-heart'); icon.classList.remove('bxs-heart'); }
      }

      if (wishlistCountEl && typeof data.count !== 'undefined') {
        wishlistCountEl.textContent = data.count;
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
