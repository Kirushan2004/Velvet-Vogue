<?php
// customer-order-details.php (or whatever you name it)
// Modern + responsive + mobile menu + image fallback
session_start();
require_once 'db.php';

if (empty($_SESSION['customer_id'])) {
    header('Location: customer-login.php?redirect=customer-orders.php&msg=login_required');
    exit;
}

$customer_id = (int)$_SESSION['customer_id'];
$order_id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($order_id <= 0) {
    die('Invalid order.');
}

// ---------- Helpers ----------
function clean($v) { return trim($v ?? ''); }
function money_fmt($v) { return '$' . number_format((float)$v, 2); }

// Placeholder image (no extra file needed)
$placeholderSvg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="220" height="220" viewBox="0 0 220 220">
  <rect width="220" height="220" fill="#f4f3fb"/>
  <path d="M55 140l38-40 30 30 18-20 44 50H55z" fill="#d9d3ea"/>
  <circle cx="85" cy="85" r="11" fill="#d9d3ea"/>
  <text x="110" y="182" text-anchor="middle" font-family="Arial, sans-serif" font-size="12" fill="#b7b0c9">No image</text>
</svg>
SVG;
$placeholderDataUri = 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($placeholderSvg);

// ---------- Load order & ensure ownership ----------
$sql = "SELECT *
        FROM orders
        WHERE id = ? AND customer_id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $order_id, $customer_id);
$stmt->execute();
$res   = $stmt->get_result();
$order = $res->fetch_assoc();
$stmt->close();

if (!$order) {
    die('Order not found.');
}

$order_status = (string)($order['status'] ?? '');

// ---------- Load items ----------
$sql = "SELECT oi.*, p.image_url
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $order_id);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) $items[] = $row;
$stmt->close();

// ---------- Handle POST actions (review & complaint) ----------
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Save review only if delivered (kept your condition)
    if ($action === 'save_review' && $order_status === 'delivered') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $rating     = (int)($_POST['rating'] ?? 0);
        $review_txt = clean($_POST['review_text'] ?? '');

        if ($product_id <= 0) $errors[] = 'Invalid product for review.';
        elseif ($rating < 1 || $rating > 5) $errors[] = 'Rating must be between 1 and 5.';
        elseif ($review_txt === '') $errors[] = 'Review text is required.';

        if (empty($errors)) {
            // upsert review (one review per order+product+customer)
            $sql = "SELECT id FROM product_reviews
                    WHERE product_id = ? AND customer_id = ? AND order_id = ?
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iii', $product_id, $customer_id, $order_id);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();

            if ($exists) {
                $sql = "UPDATE product_reviews
                        SET rating = ?, review_text = ?, updated_at = NOW()
                        WHERE product_id = ? AND customer_id = ? AND order_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('isiii', $rating, $review_txt, $product_id, $customer_id, $order_id);
            } else {
                $sql = "INSERT INTO product_reviews
                            (product_id, customer_id, order_id, rating, review_text, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('iiiis', $product_id, $customer_id, $order_id, $rating, $review_txt);
            }

            if ($stmt && $stmt->execute()) $success = 'Your review has been saved.';
            else $errors[] = 'Could not save review. Please try again.';

            if ($stmt) $stmt->close();
        }
    }

    // Complaint
    if ($action === 'file_complaint') {
        $type    = clean($_POST['complaint_type'] ?? 'general');
        $subject = clean($_POST['complaint_subject'] ?? '');
        $message = clean($_POST['complaint_message'] ?? '');

        if ($subject === '') $errors[] = 'Complaint subject is required.';
        if ($message === '') $errors[] = 'Complaint message is required.';

        if (empty($errors)) {
            $sql = "INSERT INTO order_complaints
                        (order_id, customer_id, type, subject, message, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 'open', NOW(), NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iisss', $order_id, $customer_id, $type, $subject, $message);

            if ($stmt->execute()) $success = 'Your complaint has been submitted. We will review it soon.';
            else $errors[] = 'Could not submit complaint. Please try again.';

            $stmt->close();
        }
    }
}

// ---------- Existing reviews for this order ----------
$reviews = [];
$sql = "SELECT product_id, rating, review_text
        FROM product_reviews
        WHERE customer_id = ? AND order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $customer_id, $order_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $reviews[(int)$row['product_id']] = $row;
}
$stmt->close();

// ---------- Header helpers ----------
$customerName = $_SESSION['customer_name'] ?? '';
$firstName    = $customerName ? explode(' ', $customerName)[0] : 'Account';

