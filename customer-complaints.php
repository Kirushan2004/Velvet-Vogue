<?php
// customer-complaints.php – Customer complaints list + image preview
// Modern + responsive + mobile menu + image fallback
session_start();
require_once 'db.php';

// ---------- Require login ----------
if (empty($_SESSION['customer_id'])) {
    $_SESSION['customer_login_notice'] = 'Please sign in to view your complaints.';
    $redirect = 'customer-complaints.php';
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

/**
 * Parse the stored complaint message, which was saved like:
 *  Issue type: Size Issue
 *  Product ID: 3
 *  Order ID: 1
 *
 *  it's wrong size
 */
function parse_complaint_message($raw) {
    $info = [
        'issue_type' => null,
        'product_id' => null,
        'order_id'   => null,
        'details'    => trim($raw),
    ];

    if (preg_match('/Issue type:\s*(.+)/i', $raw, $m)) {
        $info['issue_type'] = trim($m[1]);
    }
    if (preg_match('/Product ID:\s*(\d+)/i', $raw, $m)) {
        $info['product_id'] = (int)$m[1];
    }
    if (preg_match('/Order ID:\s*(\d+)/i', $raw, $m)) {
        $info['order_id'] = (int)$m[1];
    }

    // Remove the header lines to get the customer-written part
    $clean = preg_replace('/^Issue type:.*$/mi', '', $raw);
    $clean = preg_replace('/^Product ID:.*$/mi', '', $clean);
    $clean = preg_replace('/^Order ID:.*$/mi', '', $clean);
    $clean = trim($clean);

    if ($clean !== '') {
        $info['details'] = $clean;
    }

    return $info;
}

function complaint_status_label($status) {
    switch ($status) {
        case 'open':        return 'Open';
        case 'in_progress': return 'In progress';
        case 'resolved':    return 'Resolved';
        default:            return ucfirst($status);
    }
}

// ---------- Header counts ----------
$wishlist_ids   = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])
    ? $_SESSION['wishlist']
    : [];
$wishlist_count = count($wishlist_ids);

// ✅ Use SAME cart bubble logic as other pages: total quantity (supports array-based entries)
$cart_items = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $entry) {
        if (is_array($entry)) {
            $cart_items += (int)($entry['qty'] ?? 0);
        } else {
            $cart_items += (int)$entry;
        }
    }
}

// ---------- Load complaints for this customer ----------
$complaints   = [];
$productIds   = [];

$sql = "
    SELECT
        c.*,
        o.order_number,
        o.created_at AS order_created_at
    FROM complaints c
    LEFT JOIN orders o ON o.id = c.order_id
    WHERE c.customer_id = ?
    ORDER BY c.created_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $customer_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $parsed = parse_complaint_message($row['message'] ?? '');

    $row['parsed_issue_type'] = $parsed['issue_type'];
    $row['parsed_product_id'] = $parsed['product_id'];
    $row['parsed_order_id']   = $parsed['order_id'];
    $row['parsed_details']    = $parsed['details'];

    if (!empty($parsed['product_id'])) {
        $productIds[] = (int)$parsed['product_id'];
    }

    $complaints[] = $row;
}
$stmt->close();

// ---------- Load product info for referenced product IDs ----------
$productMap = [];
if (!empty($productIds)) {
    $productIds = array_unique(array_filter($productIds));
    if (!empty($productIds)) {
        $in = implode(',', $productIds);
        $sql = "
            SELECT id, name, image_url, collection
            FROM products
            WHERE id IN ($in)
        ";
        if ($result = $conn->query($sql)) {
            while ($p = $result->fetch_assoc()) {
                $productMap[(int)$p['id']] = $p;
            }
            $result->free();
        }
    }
}

// ---------- Small helpers for badges ----------
function complaint_status_classes($status) {
    $status = strtolower($status);
    switch ($status) {
        case 'open':
            return 'vv-complaint-status-open';
        case 'in_progress':
            return 'vv-complaint-status-progress';
        case 'resolved':
            return 'vv-complaint-status-resolved';
        default:
            return 'vv-complaint-status-open';
    }
}

