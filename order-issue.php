<?php
// order-issue.php – Submit a complaint / issue for an order item (with image upload)
// Modern + responsive + mobile menu + image fallback
session_start();
require_once 'db.php';

if (empty($_SESSION['customer_id'])) {
    $_SESSION['customer_login_notice'] = 'Please sign in to report an issue.';
    $redirect = 'customer-orders.php';
    header('Location: customer-login.php?redirect=' . urlencode($redirect));
    exit;
}

$customer_id   = (int)$_SESSION['customer_id'];
$customer_name = $_SESSION['customer_name'] ?? '';

function clean_str($v) { return trim($v ?? ''); }

$error      = '';
$order_id   = 0;
$item_id    = 0;
$itemRow    = null;

// ---------- Handle POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id   = (int)($_POST['order_id'] ?? 0);
    $item_id    = (int)($_POST['order_item_id'] ?? 0);
    $issue_type = clean_str($_POST['issue_type'] ?? 'other');
    $message    = clean_str($_POST['message'] ?? '');

    if ($order_id <= 0 || $item_id <= 0) {
        $error = 'Missing order or item.';
    } elseif ($message === '') {
        $error = 'Please describe the issue before submitting.';
    }

    // verify that order item belongs to this customer
    if (!$error) {
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
                o.created_at
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE oi.id = ?
              AND oi.order_id = ?
              AND o.customer_id = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iii', $item_id, $order_id, $customer_id);
        $stmt->execute();
        $res     = $stmt->get_result();
        $itemRow = $res->fetch_assoc();
        $stmt->close();

        if (!$itemRow) {
            $error = 'We could not verify this order item for a complaint.';
        }
    }

    $attachmentPath = null;

    // Handle image upload (optional)
    if (!$error && isset($_FILES['attachment']) && is_array($_FILES['attachment'])) {
        if ($_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['attachment']['tmp_name'];
            $orig    = $_FILES['attachment']['name'];

            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowedExts = ['jpg','jpeg','png','gif','webp'];
            if (!in_array($ext, $allowedExts, true)) {
                $error = 'Unsupported file type. Please upload JPG, PNG, GIF or WEBP.';
            } else {
                $uploadDir = __DIR__ . '/uploads/complaints';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0775, true);
                }
                if (is_dir($uploadDir) && is_writable($uploadDir)) {
                    $fileName = 'compl_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
                    $destPath = $uploadDir . '/' . $fileName;
                    if (move_uploaded_file($tmpName, $destPath)) {
                        $attachmentPath = 'uploads/complaints/' . $fileName; // relative path for HTML
                    } else {
                        $error = 'Could not move uploaded file.';
                    }
                } else {
                    $error = 'Upload folder is not writable.';
                }
            }
        } elseif ($_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $error = 'There was a problem uploading the file.';
        }
    }

    if (!$error && $itemRow) {
        $niceType = ucwords(str_replace('_', ' ', $issue_type));
        $subject  = $niceType . ' - ' . $itemRow['product_name'];

        $fullMessage = "Issue type: {$niceType}\n"
                     . "Product ID: {$itemRow['product_id']}\n"
                     . "Order ID: {$order_id}\n\n"
                     . $message;

        $sql = "
            INSERT INTO complaints
            (customer_id, order_id, subject, message, status, attachment_path)
            VALUES (?, ?, ?, ?, 'open', ?)
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iisss', $customer_id, $order_id, $subject, $fullMessage, $attachmentPath);
        $stmt->execute();
        $stmt->close();

        $_SESSION['customer_orders_flash'] = 'Your request has been submitted. We will get back to you soon.';
        header('Location: customer-orders.php#order-' . $order_id);
        exit;
    }
}

