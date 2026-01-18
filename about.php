<?php
// about.php – About Velvet Vogue (Modern / industry-standard design + mobile menu + dropdown)
session_start();
require_once 'db.php';

// wishlist & cart counts for header
$wishlist_ids   = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])
    ? $_SESSION['wishlist']
    : [];
$wishlist_count = count($wishlist_ids);

$cart_items = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $pid => $qty) {
        $cart_items += (int)$qty;
    }
}

// current customer (storefront user)
$customer_id   = $_SESSION['customer_id']   ?? null;
$customer_name = $_SESSION['customer_name'] ?? '';
$customer_first = '';
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

    <title>About Velvet Vogue</title>

    <!-- Fonts & icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <!-- Global CSS -->
    <link rel="stylesheet" href="css/style.css">

    <style>
        * { box-sizing: border-box; }
        img { max-width: 100%; height: auto; display: block; }
        .vv-nav-wrapper { position: relative; }

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
           USER DROPDOWN (same behavior)
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

        /* ==================================================
           ✅ MODERN ABOUT PAGE (industry-style layout)
           ================================================== */

        .vv-about-page {
            background:
                radial-gradient(1200px 600px at 10% -10%, rgba(156, 95, 255, 0.14), transparent 60%),
                radial-gradient(900px 500px at 90% 0%, rgba(255, 168, 229, 0.16), transparent 55%),
                linear-gradient(180deg, #ffffff 0%, #fbf8ff 45%, #ffffff 100%);
        }

        .vv-about-hero {
            padding: 2.2rem 0 1.4rem;
        }

        .vv-about-hero-grid{
            display:grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
            gap: 1.4rem;
            align-items: stretch;
        }

        .vv-about-kicker{
            display:inline-flex;
            align-items:center;
            gap:0.45rem;
            padding:0.35rem 0.75rem;
            border-radius:999px;
            border:1px solid var(--vv-border-soft);
            background: rgba(255,255,255,0.75);
            color: var(--vv-text-soft);
            font-size: 0.78rem;
            letter-spacing: 0.06em;
        }
        .vv-about-kicker i{ color: var(--vv-accent); font-size: 1rem; }

        .vv-about-hero h1{
            font-size: clamp(1.8rem, 2.2vw + 1.1rem, 2.8rem);
            margin: 0.75rem 0 0.55rem;
            line-height: 1.08;
        }

        .vv-about-lead{
            font-size: 0.98rem;
            color: var(--vv-text-muted);
            max-width: 58ch;
            margin-bottom: 1rem;
        }

        .vv-about-cta-row{
            display:flex;
            gap:0.6rem;
            flex-wrap:wrap;
            align-items:center;
            margin-top: 0.7rem;
        }

        .vv-about-hero-card{
            background:#fff;
            border-radius: 22px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            overflow:hidden;
            display:flex;
            flex-direction:column;
            min-height: 340px;
        }

        .vv-about-hero-card-top{
            position:relative;
            padding: 1rem 1rem 0.95rem;
            background:
                radial-gradient(700px 320px at 20% 0%, rgba(156, 95, 255, 0.18), transparent 55%),
                radial-gradient(520px 260px at 100% 10%, rgba(255, 168, 229, 0.18), transparent 55%),
                #ffffff;
            border-bottom: 1px solid rgba(230, 220, 255, 0.55);
        }

        .vv-about-hero-card-title{
            font-size: 0.95rem;
            margin: 0 0 0.2rem;
        }
        .vv-about-hero-card-sub{
            margin:0;
            font-size:0.82rem;
            color: var(--vv-text-muted);
        }

        .vv-about-stats{
            display:grid;
            grid-template-columns: repeat(3, minmax(0,1fr));
            gap: 0.6rem;
            padding: 0.85rem 1rem 1rem;
        }

        .vv-about-stat{
            background: rgba(249, 245, 255, 0.65);
            border: 1px solid rgba(232, 222, 255, 0.85);
            border-radius: 16px;
            padding: 0.7rem 0.8rem;
        }
        .vv-about-stat strong{
            display:block;
            font-size: 1.05rem;
            color: var(--vv-accent);
            margin-bottom: 0.1rem;
        }
        .vv-about-stat span{
            font-size: 0.75rem;
            color: var(--vv-text-soft);
        }

        .vv-about-trust-row{
            display:flex;
            gap:0.5rem;
            flex-wrap:wrap;
            padding: 0 1rem 1rem;
            margin-top: -0.2rem;
        }
        .vv-trust-pill{
            display:inline-flex;
            align-items:center;
            gap:0.35rem;
            padding: 0.35rem 0.7rem;
            border-radius:999px;
            border: 1px solid var(--vv-border-soft);
            background:#fff;
            font-size: 0.78rem;
            color: var(--vv-text-muted);
        }
        .vv-trust-pill i{ color: var(--vv-accent); }

        /* Section headings */
        .vv-about-section{
            padding: 1.2rem 0;
        }
        .vv-about-section-header{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:1rem;
            margin-bottom: 0.9rem;
        }
        .vv-about-section-header h2{
            font-size: 1.35rem;
            margin:0;
        }
        .vv-about-section-header p{
            margin:0.25rem 0 0;
            color: var(--vv-text-muted);
            font-size: 0.88rem;
            max-width: 70ch;
        }

        /* Values / cards */
        .vv-about-cards{
            display:grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.9rem;
        }
        .vv-about-card{
            background:#fff;
            border-radius: 18px;
            border:1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.95rem 1rem;
        }
        .vv-about-card-icon{
            width: 38px;
            height: 38px;
            border-radius: 999px;
            background: var(--vv-accent-soft);
            display:flex;
            align-items:center;
            justify-content:center;
            margin-bottom: 0.45rem;
            color: var(--vv-accent);
            font-size: 1.15rem;
        }
        .vv-about-card h3{
            margin: 0 0 0.25rem;
            font-size: 0.95rem;
        }
        .vv-about-card p{
            margin:0;
            color: var(--vv-text-muted);
            font-size: 0.84rem;
            line-height: 1.55;
        }

        /* Process */
        .vv-about-process{
            display:grid;
            grid-template-columns: repeat(3, minmax(0,1fr));
            gap: 0.9rem;
        }
        .vv-step{
            background: rgba(255,255,255,0.85);
            border: 1px solid rgba(232, 222, 255, 0.85);
            border-radius: 18px;
            padding: 0.9rem 1rem;
        }
        .vv-step-top{
            display:flex;
            align-items:center;
            justify-content:space-between;
            margin-bottom: 0.35rem;
        }
        .vv-step-num{
            width: 32px;
            height: 32px;
            border-radius: 999px;
            display:flex;
            align-items:center;
            justify-content:center;
            background: var(--vv-accent);
            color:#fff;
            font-weight: 600;
            font-size: 0.86rem;
        }
        .vv-step i{ color: var(--vv-accent); font-size: 1.2rem; }
        .vv-step h3{
            margin: 0 0 0.25rem;
            font-size: 0.95rem;
        }
        .vv-step p{
            margin:0;
            font-size: 0.84rem;
            color: var(--vv-text-muted);
            line-height: 1.55;
        }

        /* Timeline */
        .vv-about-timeline{
            background:#fff;
            border:1px solid var(--vv-border-soft);
            border-radius: 18px;
            box-shadow: var(--vv-shadow-subtle);
            overflow:hidden;
        }
        .vv-tl-row{
            display:grid;
            grid-template-columns: 110px minmax(0,1fr);
            gap: 0.85rem;
            padding: 0.9rem 1rem;
            align-items:flex-start;
        }
        .vv-tl-row + .vv-tl-row{
            border-top: 1px solid #efe7ff;
        }
        .vv-tl-year{
            font-weight: 700;
            color: var(--vv-accent);
        }
        .vv-tl-text{
            color: var(--vv-text-muted);
            font-size: 0.86rem;
            line-height: 1.6;
        }

        /* FAQ (accessible using details) */
        .vv-faq{
            display:grid;
            grid-template-columns: minmax(0,1.35fr) minmax(0,1fr);
            gap: 1rem;
            align-items:start;
        }
        .vv-faq-card{
            background:#fff;
            border:1px solid var(--vv-border-soft);
            border-radius: 18px;
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.75rem 0.9rem;
        }
        .vv-faq-card details{
            padding: 0.55rem 0.35rem;
            border-radius: 14px;
        }
        .vv-faq-card details + details{
            border-top: 1px solid #efe7ff;
        }
        .vv-faq-card summary{
            cursor:pointer;
            font-weight: 600;
            font-size: 0.88rem;
            color: var(--vv-text-main);
            list-style:none;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap: 0.6rem;
        }
        .vv-faq-card summary::-webkit-details-marker{ display:none; }
        .vv-faq-card summary .vv-faq-icon{
            color: var(--vv-accent);
            font-size: 1.15rem;
            flex: 0 0 auto;
        }
        .vv-faq-card .vv-faq-a{
            margin: 0.45rem 0 0.15rem;
            color: var(--vv-text-muted);
            font-size: 0.86rem;
            line-height: 1.6;
        }

        /* CTA */
        .vv-about-cta{
            background:
                radial-gradient(900px 420px at 15% 0%, rgba(156, 95, 255, 0.22), transparent 55%),
                radial-gradient(820px 380px at 90% 30%, rgba(255, 168, 229, 0.18), transparent 55%),
                #ffffff;
            border: 1px solid var(--vv-border-soft);
            border-radius: 22px;
            box-shadow: var(--vv-shadow-subtle);
            padding: 1.15rem 1.2rem;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap: 1rem;
            flex-wrap:wrap;
        }
        .vv-about-cta h3{
            margin:0 0 0.15rem;
            font-size: 1.05rem;
        }
        .vv-about-cta p{
            margin:0;
            color: var(--vv-text-muted);
            font-size: 0.88rem;
            max-width: 70ch;
        }

        /* Responsive */
        @media (max-width: 991.98px){
            .vv-about-hero-grid{ grid-template-columns: 1fr; }
            .vv-about-cards{ grid-template-columns: repeat(2, minmax(0,1fr)); }
            .vv-about-process{ grid-template-columns: repeat(2, minmax(0,1fr)); }
            .vv-faq{ grid-template-columns: 1fr; }
        }
        @media (max-width: 575.98px){
            .vv-about-cards{ grid-template-columns: 1fr; }
            .vv-about-process{ grid-template-columns: 1fr; }
            .vv-tl-row{ grid-template-columns: 88px minmax(0,1fr); }
            .vv-about-stats{ grid-template-columns: 1fr; }
        }
    </style>
</head>

<body class="vv-body vv-about-page">

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
            <a href="about.php" class="active">About</a>
        </nav>

        <!-- Mobile nav dropdown -->
        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="about.php" class="active">About</a>
        </div>

        <!-- Actions -->
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
                    <i class="bx bx-user"></i> Sign In / Up
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main>

    <!-- HERO -->
    <section class="vv-about-hero">
        <div class="vv-container">
            <div class="vv-about-hero-grid">

                <div>
                    <span class="vv-about-kicker">
                        <i class="bx bx-sparkles"></i>
                        Behind the boutique
                    </span>

                    <h1>Velvet Vogue is a modern edit of fashion—curated, photographed, and delivered with care.</h1>

                    <p class="vv-about-lead">
                        We’re a Sri Lanka-based boutique focused on pieces that feel premium, fit beautifully, and last beyond the trend cycle.
                        Every item is selected for fabric, finish, and versatility—so your wardrobe feels intentional, not crowded.
                    </p>

                    <div class="vv-about-cta-row">
                        <a href="shop.php" class="vv-btn vv-btn-primary">
                            Shop the collection
                        </a>
                        <a href="shop.php?view=new" class="vv-btn vv-btn-secondary">
                            Explore new arrivals
                        </a>
                    </div>

                    <div class="vv-about-cta-row" style="margin-top:0.9rem;">
                        <span class="vv-trust-pill"><i class="bx bx-package"></i> Dispatch in 24–48h</span>
                        <span class="vv-trust-pill"><i class="bx bx-refresh"></i> Easy exchanges</span>
                        <span class="vv-trust-pill"><i class="bx bx-message-rounded-dots"></i> Human support</span>
                    </div>
                </div>

                <aside class="vv-about-hero-card">
                    <div class="vv-about-hero-card-top">
                        <h3 class="vv-about-hero-card-title">Velvet Vogue at a glance</h3>
                        <p class="vv-about-hero-card-sub">Small-team boutique experience, online convenience.</p>
                    </div>

                    <div class="vv-about-stats">
                        <div class="vv-about-stat">
                            <strong>5k+</strong>
                            <span>Customers served</span>
                        </div>
                        <div class="vv-about-stat">
                            <strong>120+</strong>
                            <span>Curated styles</span>
                        </div>
                        <div class="vv-about-stat">
                            <strong>4.8/5</strong>
                            <span>Avg. rating</span>
                        </div>
                    </div>

                    <div class="vv-about-trust-row">
                        <span class="vv-trust-pill"><i class="bx bx-badge-check"></i> Quality-checked</span>
                        <span class="vv-trust-pill"><i class="bx bx-map"></i> Island-wide delivery</span>
                        <span class="vv-trust-pill"><i class="bx bx-camera"></i> Styled photography</span>
                    </div>
                </aside>

            </div>
        </div>
    </section>

    <!-- VALUES -->
    <section class="vv-about-section">
        <div class="vv-container">
            <div class="vv-about-section-header">
                <div>
                    <h2>What we stand for</h2>
                    <p>Industry-standard shopping experiences start with trust: clear info, real fit guidance, and consistent quality.</p>
                </div>
            </div>

            <div class="vv-about-cards">
                <div class="vv-about-card">
                    <div class="vv-about-card-icon"><i class="bx bx-badge-check"></i></div>
                    <h3>Curated quality</h3>
                    <p>We select pieces for fabric, stitching, and finish—so what you receive matches what you saw online.</p>
                </div>

                <div class="vv-about-card">
                    <div class="vv-about-card-icon"><i class="bx bx-body"></i></div>
                    <h3>Fit that makes sense</h3>
                    <p>We document sizing details in a practical way—stretch, structure, and how it sits when you move.</p>
                </div>

                <div class="vv-about-card">
                    <div class="vv-about-card-icon"><i class="bx bx-happy-heart-eyes"></i></div>
                    <h3>Style over hype</h3>
                    <p>Trends change fast. We focus on pieces you can wear multiple ways—work days, events, and weekends.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- PROCESS -->
    <section class="vv-about-section" style="padding-top:0;">
        <div class="vv-container">
            <div class="vv-about-section-header">
                <div>
                    <h2>How we work</h2>
                    <p>A simple, modern flow—like the best fashion retailers: curated products, clear content, fast delivery.</p>
                </div>
            </div>

            <div class="vv-about-process">
                <div class="vv-step">
                    <div class="vv-step-top">
                        <span class="vv-step-num">1</span>
                        <i class="bx bx-search-alt-2"></i>
                    </div>
                    <h3>Source & select</h3>
                    <p>We shortlist pieces that meet quality and wearability standards before they reach the store.</p>
                </div>

                <div class="vv-step">
                    <div class="vv-step-top">
                        <span class="vv-step-num">2</span>
                        <i class="bx bx-camera"></i>
                    </div>
                    <h3>Style & photograph</h3>
                    <p>Clean visuals and details help you decide faster—similar to top-tier ecommerce experiences.</p>
                </div>

                <div class="vv-step">
                    <div class="vv-step-top">
                        <span class="vv-step-num">3</span>
                        <i class="bx bx-package"></i>
                    </div>
                    <h3>Pack & deliver</h3>
                    <p>Orders are carefully packed and dispatched quickly so your delivery stays predictable.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- TIMELINE -->
    <section class="vv-about-section">
        <div class="vv-container">
            <div class="vv-about-section-header">
                <div>
                    <h2>Our story</h2>
                    <p>We grew step-by-step: from pop-ups to an online boutique, keeping the same attention to detail.</p>
                </div>
            </div>

            <div class="vv-about-timeline">
                <div class="vv-tl-row">
                    <div class="vv-tl-year">2019</div>
                    <div class="vv-tl-text">Started with small pop-up edits—party dresses, blazers, and private styling sessions.</div>
                </div>
                <div class="vv-tl-row">
                    <div class="vv-tl-year">2021</div>
                    <div class="vv-tl-text">Launched online with local delivery, adding detailed sizing notes and styling info.</div>
                </div>
                <div class="vv-tl-row">
                    <div class="vv-tl-year">Today</div>
                    <div class="vv-tl-text">Weekly curated drops—dresses, tailoring, and accessories—with boutique-level care.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ + SUPPORT -->
    <section class="vv-about-section" style="padding-top:0;">
        <div class="vv-container">
            <div class="vv-about-section-header">
                <div>
                    <h2>Customer care</h2>
                    <p>Modern ecommerce is built on clarity: shipping expectations, exchanges, and fast human replies.</p>
                </div>
            </div>

            <div class="vv-faq">
                <div>
                    <div class="vv-about-card" style="padding:1rem 1rem;">
                        <div class="vv-about-card-icon"><i class="bx bx-support"></i></div>
                        <h3 style="margin-bottom:0.25rem;">We’re here to help</h3>
                        <p style="margin-bottom:0.65rem;">
                            Sizing help, styling suggestions, delivery updates, or exchanges—message us anytime during working hours.
                        </p>

                        <div class="vv-trust-pill" style="display:flex;justify-content:space-between;gap:0.8rem;border-radius:16px;">
                            <span><i class="bx bx-phone"></i> +94 77 123 4567</span>
                            <span><i class="bx bx-time-five"></i> Mon–Sat, 9am–7pm</span>
                        </div>

                        <div style="margin-top:0.8rem;">
                            <a href="contactsupport.php" class="vv-btn vv-btn-secondary w-100" style="width:100%;">
                                <i class="bx bx-message-dots"></i> Contact support
                            </a>
                        </div>
                    </div>
                </div>

                <div class="vv-faq-card">
                    <details open>
                        <summary>
                            <span>How long does delivery take?</span>
                            <i class="bx bx-chevron-down vv-faq-icon"></i>
                        </summary>
                        <div class="vv-faq-a">Most orders are dispatched within 24–48 hours on working days. Delivery typically takes 2–5 working days depending on your area.</div>
                    </details>

                    <details>
                        <summary>
                            <span>Can I exchange if the size is wrong?</span>
                            <i class="bx bx-chevron-down vv-faq-icon"></i>
                        </summary>
                        <div class="vv-faq-a">Yes—if unworn with tags attached, you can request an exchange or store credit within 14 days of delivery.</div>
                    </details>

                    <details>
                        <summary>
                            <span>Do you have a physical store?</span>
                            <i class="bx bx-chevron-down vv-faq-icon"></i>
                        </summary>
                        <div class="vv-faq-a">We operate mainly online, but we occasionally host pop-up try-on days. Follow our social pages for updates.</div>
                    </details>

                    <details>
                        <summary>
                            <span>How do reviews & complaints work?</span>
                            <i class="bx bx-chevron-down vv-faq-icon"></i>
                        </summary>
                        <div class="vv-faq-a">After delivery, you can log in to rate products and leave reviews. If something isn’t right, you can submit a complaint for help.</div>
                    </details>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="vv-about-section">
        <div class="vv-container">
            <div class="vv-about-cta">
                <div>
                    <h3>Ready to explore the collection?</h3>
                    <p>Browse the latest edits—from everyday dresses and suiting to accessories that finish the look.</p>
                </div>
                <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
                    <a href="shop.php" class="vv-btn vv-btn-primary">Shop all products</a>
                    <a href="shop.php?view=new" class="vv-btn vv-btn-secondary">New arrivals</a>
                </div>
            </div>
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
            <p class="vv-footer-copy">
                Curated pieces, considered details and a smoother online shopping experience.
            </p>
            <p class="vv-footer-copy-small">
                &copy; <?php echo date('Y'); ?> Velvet Vogue. All rights reserved.
            </p>
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
                <li><a href="contactsupport.php">Contact support</a></li>
                <li><a href="about.php">About the brand</a></li>
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
    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      userMenu.classList.toggle('open');
    });
    document.addEventListener('click', function () {
      userMenu.classList.remove('open');
    });
  }
});
</script>

</body>
</html>
