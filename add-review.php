<?php
// add-review.php – write / edit review for a single order item
session_start();
require_once 'db.php';

if (empty($_SESSION['customer_id'])) {
    $_SESSION['customer_login_notice'] = 'Please sign in to write a review.';
    $redirect = 'customer-orders.php';
    header('Location: customer-login.php?redirect=' . urlencode($redirect));
    exit;
}

$customer_id   = (int)$_SESSION['customer_id'];
$customer_name = $_SESSION['customer_name'] ?? '';

function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}
function clean_str($v) {
    return trim($v ?? '');
}

// header counters
$wishlist_ids   = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist']) ? $_SESSION['wishlist'] : [];
$wishlist_count = count($wishlist_ids);

$cart_items = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        if (is_array($item)) {
            $cart_items += (int)($item['qty'] ?? 0);
        } else {
            $cart_items += (int)$item;
        }
    }
}

// read identifiers
$order_id      = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$order_item_id = (int)($_GET['order_item_id'] ?? $_POST['order_item_id'] ?? 0);
$product_id    = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);

if ($order_id <= 0 || $order_item_id <= 0 || $product_id <= 0) {
    http_response_code(400);
    echo "Invalid review request.";
    exit;
}

// load order + item + product, ensure owned by this customer and completed
$sql = "
    SELECT
        o.id AS order_id,
        o.status,
        o.created_at,
        oi.id AS order_item_id,
        oi.product_id,
        oi.product_name,
        oi.size,
        oi.color,
        oi.quantity,
        oi.unit_price,
        p.image_url,
        p.collection
    FROM orders o
    INNER JOIN order_items oi ON oi.order_id = o.id
    INNER JOIN products p ON p.id = oi.product_id
    WHERE o.id = ?
      AND oi.id = ?
      AND oi.product_id = ?
      AND o.customer_id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Database error.');
}
$stmt->bind_param('iiii', $order_id, $order_item_id, $product_id, $customer_id);
$stmt->execute();
$res = $stmt->get_result();
$itemRow = $res->fetch_assoc();
$stmt->close();

if (!$itemRow) {
    http_response_code(404);
    echo "Order item not found.";
    exit;
}

if (strtolower($itemRow['status']) !== 'completed') {
    http_response_code(403);
    echo "You can only review completed orders.";
    exit;
}

// load existing review if any
$currentRating = 5;
$currentComment = '';
$existingReviewId = null;

if ($customer_name !== '') {
    $sql = "
        SELECT id, rating, comment
        FROM product_reviews
        WHERE product_id = ?
          AND customer_name = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('is', $product_id, $customer_name);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($rev = $res->fetch_assoc()) {
            $existingReviewId = (int)$rev['id'];
            $currentRating    = (int)$rev['rating'];
            $currentComment   = $rev['comment'];
        }
        $stmt->close();
    }
}

// handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_review'])) {
    $rating  = (int)($_POST['rating'] ?? 0);
    $comment = clean_str($_POST['comment'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $rating = 5;
    }

    if ($comment === '' || $customer_name === '') {
        $_SESSION['customer_orders_flash'] = 'Please provide a rating and a short review.';
        header('Location: customer-orders.php#order-' . $order_id);
        exit;
    }

    if ($existingReviewId) {
        $sql = "UPDATE product_reviews SET rating = ?, comment = ?, created_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('isi', $rating, $comment, $existingReviewId);
            $stmt->execute();
            $stmt->close();
        }
        $_SESSION['customer_orders_flash'] = 'Your review has been updated.';
    } else {
        $sql = "INSERT INTO product_reviews (product_id, customer_name, rating, comment, created_at)
                VALUES (?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('isis', $product_id, $customer_name, $rating, $comment);
            $stmt->execute();
            $stmt->close();
        }
        $_SESSION['customer_orders_flash'] = 'Thank you, your review has been added.';
    }

    header('Location: customer-orders.php#order-' . $order_id);
    exit;
}