// ---------- Handle GET (or POST with error) ----------
if (!$itemRow) {
    $order_id = $order_id ?: (int)($_GET['order_id'] ?? 0);
    // supports both item_id and order_item_id
    $item_id  = $item_id ?: (int)($_GET['item_id'] ?? ($_GET['order_item_id'] ?? 0));

    if ($order_id <= 0 || $item_id <= 0) {
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
            p.image_url
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        INNER JOIN products p ON p.id = oi.product_id
        WHERE oi.id = ?
          AND oi.order_id = ?
          AND o.customer_id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $item_id, $order_id, $customer_id);
    $stmt->execute();
    $res     = $stmt->get_result();
    $itemRow = $res->fetch_assoc();
    $stmt->close();

    if (!$itemRow) {
        http_response_code(404);
        echo "Order item not found.";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Report issue | Velvet Vogue</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="css/style.css">

    <!-- Image fallback helpers -->
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

        /* Keep primary button text visible on hover */
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

        /* ===== Mobile menu LEFT ===== */
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

        .vv-page-main { padding: 1.8rem 0 2.2rem; }

        .vv-issue-card {
            max-width: 640px;
            margin: 0 auto;
            background: #fff;
            border-radius: 20px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 1rem 1.2rem 1.2rem;
        }

        .vv-issue-header { display: flex; gap: 0.8rem; margin-bottom: 0.8rem; }
        .vv-issue-thumb {
            flex: 0 0 80px;
            border-radius: 14px;
            overflow: hidden;
            background: #f4f3fb;
            border: 1px solid var(--vv-border-soft);
        }
        .vv-issue-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .vv-issue-thumb img.vv-img-fallback{
            object-fit: contain !important;
        }

        .vv-issue-product-name { font-size: 0.96rem; font-weight: 600; margin-bottom: 0.1rem; }
        .vv-issue-meta { font-size: 0.8rem; color: var(--vv-text-soft); }

        .vv-alert-error {
            padding: 0.5rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            margin-bottom: 0.7rem;
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .vv-form-row { margin-bottom: 0.65rem; font-size: 0.86rem; }
        .vv-form-row label { display: block; font-size: 0.8rem; font-weight: 500; margin-bottom: 0.15rem; }

        .vv-form-row select,
        .vv-form-row textarea,
        .vv-form-row input[type="file"] { width: 100%; font-size: 0.86rem; }

        .vv-form-row select {
            border-radius: 12px;
            border: 1px solid var(--vv-border-strong);
            padding: 0.35rem 0.7rem;
            background:#fff;
        }
        .vv-form-row textarea {
            border-radius: 12px;
            border: 1px solid var(--vv-border-strong);
            padding: 0.4rem 0.7rem;
            min-height: 90px;
            resize: vertical;
            background:#fff;
        }
        .vv-form-row input[type="file"] { font-size: 0.8rem; }

        .vv-form-hint { font-size: 0.75rem; color: var(--vv-text-soft); margin-top: 0.1rem; }

        .vv-form-actions {
            margin-top: 0.6rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.8rem;
            flex-wrap: wrap;
        }
        .vv-form-actions a{ color: var(--vv-accent); text-decoration:none; }
        .vv-form-actions a:hover{ text-decoration:underline; }

        @media (max-width: 575.98px){
            .vv-issue-header{ align-items:flex-start; }
            .vv-issue-thumb{ flex:0 0 72px; }
        }
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
            <a href="customer-orders.php" class="active">My orders</a>
        </nav>

        <!-- Mobile nav -->
        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="customer-orders.php" class="active">My orders</a>
            <a href="wishlist.php">Wishlist</a>
            <a href="customer-profile.php">My profile</a>
        </div>

        <div class="vv-nav-actions">
            <a href="customer-orders.php#order-<?php echo (int)$itemRow['order_id']; ?>" class="vv-pill-link">
                <i class="bx bx-arrow-back"></i> Back to orders
            </a>
        </div>
    </div>
</header>

<main class="vv-page-main">
    <div class="vv-container">
        <div class="vv-issue-card">
            <h1 style="font-size:1.2rem;margin-bottom:0.4rem;">Report an issue</h1>

            <div class="vv-issue-header">
                <div class="vv-issue-thumb">
                    <?php if (!empty($itemRow['image_url'])): ?>
                        <img
                            src="<?php echo htmlspecialchars($itemRow['image_url']); ?>"
                            alt="<?php echo htmlspecialchars($itemRow['product_name']); ?>"
                            data-fallback-text="<?php echo htmlspecialchars($itemRow['product_name']); ?>"
                            onerror="vvImgFallback(this)"
                        >
                    <?php else: ?>
                        <img
                            src="data:image/gif;base64,R0lGODlhAQABAAAAACw="
                            alt="<?php echo htmlspecialchars($itemRow['product_name']); ?>"
                            data-fallback-text="<?php echo htmlspecialchars($itemRow['product_name']); ?>"
                            onerror="vvImgFallback(this)"
                        >
                    <?php endif; ?>
                </div>
                <div>
                    <div class="vv-issue-product-name">
                        <?php echo htmlspecialchars($itemRow['product_name']); ?>
                    </div>
                    <div class="vv-issue-meta">
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
                <div class="vv-alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="order_id" value="<?php echo (int)$itemRow['order_id']; ?>">
                <input type="hidden" name="order_item_id" value="<?php echo (int)$itemRow['id']; ?>">

                <div class="vv-form-row">
                    <label for="issue_type">Issue type</label>
                    <select id="issue_type" name="issue_type" required>
                        <?php
                          $prevType = clean_str($_POST['issue_type'] ?? 'refund_request');
                          $opts = [
                            'refund_request' => 'Refund request',
                            'size_issue'     => 'Size / fit issue',
                            'damaged_item'   => 'Damaged or defective item',
                            'delivery_issue' => 'Delivery / courier issue',
                            'other'          => 'Other'
                          ];
                          foreach ($opts as $val => $label) {
                            $sel = ($prevType === $val) ? 'selected' : '';
                            echo '<option value="'.htmlspecialchars($val).'" '.$sel.'>'.htmlspecialchars($label).'</option>';
                          }
                        ?>
                    </select>
                </div>

                <div class="vv-form-row">
                    <label for="message">Describe the issue</label>
                    <textarea id="message"
                              name="message"
                              placeholder="Tell us what went wrong and what you’d like us to do."
                              required><?php echo isset($_POST['message']) ? htmlspecialchars($_POST['message']) : ''; ?></textarea>
                    <div class="vv-form-hint">
                        Please include details like when you noticed the issue, and whether you prefer a refund, exchange or repair.
                    </div>
                </div>

                <div class="vv-form-row">
                    <label for="attachment">Attach a photo (optional)</label>
                    <input type="file" id="attachment" name="attachment" accept="image/*">
                    <div class="vv-form-hint">
                        JPG, PNG, GIF or WEBP. Max a few MB (depending on your server limits).
                    </div>
                </div>

                <div class="vv-form-actions">
                    <button type="submit" class="vv-btn vv-btn-secondary vv-btn-small">
                        Submit issue
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
});
</script>

</body>
</html>