// Optional: normalize status label
function status_label($s) { return $s ? ucfirst(str_replace('_', ' ', strtolower($s))) : '—'; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order #<?php echo $order_id; ?> | Velvet Vogue</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/admin-login.css">

    <style>
        /* Mobile nav toggle */
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
                z-index:50;
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
                display:flex;
                align-items:center;
                gap:0.45rem;
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

        /* Account dropdown (if your global CSS doesn’t already handle open) */
        .vv-account-menu{ position:relative; }
        .vv-account-dropdown{
            position:absolute;
            right:0;
            top:calc(100% + 0.45rem);
            min-width:190px;
            background:#fff;
            border:1px solid var(--vv-border-soft);
            border-radius:14px;
            box-shadow: var(--vv-shadow-subtle);
            padding:0.35rem 0;
            display:none;
            z-index:60;
        }
        .vv-account-menu.open .vv-account-dropdown{ display:block; }
        .vv-account-dropdown a{
            display:flex;
            align-items:center;
            gap:0.45rem;
            padding:0.45rem 0.85rem;
            text-decoration:none;
            color: var(--vv-text-main);
            font-size:0.85rem;
        }
        .vv-account-dropdown a:hover{ background:#f6f1ff; }

        /* Make “save review” button compact if vv-btn-xs not defined */
        .vv-btn-xs{
            padding:0.35rem 0.75rem !important;
            font-size:0.78rem !important;
            border-radius:999px !important;
        }

        /* Image fallback keeps layout stable */
        .vv-cart-thumb img{ display:block; width:100%; height:100%; object-fit:cover; }
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
            <a href="customer-orders.php">My orders</a>
        </nav>

        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php"><i class="bx bx-home"></i> Home</a>
            <a href="shop.php"><i class="bx bx-store"></i> Shop</a>
            <a href="customer-orders.php"><i class="bx bx-package"></i> My orders</a>
            <a href="customer-profile.php"><i class="bx bx-id-card"></i> My profile</a>
        </div>

        <div class="vv-nav-actions">
            <div class="vv-account-menu" id="vvAccountMenu">
                <button type="button" class="vv-pill-link vv-account-trigger" id="vvAccountTrigger">
                    <i class="bx bx-user-circle"></i>
                    <span><?php echo htmlspecialchars($firstName); ?></span>
                    <i class="bx bx-chevron-down vv-account-caret"></i>
                </button>
                <div class="vv-account-dropdown" id="vvAccountDropdown">
                    <a href="customer-profile.php"><i class="bx bx-id-card"></i> My profile</a>
                    <a href="customer-orders.php"><i class="bx bx-package"></i> My orders</a>
                    <a href="customer-logout.php"><i class="bx bx-log-out"></i> Log out</a>
                </div>
            </div>
        </div>
    </div>
</header>

<main style="padding:1.8rem 0 2.2rem;">
    <div class="vv-container">
        <a href="customer-orders.php" class="vv-link-reset" style="display:inline-flex;align-items:center;gap:0.3rem;margin-bottom:0.6rem;">
            <i class="bx bx-arrow-back"></i> Back to orders
        </a>

        <h2>Order #<?php echo $order_id; ?></h2>
        <p class="vv-section-sub" style="margin-bottom:0.8rem;">
            Placed on <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($order['order_date']))); ?> ·
            Status: <strong><?php echo htmlspecialchars(status_label($order['status'] ?? '')); ?></strong>
        </p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger py-2 px-3 small">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success py-2 px-3 small">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <div class="admin-login-card" style="padding:1rem 1.2rem;margin-bottom:1rem;">
            <div class="row g-3">
                <div class="col-md-6">
                    <h5 class="mb-2" style="font-size:0.95rem;">Shipping details</h5>
                    <p class="vv-body-text" style="margin-bottom:0;">
                        <?php echo htmlspecialchars($order['ship_name'] ?? $_SESSION['customer_name']); ?><br>
                        <?php echo htmlspecialchars($order['ship_phone'] ?? ''); ?><br>
                        <?php echo htmlspecialchars($order['ship_address1'] ?? ''); ?><br>
                        <?php if (!empty($order['ship_address2'])) echo htmlspecialchars($order['ship_address2']) . '<br>'; ?>
                        <?php echo htmlspecialchars(($order['ship_city'] ?? '') . ' ' . ($order['ship_state'] ?? '')); ?><br>
                        <?php echo htmlspecialchars(($order['ship_postal_code'] ?? '') . ' ' . ($order['ship_country'] ?? '')); ?>
                    </p>
                </div>
                <div class="col-md-6">
                    <h5 class="mb-2" style="font-size:0.95rem;">Payment summary</h5>
                    <p class="vv-body-text" style="margin-bottom:0.2rem;">
                        Method: <?php echo htmlspecialchars(ucfirst($order['payment_method'] ?? '')); ?><br>
                        Status: <?php echo htmlspecialchars(ucfirst($order['payment_status'] ?? '')); ?>
                    </p>
                    <p class="vv-body-text">
                        Total: <strong><?php echo money_fmt($order['total_amount'] ?? 0); ?></strong>
                    </p>
                </div>
            </div>
        </div>

        <h3 style="font-size:1.05rem;margin-bottom:0.5rem;">Items in this order</h3>

        <?php if (empty($items)): ?>
            <p class="text-muted">No items found for this order.</p>
        <?php else: ?>
            <?php foreach ($items as $it): ?>
                <?php
                    $pid = (int)$it['product_id'];
                    $rev = $reviews[$pid] ?? null;
                    $img = trim($it['image_url'] ?? '');
                    $imgSrc = $img !== '' ? $img : $placeholderDataUri;
                ?>
                <article class="vv-cart-item-card" style="margin-bottom:0.75rem;">
                    <div class="vv-cart-thumb">
                        <img
                            src="<?php echo htmlspecialchars($imgSrc); ?>"
                            alt="<?php echo htmlspecialchars($it['product_name'] ?? 'Product'); ?>"
                            onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($placeholderDataUri); ?>';"
                        >
                    </div>

                    <div class="vv-cart-body">
                        <div>
                            <h2 class="vv-cart-name" style="font-size:0.95rem;">
                                <?php echo htmlspecialchars($it['product_name']); ?>
                            </h2>
                            <p class="vv-cart-meta">
                                <?php if (!empty($it['color'])): ?>
                                    Color: <?php echo htmlspecialchars($it['color']); ?> ·
                                <?php endif; ?>
                                <?php if (!empty($it['size'])): ?>
                                    Size: <?php echo htmlspecialchars($it['size']); ?> ·
                                <?php endif; ?>
                                Qty: <?php echo (int)$it['quantity']; ?>
                            </p>
                            <div class="vv-cart-price-row">
                                <span class="vv-price-main">
                                    <?php echo money_fmt($it['unit_price']); ?>
                                </span>
                                <span class="vv-cart-line">
                                    Line total: <?php echo money_fmt(((float)$it['unit_price']) * ((int)$it['quantity'])); ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($order_status === 'delivered'): ?>
                            <div style="margin-top:0.5rem;">
                                <form method="post" class="small">
                                    <input type="hidden" name="action" value="save_review">
                                    <input type="hidden" name="product_id" value="<?php echo $pid; ?>">

                                    <div class="mb-1">
                                        <label class="form-label" style="font-size:0.78rem;margin-bottom:0.1rem;">
                                            Your rating
                                        </label>
                                        <select name="rating" class="form-select form-select-sm" style="max-width:160px;">
                                            <?php for ($r = 5; $r >= 1; $r--): ?>
                                                <option value="<?php echo $r; ?>"
                                                    <?php echo ($rev && (int)$rev['rating'] === $r) ? 'selected' : ''; ?>>
                                                    <?php echo $r; ?> star<?php echo $r > 1 ? 's' : ''; ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>

                                    <div class="mb-1">
                                        <label class="form-label" style="font-size:0.78rem;margin-bottom:0.1rem;">
                                            Your review
                                        </label>
                                        <textarea name="review_text" class="form-control form-control-sm"
                                                  rows="2"
                                                  placeholder="Share your thoughts about this product..."><?php
                                            echo $rev ? htmlspecialchars($rev['review_text']) : '';
                                        ?></textarea>
                                    </div>

                                    <button type="submit" class="vv-btn vv-btn-primary vv-btn-xs">
                                        Save review
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>

        <h3 style="font-size:1.05rem;margin-top:1.2rem;margin-bottom:0.4rem;">File a complaint / refund request</h3>
        <div class="admin-login-card" style="padding:0.9rem 1.1rem;max-width:720px;">
            <form method="post">
                <input type="hidden" name="action" value="file_complaint">

                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Type</label>
                        <select name="complaint_type" class="form-select form-select-sm">
                            <option value="general">General complaint</option>
                            <option value="refund">Refund request</option>
                        </select>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Subject</label>
                        <input type="text" name="complaint_subject" class="form-control form-control-sm"
                               placeholder="Short summary (e.g. Wrong size received)">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Details</label>
                        <textarea name="complaint_message"
                                  class="form-control form-control-sm"
                                  rows="3"
                                  placeholder="Describe the issue and what you would like us to do (e.g. refund, replacement, etc.)."></textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-2">
                    <button type="submit" class="vv-btn vv-btn-outline vv-btn-xs">
                        Submit complaint
                    </button>
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

    // Account dropdown
    const menu = document.getElementById('vvAccountMenu');
    const trigger = document.getElementById('vvAccountTrigger');
    if (menu && trigger) {
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            menu.classList.toggle('open');
        });
        document.addEventListener('click', function () {
            menu.classList.remove('open');
        });
    }
});
</script>

</body>
</html>