$customer_full_name = $_SESSION['customer_name'] ?? '';
$firstName = '';
if ($customer_full_name) {
    $parts = explode(' ', $customer_full_name);
    $firstName = $parts[0];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $existingReviewId ? 'Edit review' : 'Add review'; ?> | Velvet Vogue</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="css/style.css">

    <style>
        .vv-page-main { padding: 1.8rem 0 2.2rem; }
        .vv-page-header { margin-bottom: 1.2rem; }
        .vv-page-header h1 { margin-bottom: 0.25rem; }
        .vv-page-sub { font-size: 0.9rem; color: var(--vv-text-soft); }

        .vv-card {
            background: #fff;
            border-radius: 20px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 1rem 1.2rem 1.2rem;
            max-width: 640px;
            margin: 0 auto;
        }

        .vv-product-row {
            display: flex;
            gap: 0.9rem;
            margin-bottom: 0.9rem;
        }
        .vv-product-thumb {
            flex: 0 0 90px;
            border-radius: 16px;
            overflow: hidden;
            background: #f4f3fb;
        }
        .vv-product-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .vv-product-info {
            font-size: 0.85rem;
        }
        .vv-product-info h2 {
            font-size: 0.96rem;
            margin: 0 0 0.2rem;
        }
        .vv-product-meta {
            font-size: 0.8rem;
            color: var(--vv-text-soft);
        }
        .vv-product-meta span + span::before {
            content: "·";
            margin: 0 0.3rem;
        }

        .vv-form-row {
            margin-top: 0.7rem;
        }
        .vv-form-row label {
            display: block;
            font-size: 0.82rem;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        .vv-form-row textarea {
            width: 100%;
            border-radius: 12px;
            border: 1px solid var(--vv-border-strong);
            padding: 0.45rem 0.75rem;
            font-size: 0.84rem;
            min-height: 80px;
            resize: vertical;
        }

        .vv-star-rating {
            display: inline-flex;
            gap: 0.15rem;
            font-size: 1.3rem;
        }
        .vv-star-btn {
            border: none;
            background: none;
            padding: 0;
            cursor: pointer;
            line-height: 1;
        }
        .vv-star-btn i {
            font-size: 1.4rem;
            color: #d1c5ff;
        }
        .vv-star-btn.active i {
            color: #f9a825;
        }

        .vv-form-actions {
            margin-top: 0.9rem;
            display: flex;
            justify-content: flex-start;
            gap: 0.5rem;
            flex-wrap: wrap;
            font-size: 0.85rem;
        }
        .vv-btn-sm {
            padding: 0.35rem 0.8rem;
            border-radius: 999px;
            font-size: 0.82rem;
        }
    </style>
</head>
<body class="vv-body">

<header class="vv-header">
    <div class="vv-container vv-nav-wrapper">
        <a href="index.php" class="vv-logo" style="text-decoration:none;color:inherit;">
            <span class="vv-logo-main">Velvet</span>
            <span class="vv-logo-sub">Vogue</span>
        </a>

        <nav class="vv-nav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="wishlist.php">Wishlist</a>
            <a href="customer-orders.php" class="active">My orders</a>
        </nav>

        <div class="vv-nav-actions">
            <a href="wishlist.php" class="vv-icon-btn" aria-label="Wishlist">
                <i class="bx bx-heart"></i>
                <?php if ($wishlist_count > 0): ?>
                    <span class="vv-count-badge"><?php echo $wishlist_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="cart.php" class="vv-icon-btn" aria-label="Cart">
                <i class="bx bx-shopping-bag"></i>
                <?php if ($cart_items > 0): ?>
                    <span class="vv-count-badge"><?php echo $cart_items; ?></span>
                <?php endif; ?>
            </a>

            <div class="vv-account-menu">
                <button type="button" class="vv-pill-link vv-account-toggle">
                    <i class="bx bx-user-circle"></i>
                    <span><?php echo htmlspecialchars($firstName ?: 'My account'); ?></span>
                    <i class="bx bx-chevron-down"></i>
                </button>
                <div class="vv-account-dropdown">
                    <a href="customer-profile.php">My profile</a>
                    <a href="customer-orders.php">My orders</a>
                    <a href="wishlist.php">Wishlist</a>
                    <form method="post" action="customer-logout.php">
                        <button type="submit">Sign out</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="vv-page-main">
    <div class="vv-container">
        <div class="vv-page-header">
            <h1><?php echo $existingReviewId ? 'Edit your review' : 'Add a review'; ?></h1>
            <p class="vv-page-sub">
                Order #<?php echo (int)$itemRow['order_id']; ?> · Placed on <?php echo htmlspecialchars($itemRow['created_at']); ?>
            </p>
        </div>

        <div class="vv-card">
            <div class="vv-product-row">
                <div class="vv-product-thumb">
                    <a href="product-details.php?id=<?php echo (int)$itemRow['product_id']; ?>">
                        <?php if (!empty($itemRow['image_url'])): ?>
                            <img src="<?php echo htmlspecialchars($itemRow['image_url']); ?>"
                                 alt="<?php echo htmlspecialchars($itemRow['product_name']); ?>">
                        <?php else: ?>
                            <img src="https://via.placeholder.com/90x90?text=VV"
                                 alt="<?php echo htmlspecialchars($itemRow['product_name']); ?>">
                        <?php endif; ?>
                    </a>
                </div>
                <div class="vv-product-info">
                    <h2>
                        <a href="product-details.php?id=<?php echo (int)$itemRow['product_id']; ?>" style="text-decoration:none;color:inherit;">
                            <?php echo htmlspecialchars($itemRow['product_name']); ?>
                        </a>
                    </h2>
                    <div class="vv-product-meta">
                        <span>Qty: <?php echo (int)$itemRow['quantity']; ?></span>
                        <?php if ($itemRow['color']): ?>
                            <span>Color: <?php echo htmlspecialchars($itemRow['color']); ?></span>
                        <?php endif; ?>
                        <?php if ($itemRow['size']): ?>
                            <span>Size: <?php echo htmlspecialchars($itemRow['size']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:0.82rem;margin-top:0.2rem;">
                        Unit price: <?php echo money_fmt($itemRow['unit_price']); ?>
                    </div>
                </div>
            </div>

            <form method="post">
                <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                <input type="hidden" name="order_item_id" value="<?php echo $order_item_id; ?>">
                <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
                <input type="hidden" name="rating" id="ratingValue" value="<?php echo (int)$currentRating; ?>">

                <div class="vv-form-row">
                    <label>Rating</label>
                    <div class="vv-star-rating" data-current="<?php echo (int)$currentRating; ?>">
                        <?php for ($r = 1; $r <= 5; $r++): ?>
                            <button type="button"
                                    class="vv-star-btn <?php echo $r <= $currentRating ? 'active' : ''; ?>"
                                    data-value="<?php echo $r; ?>">
                                <i class="bx bxs-star"></i>
                            </button>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="vv-form-row">
                    <label for="comment">Your review</label>
                    <textarea id="comment" name="comment" required><?php echo htmlspecialchars($currentComment); ?></textarea>
                </div>

                <div class="vv-form-actions">
                    <button type="submit"
                            name="save_review"
                            class="vv-btn vv-btn-primary vv-btn-sm">
                        Save review
                    </button>
                    <a href="customer-orders.php#order-<?php echo $order_id; ?>"
                       class="vv-btn vv-btn-outline vv-btn-sm">
                        Back to my orders
                    </a>
                </div>
            </form>
        </div>
    </div>
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
                <li><a href="contactsupport.php">Contact support</a></li>
                <li><a href="#">Shipping &amp; returns</a></li>
                <li><a href="#">Size guide</a></li>
            </ul>
        </div>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // account dropdown
    var toggle   = document.querySelector('.vv-account-toggle');
    var dropdown = document.querySelector('.vv-account-dropdown');
    if (toggle && dropdown) {
        toggle.addEventListener('click', function () {
            dropdown.classList.toggle('open');
        });
        document.addEventListener('click', function (e) {
            if (!toggle.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.remove('open');
            }
        });
    }

    // star rating
    var starContainer = document.querySelector('.vv-star-rating');
    if (starContainer) {
        var stars = starContainer.querySelectorAll('.vv-star-btn');
        var hiddenInput = document.getElementById('ratingValue');
        function setRating(val) {
            stars.forEach(function (btn) {
                var v = parseInt(btn.getAttribute('data-value'), 10);
                if (v <= val) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            if (hiddenInput) hiddenInput.value = val;
        }
        stars.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var v = parseInt(this.getAttribute('data-value'), 10);
                setRating(v);
            });
        });
    }
});
</script>

</body>
</html>
