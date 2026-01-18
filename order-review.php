<?php
// order-review.php – Add / edit a review for a completed order item
// Modern + responsive + mobile menu + image fallback
session_start();
require_once 'db.php';

if (empty($_SESSION['customer_id'])) {
    $_SESSION['customer_login_notice'] = 'Please sign in to review your order.';
    $redirect = 'customer-orders.php';
    header('Location: customer-login.php?redirect=' . urlencode($redirect));
    exit;
}

$customer_id   = (int)$_SESSION['customer_id'];
$customer_name = $_SESSION['customer_name'] ?? '';

function clean_str($v) { return trim($v ?? ''); }

$error          = '';
$order_id       = 0;
$order_item_id  = 0;
$productId      = 0;
$itemRow        = null;
$existingReview = null;

// ---------- Handle POST (save review) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id      = (int)($_POST['order_id'] ?? 0);
    $order_item_id = (int)($_POST['order_item_id'] ?? 0);
    $rating        = (int)($_POST['rating'] ?? 0);
    $comment       = clean_str($_POST['comment'] ?? '');

    if ($rating < 1 || $rating > 5) $rating = 5;
    if ($comment === '') $error = 'Please write a short review message.';

    if (!$error) {
        // verify that this order item belongs to this customer and order is completed
        $sql = "
            SELECT
                oi.id,
                oi.order_id,
                oi.product_id,
                oi.product_name,
                oi.size,
                oi.color,
                oi.quantity,
                oi.unit_price,
                o.order_number,
                o.created_at,
                o.status,
                p.image_url
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            INNER JOIN products p ON p.id = oi.product_id
            WHERE oi.id = ?
              AND oi.order_id = ?
              AND o.customer_id = ?
              AND o.status = 'completed'
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iii', $order_item_id, $order_id, $customer_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $itemRow = $res->fetch_assoc();
        $stmt->close();

        if (!$itemRow) {
            $error = 'We could not verify this order item to review.';
        } else {
            $productId = (int)$itemRow['product_id'];

            // check if review already exists for this product + customer_name
            if ($customer_name !== '') {
                $sql = "
                    SELECT id, rating, comment
                    FROM product_reviews
                    WHERE product_id = ?
                      AND customer_name = ?
                    LIMIT 1
                ";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('is', $productId, $customer_name);
                $stmt->execute();
                $res = $stmt->get_result();
                $existingReview = $res->fetch_assoc();
                $stmt->close();
            }

            if (!$error) {
                if ($existingReview) {
                    $review_id = (int)$existingReview['id'];
                    $sql = "
                        UPDATE product_reviews
                        SET rating = ?, comment = ?, created_at = NOW()
                        WHERE id = ?
                    ";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('isi', $rating, $comment, $review_id);
                    $stmt->execute();
                    $stmt->close();
                    $_SESSION['customer_orders_flash'] = 'Your review has been updated.';
                } else {
                    $sql = "
                        INSERT INTO product_reviews
                        (product_id, customer_name, rating, comment, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('isis', $productId, $customer_name, $rating, $comment);
                    $stmt->execute();
                    $stmt->close();
                    $_SESSION['customer_orders_flash'] = 'Thank you, your review has been added.';
                }

                header('Location: customer-orders.php#order-' . $order_id);
                exit;
            }
        }
    }
}

// ---------- Handle GET (or POST with error) ----------
if (!$itemRow) {
    $order_id      = $order_id ?: (int)($_GET['order_id'] ?? 0);
    $order_item_id = $order_item_id ?: (int)($_GET['item_id'] ?? 0);

    if ($order_id <= 0 || $order_item_id <= 0) {
        http_response_code(400);
        echo "Missing order or item id.";
        exit;
    }

    $sql = "
        SELECT
            oi.id,
            oi.order_id,
            oi.product_id,
            oi.product_name,
            oi.size,
            oi.color,
            oi.quantity,
            oi.unit_price,
            o.order_number,
            o.created_at,
            o.status,
            p.image_url
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        INNER JOIN products p ON p.id = oi.product_id
        WHERE oi.id = ?
          AND oi.order_id = ?
          AND o.customer_id = ?
          AND o.status = 'completed'
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $order_item_id, $order_id, $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $itemRow = $res->fetch_assoc();
    $stmt->close();

    if (!$itemRow) {
        http_response_code(404);
        echo "Order item not found or not eligible for review.";
        exit;
    }

    $productId = (int)$itemRow['product_id'];

    // existing review (if any)
    if ($customer_name !== '') {
        $sql = "
            SELECT id, rating, comment
            FROM product_reviews
            WHERE product_id = ?
              AND customer_name = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $productId, $customer_name);
        $stmt->execute();
        $res = $stmt->get_result();
        $existingReview = $res->fetch_assoc();
        $stmt->close();
    }
}