// First name for header pill
$firstName = '';
if ($customer_name) {
    $parts = preg_split('/\s+/', trim($customer_name));
    $firstName = $parts[0] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My complaints | Velvet Vogue</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Boxicons -->
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <!-- Bootstrap only for modal (same as admin preview) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

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
        .vv-btn.vv-btn-primary:hover{
            color:#fff !important;
            background-color: var(--vv-accent) !important;
            border-color: var(--vv-accent) !important;
            box-shadow: 0 10px 26px rgba(25,12,64,0.18) !important;
            transform: translateY(-1px);
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

        .vv-complaints-main { padding: 1.8rem 0 2.2rem; }
        .vv-complaints-header { margin-bottom: 1.4rem; }
        .vv-complaints-header h2 { margin-bottom: 0.25rem; }
        .vv-complaints-summary { font-size: 0.9rem; color: var(--vv-text-soft); }

        .vv-complaint-card {
            background: #fff;
            border-radius: 20px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.9rem 1.1rem 1rem;
            margin-bottom: 1rem;
        }

        .vv-complaint-header-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .vv-complaint-title { font-size: 0.9rem; font-weight: 500; }
        .vv-complaint-meta { font-size: 0.8rem; color: var(--vv-text-soft); }
        .vv-complaint-meta span + span::before {
            content: "·";
            margin: 0 0.3rem;
            color: #c0b7d4;
        }

        .vv-complaint-status-badge {
            padding: 0.18rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .vv-complaint-status-badge span.dot {
            width: 7px;
            height: 7px;
            border-radius: 999px;
            display: inline-block;
        }
        .vv-complaint-status-open { background: #fff7e0; color: #8d6b1f; }
        .vv-complaint-status-open span.dot { background: #fbc02d; }

        .vv-complaint-status-progress { background: #e3f2fd; color: #1e88e5; }
        .vv-complaint-status-progress span.dot { background: #1e88e5; }

        .vv-complaint-status-resolved { background: #e8f5e9; color: #2e7d32; }
        .vv-complaint-status-resolved span.dot { background: #43a047; }

        .vv-complaint-body {
            display: grid;
            grid-template-columns: minmax(0, 1.3fr) minmax(0, 0.9fr);
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .vv-complaint-info-block { font-size: 0.82rem; }
        .vv-complaint-info-row {
            display: flex;
            align-items: baseline;
            gap: 0.4rem;
            margin-bottom: 0.25rem;
        }
        .vv-complaint-info-label {
            font-weight: 500;
            color: var(--vv-text-main);
            min-width: 90px;
            font-size: 0.8rem;
        }
        .vv-complaint-info-value { color: var(--vv-text-soft); }

        .vv-complaint-message-box {
            margin-top: 0.35rem;
            padding: 0.5rem 0.7rem;
            border-radius: 14px;
            background: #fbf8ff;
            border: 1px dashed var(--vv-border-soft);
        }
        .vv-complaint-message-title { font-size: 0.8rem; font-weight: 500; margin-bottom: 0.2rem; }
        .vv-complaint-message-text { font-size: 0.8rem; color: var(--vv-text-main); white-space: pre-wrap; }

        .vv-complaint-admin-box {
            margin-top: 0.4rem;
            padding: 0.5rem 0.7rem;
            border-radius: 14px;
            background: #f4fbf7;
            border: 1px dashed #c8e6c9;
        }
        .vv-complaint-admin-title {
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 0.15rem;
            color: #2e7d32;
        }
        .vv-complaint-admin-text { font-size: 0.8rem; color: #2e7d32; white-space: pre-wrap; }
        .vv-complaint-admin-empty { font-size: 0.8rem; color: var(--vv-text-soft); }

        .vv-complaint-right { font-size: 0.8rem; }

        .vv-complaint-image-wrapper {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 0.5rem;
        }

        .vv-complaint-thumb {
            width: 52px;
            height: 52px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid var(--vv-border-soft);
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            flex-shrink: 0;
            background:#f4f3fb;
        }
        .vv-complaint-thumb:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.12);
        }
        .vv-complaint-thumb.vv-img-fallback{
            object-fit: contain !important;
        }
        .vv-complaint-image-text { color: var(--vv-text-soft); }

        .vv-complaint-links {
            margin-top: 0.4rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.3rem 0.6rem;
        }
        .vv-complaint-links a { font-size: 0.78rem; color: var(--vv-accent, #6b4de6); text-decoration:none; }
        .vv-complaint-links a:hover{ text-decoration:underline; }

        .vv-complaint-timeline {
            margin-top: 0.35rem;
            font-size: 0.78rem;
            color: var(--vv-text-soft);
        }
        .vv-complaint-timeline span + span::before {
            content: "·";
            margin: 0 0.3rem;
            color: #c0b7d4;
        }

        @media (max-width: 767.98px) {
            .vv-complaint-header-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.3rem;
            }
            .vv-complaint-body { grid-template-columns: minmax(0, 1fr); }
        }

        /* ======= preview modal style ======= */
        .image-preview-modal .modal-dialog { max-width: 540px; }
        .image-preview-modal-content { border-radius: 18px; overflow: hidden; border: none; }
        .image-preview-body { padding: 0.75rem; background: #ffffff; }
        .image-preview-img {
            display: block;
            width: 100%;
            height: auto;
            max-height: 70vh;
            object-fit: contain;
        }
        .image-preview-close { position: absolute; top: 0.35rem; right: 0.5rem; z-index: 5; }
    </style>
</head>

<body class="vv-body">

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

        <nav class="vv-nav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="wishlist.php">Wishlist</a>
            <a href="customer-orders.php">My orders</a>
            <a href="customer-complaints.php" class="active">My complaints</a>
        </nav>

        <!-- Mobile nav -->
        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="wishlist.php">Wishlist</a>
            <a href="customer-orders.php">My orders</a>
            <a href="customer-complaints.php" class="active">My complaints</a>
            <a href="customer-profile.php">My profile</a>
        </div>

        <div class="vv-nav-actions">
            <!-- wishlist icon with counter -->
            <a href="wishlist.php" class="vv-icon-btn" aria-label="Wishlist">
                <i class="bx bx-heart"></i>
                <?php if ($wishlist_count > 0): ?>
                    <span class="vv-count-badge" id="wishlistCount"><?php echo (int)$wishlist_count; ?></span>
                <?php endif; ?>
            </a>

            <!-- cart icon with counter -->
            <a href="cart.php" class="vv-icon-btn" aria-label="Cart">
                <i class="bx bx-shopping-bag"></i>
                <?php if ($cart_items > 0): ?>
                    <span class="vv-count-badge" id="cartCount"><?php echo (int)$cart_items; ?></span>
                <?php endif; ?>
            </a>

            <!-- account pill -->
            <div class="vv-account-menu">
                <button type="button" class="vv-pill-link vv-account-toggle">
                    <i class="bx bx-user-circle"></i>
                    <span><?php echo htmlspecialchars($firstName ?: 'My account'); ?></span>
                    <i class="bx bx-chevron-down"></i>
                </button>
                <div class="vv-account-dropdown">
                    <a href="customer-profile.php">My profile</a>
                    <a href="customer-orders.php">My orders</a>
                    <a href="customer-complaints.php" class="active">My complaints</a>
                    <a href="wishlist.php">Wishlist</a>
                    <form method="post" action="customer-logout.php">
                        <button type="submit">Sign out</button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</header>

<main class="vv-complaints-main">
    <div class="vv-container">
        <div class="vv-complaints-header">
            <h2>My complaints</h2>
            <p class="vv-complaints-summary">
                <?php echo count($complaints); ?> complaint(s) submitted
            </p>
        </div>

        <?php if (empty($complaints)): ?>
            <p class="text-muted">
                You haven’t raised any complaints yet.
                If something is wrong with an order, you can submit a complaint from
                the <a href="customer-orders.php">My orders</a> page.
            </p>
        <?php else: ?>
            <?php foreach ($complaints as $c): ?>
                <?php
                $cid          = (int)$c['id'];
                $status       = $c['status'] ?? 'open';
                $statusClass  = complaint_status_classes($status);
                $statusLabel  = complaint_status_label($status);
                $subject      = $c['subject'] ?: 'Order complaint';
                $createdAt    = $c['created_at'] ?? '';
                $updatedAt    = $c['updated_at'] ?? '';
                $orderId      = (int)($c['order_id'] ?? 0);
                $orderNumber  = $c['order_number'] ?: ($orderId ? ('#' . $orderId) : '—');
                $orderDate    = $c['order_created_at'] ?? '';
                $issueType    = $c['parsed_issue_type'] ?: 'Not specified';
                $parsedOrderId   = $c['parsed_order_id'];
                $parsedProductId = $c['parsed_product_id'];

                $productInfo = null;
                if ($parsedProductId && isset($productMap[$parsedProductId])) {
                    $productInfo = $productMap[$parsedProductId];
                }

                $details     = $c['parsed_details'] ?? '';
                $adminReply  = $c['admin_reply'] ?? '';
                $attachment  = $c['attachment_path'] ?? '';
                ?>
                <article class="vv-complaint-card" id="complaint-<?php echo $cid; ?>">
                    <div class="vv-complaint-header-row">
                        <div>
                            <div class="vv-complaint-title">
                                Complaint #<?php echo $cid; ?> – <?php echo htmlspecialchars($subject); ?>
                            </div>
                            <div class="vv-complaint-meta">
                                <span>Order: <?php echo htmlspecialchars($orderNumber); ?></span>
                                <?php if ($orderDate): ?>
                                    <span>Order date: <?php echo htmlspecialchars($orderDate); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <div class="vv-complaint-status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                <span class="dot"></span>
                                <?php echo htmlspecialchars($statusLabel); ?>
                            </div>
                        </div>
                    </div>

                    <div class="vv-complaint-body">
                        <!-- LEFT: structured details -->
                        <div class="vv-complaint-info-block">
                            <div class="vv-complaint-info-row">
                                <div class="vv-complaint-info-label">Issue type</div>
                                <div class="vv-complaint-info-value">
                                    <?php echo htmlspecialchars($issueType); ?>
                                </div>
                            </div>

                            <div class="vv-complaint-info-row">
                                <div class="vv-complaint-info-label">Product</div>
                                <div class="vv-complaint-info-value">
                                    <?php if ($productInfo): ?>
                                        <a href="product-details.php?id=<?php echo (int)$productInfo['id']; ?>">
                                            <?php echo htmlspecialchars($productInfo['name']); ?>
                                        </a>
                                        (ID: <?php echo (int)$productInfo['id']; ?>)
                                        <?php if (!empty($productInfo['collection'])): ?>
                                            – <?php echo htmlspecialchars($productInfo['collection']); ?> collection
                                        <?php endif; ?>
                                    <?php elseif ($parsedProductId): ?>
                                        Product ID: <?php echo (int)$parsedProductId; ?>
                                    <?php else: ?>
                                        Not specified
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="vv-complaint-info-row">
                                <div class="vv-complaint-info-label">Order</div>
                                <div class="vv-complaint-info-value">
                                    <?php if ($orderId): ?>
                                        <a href="customer-orders.php#order-<?php echo $orderId; ?>">
                                            <?php echo htmlspecialchars($orderNumber); ?>
                                        </a>
                                        <?php if ($parsedOrderId && $parsedOrderId !== $orderId): ?>
                                            (original ID in note: <?php echo (int)$parsedOrderId; ?>)
                                        <?php endif; ?>
                                    <?php else: ?>
                                        Not linked to order
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="vv-complaint-message-box">
                                <div class="vv-complaint-message-title">Your message</div>
                                <div class="vv-complaint-message-text">
                                    <?php echo nl2br(htmlspecialchars($details)); ?>
                                </div>
                            </div>

                            <div class="vv-complaint-admin-box">
                                <div class="vv-complaint-admin-title">Store reply</div>
                                <?php if ($adminReply): ?>
                                    <div class="vv-complaint-admin-text">
                                        <?php echo nl2br(htmlspecialchars($adminReply)); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="vv-complaint-admin-empty">
                                        We haven’t replied to this complaint yet. You’ll see updates here once our team responds.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="vv-complaint-timeline">
                                <span>Opened on <?php echo htmlspecialchars($createdAt); ?></span>
                                <?php if ($updatedAt && $updatedAt !== $createdAt): ?>
                                    <span>Last updated <?php echo htmlspecialchars($updatedAt); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- RIGHT: image + quick links -->
                        <div class="vv-complaint-right">
                            <?php if (!empty($attachment)): ?>
                                <div class="vv-complaint-image-wrapper">
                                    <img src="<?php echo htmlspecialchars($attachment); ?>"
                                         alt="Complaint attachment"
                                         class="vv-complaint-thumb js-complaint-image-thumb"
                                         data-full-src="<?php echo htmlspecialchars($attachment); ?>"
                                         data-fallback-text="Attachment"
                                         onerror="vvImgFallback(this)">
                                    <div class="vv-complaint-image-text">
                                        <div><strong>Attached photo</strong></div>
                                        <div>Click to view larger.</div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="vv-complaint-image-wrapper">
                                    <img src="images/complaint-placeholder.png"
                                         alt="No attachment"
                                         class="vv-complaint-thumb"
                                         data-fallback-text="No photo"
                                         onerror="vvImgFallback(this)">
                                    <div class="vv-complaint-image-text" style="margin-bottom:0.5rem;">
                                        <strong>No photo attached</strong><br>
                                        For future complaints you can attach a photo from the order page.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="vv-complaint-links">
                                <?php if ($productInfo): ?>
                                    <a href="product-details.php?id=<?php echo (int)$productInfo['id']; ?>">
                                        <i class="bx bx-link-external"></i> View product
                                    </a>
                                <?php endif; ?>

                                <?php if ($orderId): ?>
                                    <a href="customer-orders.php#order-<?php echo $orderId; ?>">
                                        <i class="bx bx-package"></i> View related order
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
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
                <li><a href="customer-complaints.php">My complaints</a></li>
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

<!-- MODAL: complaint image preview -->
<div class="modal fade image-preview-modal" id="complaintImageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content image-preview-modal-content">
            <button type="button" class="btn-close image-preview-close" aria-label="Close"></button>
            <div class="modal-body image-preview-body">
                <img id="complaintModalImage"
                     src=""
                     alt="Complaint image large preview"
                     class="img-fluid image-preview-img"
                     data-fallback-text="Attachment"
                     onerror="vvImgFallback(this)">
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS (for modal) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

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

    // Account dropdown
    var toggle = document.querySelector('.vv-account-toggle');
    var dropdown = document.querySelector('.vv-account-dropdown');
    if (toggle && dropdown) {
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            dropdown.classList.toggle('open');
        });
        document.addEventListener('click', function (e) {
            if (!toggle.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.remove('open');
            }
        });
    }

    // Complaint image preview modal
    const modalEl  = document.getElementById('complaintImageModal');
    const modalImg = document.getElementById('complaintModalImage');
    const closeBtn = modalEl ? modalEl.querySelector('.image-preview-close') : null;
    let modalInstance = null;

    if (modalEl) {
        modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
    }

    // Thumbnails -> open modal
    if (modalInstance && modalImg) {
        document.querySelectorAll('.js-complaint-image-thumb').forEach(function (thumb) {
            thumb.addEventListener('click', function () {
                const src = this.getAttribute('data-full-src') || this.src;
                if (!src) return;
                modalImg.dataset.fallbackApplied = "0";
                modalImg.src = src;
                modalInstance.show();
            });
        });
    }

    // Close button
    if (closeBtn && modalInstance) {
        closeBtn.addEventListener('click', function () {
            modalInstance.hide();
        });
    }
});
</script>

</body>
</html>