$defaultRating = $existingReview ? (int)$existingReview['rating'] : 5;
$commentValue  = $existingReview ? (string)$existingReview['comment'] : '';

// placeholder (works without external files)
$placeholderSvg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
  <rect width="200" height="200" fill="#f4f3fb"/>
  <path d="M55 125l30-32 26 28 16-18 35 38H55z" fill="#d9d3ea"/>
  <circle cx="78" cy="78" r="10" fill="#d9d3ea"/>
  <text x="100" y="165" text-anchor="middle" font-family="Arial, sans-serif" font-size="12" fill="#b7b0c9">No image</text>
</svg>
SVG;
$placeholderDataUri = 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($placeholderSvg);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Review product | Velvet Vogue</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="css/style.css">

    <style>
        .vv-page-main { padding: 1.8rem 0 2.2rem; }

        .vv-review-card{
            max-width: 640px;
            margin: 0 auto;
            background:#fff;
            border-radius:20px;
            border:1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 1rem 1.2rem 1.2rem;
        }

        .vv-review-header{
            display:flex;
            gap:0.8rem;
            margin-bottom:0.8rem;
            align-items:flex-start;
        }
        .vv-review-thumb{
            flex: 0 0 80px;
            width:80px;
            height:80px;
            border-radius:14px;
            overflow:hidden;
            background:#f4f3fb;
            border:1px solid var(--vv-border-soft);
        }
        .vv-review-thumb img{
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }

        .vv-review-meta{ font-size:0.8rem; color: var(--vv-text-soft); }
        .vv-review-product-name{ font-size:0.96rem; font-weight:600; margin-bottom:0.1rem; }

        .vv-alert-error{
            padding:0.5rem 0.75rem;
            border-radius:999px;
            font-size:0.8rem;
            margin-bottom:0.7rem;
            background:#ffebee;
            color:#c62828;
            border:1px solid #ffcdd2;
        }

        .vv-form-row{ margin-bottom:0.65rem; font-size:0.86rem; }
        .vv-form-row label{
            display:block;
            font-size:0.8rem;
            font-weight:500;
            margin-bottom:0.15rem;
        }
        .vv-form-row textarea{
            width:100%;
            border-radius:12px;
            border:1px solid var(--vv-border-strong);
            padding:0.4rem 0.7rem;
            min-height:80px;
            resize:vertical;
            font-size:0.86rem;
        }

        /* Stars */
        .vv-rating-stars{
            display:inline-flex;
            gap:0.2rem;
            cursor:pointer;
            user-select:none;
        }
        .vv-rating-stars .star{
            font-size:1.45rem;
            color:#d3c8f0;
            transition: transform 0.12s ease, color 0.15s ease;
            line-height:1;
        }
        .vv-rating-stars .star.active{ color:#ffc107; }
        .vv-rating-stars .star:hover{ transform: translateY(-1px); }

        .vv-rating-hint{
            font-size:0.78rem;
            color: var(--vv-text-soft);
            margin-top:0.25rem;
        }

        .vv-form-actions{
            margin-top:0.6rem;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:0.6rem;
            font-size:0.8rem;
            flex-wrap:wrap;
        }

        /* Header: mobile menu support (same pattern as above) */
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
            .vv-nav-toggle{ display:inline-flex; align-items:center; justify-content:center; }
            .vv-logo{ margin-right:auto; }
            .vv-nav-actions{ display:flex; align-items:center; justify-content:flex-end; gap:0.6rem; }

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

        @media (max-width: 600px){
            .vv-review-card{ padding: 0.95rem 0.95rem 1rem; }
            .vv-review-header{ gap:0.65rem; }
        }
    </style>
</head>
<body class="vv-body">

<header class="vv-header">
    <div class="vv-container vv-nav-wrapper">
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
            <a href="customer-orders.php" class="active">My orders</a>
        </nav>

        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="customer-orders.php">My orders</a>
            <a href="wishlist.php">Wishlist</a>
            <a href="customer-profile.php">My profile</a>
        </div>

        <div class="vv-nav-actions">
            <a href="customer-orders.php" class="vv-pill-link">
                <i class="bx bx-arrow-back"></i> Back to orders
            </a>
        </div>
    </div>
</header>

<main class="vv-page-main">
    <div class="vv-container">
        <div class="vv-review-card">
            <h1 style="font-size:1.2rem;margin-bottom:0.4rem;">Review your product</h1>

            <div class="vv-review-header">
                <div class="vv-review-thumb">
                    <?php
                        $img = trim($itemRow['image_url'] ?? '');
                        $imgSrc = $img !== '' ? $img : $placeholderDataUri;
                    ?>
                    <img
                        src="<?php echo htmlspecialchars($imgSrc); ?>"
                        alt="<?php echo htmlspecialchars($itemRow['product_name'] ?? 'Product'); ?>"
                        onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($placeholderDataUri); ?>';"
                    >
                </div>
                <div>
                    <div class="vv-review-product-name">
                        <?php echo htmlspecialchars($itemRow['product_name']); ?>
                    </div>
                    <div class="vv-review-meta">
                        Order: <?php echo htmlspecialchars($itemRow['order_number']); ?><br>
                        Placed on: <?php echo htmlspecialchars($itemRow['created_at']); ?><br>
                        Qty: <?php echo (int)$itemRow['quantity']; ?>
                        <?php if (!empty($itemRow['color'])): ?>
                            · Color: <?php echo htmlspecialchars($itemRow['color']); ?>
                        <?php endif; ?>
                        <?php if (!empty($itemRow['size'])): ?>
                            · Size: <?php echo htmlspecialchars($itemRow['size']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="vv-alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="order_id" value="<?php echo (int)$itemRow['order_id']; ?>">
                <input type="hidden" name="order_item_id" value="<?php echo (int)$itemRow['id']; ?>">
                <input type="hidden" id="rating-input" name="rating" value="<?php echo (int)$defaultRating; ?>">

                <div class="vv-form-row">
                    <label>Your rating</label>
                    <div class="vv-rating-stars" id="ratingStars" role="radiogroup" aria-label="Rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span
                                class="star <?php echo $i <= $defaultRating ? 'active' : ''; ?>"
                                data-value="<?php echo $i; ?>"
                                role="radio"
                                aria-checked="<?php echo $i == $defaultRating ? 'true' : 'false'; ?>"
                                tabindex="<?php echo $i == $defaultRating ? '0' : '-1'; ?>"
                                title="<?php echo $i; ?> star<?php echo $i > 1 ? 's' : ''; ?>"
                            >★</span>
                        <?php endfor; ?>
                    </div>
                    <div class="vv-rating-hint">Tip: you can also use the arrow keys, then press Enter.</div>
                </div>

                <div class="vv-form-row">
                    <label for="comment">Your review</label>
                    <textarea id="comment" name="comment" required><?php echo htmlspecialchars($commentValue); ?></textarea>
                </div>

                <div class="vv-form-actions">
                    <button type="submit" class="vv-btn vv-btn-primary vv-btn-small">
                        Save review
                    </button>
                    <a href="customer-orders.php#order-<?php echo (int)$itemRow['order_id']; ?>">
                        Cancel and go back
                    </a>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Mobile nav
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

    // Rating stars (click + keyboard)
    const wrapper = document.getElementById('ratingStars');
    const input   = document.getElementById('rating-input');
    if (!wrapper || !input) return;

    const stars = Array.from(wrapper.querySelectorAll('.star'));

    function setRating(val, focusStar = true) {
        val = Math.max(1, Math.min(5, parseInt(val, 10) || 5));
        input.value = String(val);

        stars.forEach((s, idx) => {
            const active = (idx + 1) <= val;
            s.classList.toggle('active', active);
            s.setAttribute('aria-checked', (idx + 1) === val ? 'true' : 'false');
            s.setAttribute('tabindex', (idx + 1) === val ? '0' : '-1');
        });

        if (focusStar) {
            const target = stars[val - 1];
            if (target) target.focus();
        }
    }

    // Click
    stars.forEach((star) => {
        star.addEventListener('click', function () {
            setRating(this.getAttribute('data-value'), false);
        });
    });

    // Keyboard navigation
    wrapper.addEventListener('keydown', function (e) {
        const current = parseInt(input.value, 10) || 5;

        if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
            e.preventDefault();
            setRating(current + 1);
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
            e.preventDefault();
            setRating(current - 1);
        } else if (e.key === 'Home') {
            e.preventDefault();
            setRating(1);
        } else if (e.key === 'End') {
            e.preventDefault();
            setRating(5);
        } else if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            // keep current selection (already set)
        }
    });
});
</script>

</body>
</html>
