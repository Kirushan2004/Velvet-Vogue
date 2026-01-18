<?php
// checkout.php (Modern + responsive + mobile menu + image fallback + ✅ cart badge = distinct products)
// ✅ SUCCESS MESSAGE should show in customer-orders.php (via SESSION flash), not on checkout page

session_start();
require_once 'db.php';

// Set timezone for date/time display
date_default_timezone_set('Asia/Colombo');

// ------------------------------------------------------
// Helpers
// ------------------------------------------------------
function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}

// Is customer logged in?
$customer_id   = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : 0;
$customer_name = $_SESSION['customer_name'] ?? '';
$isCustomer    = $customer_id > 0;

// Derive first name for header pill
$customer_first = 'Account';
if (!empty($customer_name)) {
    $parts = preg_split('/\s+/', trim($customer_name));
    if (!empty($parts[0])) $customer_first = $parts[0];
}

// Wishlist bubble
$wishlist_ids   = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist']) ? $_SESSION['wishlist'] : [];
$wishlist_count = count($wishlist_ids);

// ------------------------------------------------------
// Cart structure (same as cart.php)
// $_SESSION['cart'][ "product_id|color|size" ] = [ ... ]
// plus possible legacy: key = product_id, value = qty
// ------------------------------------------------------
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
$cart = $_SESSION['cart'];

// ✅ Header cart bubble should show DISTINCT product lines (not quantity sum)
$cart_distinct_count = count($cart);

// Quantity sum (for checkout content)
$total_items_in_cart = 0;
foreach ($cart as $key => $item) {
    if (is_array($item)) $total_items_in_cart += (int)($item['qty'] ?? 0);
    else $total_items_in_cart += (int)$item;
}
$cartEmpty = ($total_items_in_cart === 0);

// ------------------------------------------------------
// JSON POST: save order after PayPal approval
// ------------------------------------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['CONTENT_TYPE'])
    && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false
) {
    header('Content-Type: application/json');

    if (!$isCustomer) {
        echo json_encode([
            'success' => false,
            'error'   => 'not_logged_in',
            'message' => 'You need to be logged in first to proceed the process.'
        ]);
        exit;
    }

    if ($cartEmpty) {
        echo json_encode([
            'success' => false,
            'error'   => 'empty_cart',
            'message' => 'Your cart is empty.'
        ]);
        exit;
    }

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];

    if (($data['action'] ?? '') !== 'save_order') {
        echo json_encode(['success' => false, 'error' => 'bad_action']);
        exit;
    }

    $paypal_order_id = $data['paypal_order_id'] ?? '';
    $paypal_details  = $data['paypal_details'] ?? [];

    // ---- Load customer ----
    $sql = "SELECT full_name, email, phone,
                   address_line1, address_line2, city, state, postal_code, country
            FROM customers
            WHERE id = ? AND is_active = 1
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'db_error_customer']);
        exit;
    }
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();
    $resCustomer = $stmt->get_result();
    $customerRow = $resCustomer->fetch_assoc();
    $stmt->close();

    if (!$customerRow) {
        echo json_encode([
            'success' => false,
            'error'   => 'customer_not_found',
            'message' => 'Customer record not found.'
        ]);
        exit;
    }

    // Build shipping address string
    $addressParts = [];
    if (!empty($customerRow['address_line1'])) $addressParts[] = $customerRow['address_line1'];
    if (!empty($customerRow['address_line2'])) $addressParts[] = $customerRow['address_line2'];
    if (!empty($customerRow['city']))         $addressParts[] = $customerRow['city'];
    if (!empty($customerRow['state']))        $addressParts[] = $customerRow['state'];
    if (!empty($customerRow['postal_code']))  $addressParts[] = $customerRow['postal_code'];
    if (!empty($customerRow['country']))      $addressParts[] = $customerRow['country'];
    $shippingAddress = implode(', ', $addressParts);

    // ---- Load products for cart (variant-aware) ----
    $ids = [];
    foreach ($cart as $key => $item) {
        if (is_array($item)) $ids[] = (int)$item['product_id'];
        else $ids[] = (int)$key;
    }
    $ids = array_unique(array_filter($ids));

    if (empty($ids)) {
        echo json_encode([
            'success' => false,
            'error'   => 'no_items',
            'message' => 'No valid items in your cart.'
        ]);
        exit;
    }

    $idList = implode(',', $ids);

    $sql = "
        SELECT p.id, p.name, p.gender, p.price, p.sale_price, p.on_sale,
               p.cost_price, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.id IN ($idList)
    ";
    $result = $conn->query($sql);

    if (!$result || $result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'error'   => 'products_not_found',
            'message' => 'Could not load products for your cart.'
        ]);
        exit;
    }

    $productMap = [];
    while ($row = $result->fetch_assoc()) {
        $productMap[(int)$row['id']] = $row;
    }
    $result->free();

    $items    = [];
    $subtotal = 0.0;

    foreach ($cart as $key => $item) {
        if (is_array($item)) {
            $pid   = (int)$item['product_id'];
            $qty   = (int)($item['qty'] ?? 0);
            $size  = trim($item['size'] ?? '');
            $color = trim($item['color'] ?? '');
        } else {
            $pid   = (int)$key;
            $qty   = (int)$item;
            $size  = '';
            $color = '';
        }

        if ($qty <= 0 || !isset($productMap[$pid])) continue;

        $row = $productMap[$pid];

        $unit = ((int)$row['on_sale'] === 1 && (float)$row['sale_price'] > 0)
            ? (float)$row['sale_price']
            : (float)$row['price'];

        $lineTotal = $unit * $qty;
        $unitCost  = (float)$row['cost_price'];
        $lineCost  = $unitCost * $qty;

        $items[] = [
            'id'            => $pid,
            'name'          => $row['name'],
            'gender'        => $row['gender'],
            'category_name' => $row['category_name'],
            'qty'           => $qty,
            'unit_price'    => $unit,
            'unit_cost'     => $unitCost,
            'line_total'    => $lineTotal,
            'line_cost'     => $lineCost,
            'size'          => $size ?: null,
            'color'         => $color ?: null,
        ];

        $subtotal += $lineTotal;
    }

    if (empty($items)) {
        echo json_encode([
            'success' => false,
            'error'   => 'no_items',
            'message' => 'No valid items in your cart.'
        ]);
        exit;
    }

    // Totals: shipping is always 500.00
    $discount = 0.00;
    $shipping = 500.00;
    $tax      = 0.00;
    $grand    = $subtotal - $discount + $shipping + $tax;

    // ---- Create order ----
    $orderNumber = 'VV-' . date('Ymd') . '-' . str_pad((string)mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

    $sql = "INSERT INTO orders
                (order_number, customer_id, full_name, email, phone, shipping_address,
                 payment_method, status, subtotal, discount_amount, shipping_cost, tax_amount, total_amount)
            VALUES
                (?, ?, ?, ?, ?, ?, 'card', 'paid', ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'db_insert_order_prepare']);
        exit;
    }

    $fullName = $customerRow['full_name'];
    $email    = $customerRow['email'];
    $phone    = $customerRow['phone'];

    $stmt->bind_param(
        'sissssddddd',
        $orderNumber,
        $customer_id,
        $fullName,
        $email,
        $phone,
        $shippingAddress,
        $subtotal,
        $discount,
        $shipping,
        $tax,
        $grand
    );
    $ok = $stmt->execute();
    if (!$ok) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'db_insert_order_execute']);
        exit;
    }
    $order_id = $stmt->insert_id;
    $stmt->close();

    // ---- Insert order items (with size & color) ----
    $sqlItem = "INSERT INTO order_items
                (order_id, product_id, product_name, category_name, gender,
                 size, color, quantity, unit_price, unit_cost, line_total, line_cost)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtItem = $conn->prepare($sqlItem);
    if (!$stmtItem) {
        echo json_encode(['success' => false, 'error' => 'db_insert_items_prepare']);
        exit;
    }

    foreach ($items as $it) {
        $size  = $it['size']  ?? null;
        $color = $it['color'] ?? null;

        $stmtItem->bind_param(
            'iisssssidddd',
            $order_id,
            $it['id'],
            $it['name'],
            $it['category_name'],
            $it['gender'],
            $size,
            $color,
            $it['qty'],
            $it['unit_price'],
            $it['unit_cost'],
            $it['line_total'],
            $it['line_cost']
        );
        $stmtItem->execute();
    }
    $stmtItem->close();

    // Clear cart
    $_SESSION['cart'] = [];

    // ✅ FLASH SUCCESS MESSAGE FOR customer-orders.php
    $_SESSION['order_success'] = '✅ Payment successful! Your order has been placed. Order number: ' . $orderNumber;

    echo json_encode([
        'success'      => true,
        'order_id'     => $order_id,
        'order_number' => $orderNumber
    ]);
    exit;
}

// ------------------------------------------------------
// GET: render checkout page
// ------------------------------------------------------

// Load customer info for display
$customerRow     = null;
$shippingAddress = '';
if ($isCustomer) {
    $sql = "SELECT full_name, email, phone,
                   address_line1, address_line2, city, state, postal_code, country
            FROM customers
            WHERE id = ? AND is_active = 1
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $customerRow = $res->fetch_assoc();
        $stmt->close();

        if ($customerRow) {
            $addressParts = [];
            if (!empty($customerRow['address_line1'])) $addressParts[] = $customerRow['address_line1'];
            if (!empty($customerRow['address_line2'])) $addressParts[] = $customerRow['address_line2'];
            if (!empty($customerRow['city']))         $addressParts[] = $customerRow['city'];
            if (!empty($customerRow['state']))        $addressParts[] = $customerRow['state'];
            if (!empty($customerRow['postal_code']))  $addressParts[] = $customerRow['postal_code'];
            if (!empty($customerRow['country']))      $addressParts[] = $customerRow['country'];
            $shippingAddress = implode(', ', $addressParts);
        }
    }
}

// Build order items + totals for display
$displayItems = [];
$subtotal     = 0.0;
$discount     = 0.00;
$shipping     = 0.00;
$tax          = 0.00;

if (!$cartEmpty) {
    $ids = [];
    foreach ($cart as $key => $item) {
        if (is_array($item)) $ids[] = (int)$item['product_id'];
        else $ids[] = (int)$key;
    }
    $ids = array_unique(array_filter($ids));

    if (!empty($ids)) {
        $idList = implode(',', $ids);
        $sql = "
            SELECT id, name, sku, price, sale_price, on_sale, sizes, colors, image_url
            FROM products
            WHERE id IN ($idList)
        ";
        $res = $conn->query($sql);

        $productMap = [];
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $productMap[(int)$row['id']] = $row;
            }
            $res->free();
        }

        foreach ($cart as $key => $item) {
            if (is_array($item)) {
                $pid   = (int)$item['product_id'];
                $qty   = (int)($item['qty'] ?? 0);
                $size  = trim($item['size'] ?? '');
                $color = trim($item['color'] ?? '');
            } else {
                $pid   = (int)$key;
                $qty   = (int)$item;
                $size  = '';
                $color = '';
            }

            if ($qty <= 0 || !isset($productMap[$pid])) continue;

            $row = $productMap[$pid];

            $unit = ((int)$row['on_sale'] === 1 && (float)$row['sale_price'] > 0)
                ? (float)$row['sale_price']
                : (float)$row['price'];

            $lineTotal = $unit * $qty;

            $displayItems[] = [
                'id'         => $pid,
                'name'       => $row['name'],
                'sku'        => $row['sku'],
                'qty'        => $qty,
                'unit_price' => $unit,
                'line_total' => $lineTotal,
                'size'       => $size ?: null,
                'color'      => $color ?: null,
                'image_url'  => $row['image_url'],
            ];

            $subtotal += $lineTotal;
        }

        if (!empty($displayItems)) $shipping = 500.00;
    }
}

$grandTotal       = $subtotal - $discount + $shipping + $tax;
$paypalAmount     = number_format(max($grandTotal, 0.01), 2, '.', ''); // PayPal cannot take 0.00
$checkoutDateTime = date('M j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout | Velvet Vogue</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Boxicons -->
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <!-- Site CSS -->
    <link rel="stylesheet" href="css/style.css">

    <!-- PayPal JS SDK (sandbox) -->
    <script src="https://www.paypal.com/sdk/js?client-id=Af0nIE9cLy6VBR-z18TVmbC72gSHSSNXgSJUHeY0foFof1YIrtS2t1myce90U2ofnN4pOfpEvftc9QOf&currency=USD&intent=capture"></script>

    <!-- Image fallback helpers -->
    <script>
      function vvSvgPlaceholderDataUri(text) {
        const safe = String(text || 'Image unavailable').slice(0, 40);
        const svg =
          `<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300" viewBox="0 0 300 300">
            <rect width="300" height="300" fill="#f4f3fb"/>
            <g opacity="0.9">
              <path d="M85 185l45-48 35 37 22-25 50 53H85z" fill="#d9d3ea"/>
              <circle cx="115" cy="115" r="15" fill="#d9d3ea"/>
            </g>
            <text x="50%" y="78%" text-anchor="middle" font-family="Poppins, Arial, sans-serif"
                  font-size="12" fill="#b7b0c9">` + safe.replace(/</g,'').replace(/>/g,'') + `</text>
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
        html, body { min-height: 100%; overflow-x: hidden; }

        /* ✅ Primary button hover fix (text never hides) */
        .vv-btn-primary, .vv-btn.vv-btn-primary{
            position:relative;
            overflow:hidden;
            color:#fff !important;
            text-decoration:none;
            transition: transform 160ms ease, box-shadow 160ms ease, background-color 160ms ease, border-color 160ms ease, color 160ms ease;
        }
        .vv-btn-primary:hover, .vv-btn.vv-btn-primary:hover,
        .vv-btn-primary:focus, .vv-btn.vv-btn-primary:focus{
            color:#fff !important;
            background-color: var(--vv-accent) !important;
            border-color: var(--vv-accent) !important;
            box-shadow: 0 10px 26px rgba(25,12,64,0.18) !important;
            transform: translateY(-1px);
        }
        .vv-btn-primary:active, .vv-btn.vv-btn-primary:active{
            transform: translateY(0);
            box-shadow: 0 6px 18px rgba(25,12,64,0.16) !important;
        }

        /* Flash (only using ERROR on checkout page now) */
        .vv-flash{
            margin:0.8rem 0;
            padding:0.75rem 0.9rem;
            border-radius:14px;
            font-size:0.9rem;
            border:1px solid var(--vv-border-soft);
            background:#f7f0fa;
            color: var(--vv-text-main);
        }
        .vv-flash.error{
            border-color:#fda29b;
            background:#fffbfa;
            color:#b42318;
        }

        /* Modern background */
        body.checkout-page{
            background:
              radial-gradient(900px 420px at 10% -10%, rgba(156, 95, 255, 0.14), transparent 60%),
              radial-gradient(820px 380px at 90% 0%, rgba(255, 168, 229, 0.16), transparent 55%),
              linear-gradient(180deg, #ffffff 0%, #fbf8ff 45%, #ffffff 100%);
        }

        /* Checkout: fixed header while browsing */
        .checkout-page .vv-header{
            position: fixed !important;
            top: 0; left: 0; right: 0;
            z-index: 100;
            background: #ffffff;
            border-bottom: 1px solid rgba(232, 222, 255, 0.75);
        }
        .checkout-page .vv-checkout-main{
            padding: 5.6rem 0 2.2rem; /* header offset */
        }

        /* When PayPal Debit / Credit Card sheet is open */
        body.paypal-card-open .vv-header{ display:none; }
        body.paypal-card-open .vv-checkout-main{
            padding-top: 1.6rem;
            min-height: 1100px;
        }

        /* Header mobile menu */
        .vv-nav-wrapper{ position: relative; }
        .vv-nav-toggle{
            display:none;
            border:1px solid var(--vv-border-soft);
            background:#fff;
            border-radius:12px;
            padding:0.45rem 0.6rem;
            cursor:pointer;
            line-height:1;
            margin-right: 0.45rem;
        }
        .vv-mobile-nav{ display:none; }

        @media (max-width: 991.98px){
            .vv-nav{ display:none; }
            .vv-nav-wrapper{ display:flex; align-items:center; gap:0.6rem; }
            .vv-nav-toggle{ display:inline-flex; align-items:center; justify-content:center; order:0; }
            .vv-logo{ order:1; margin-right:auto; }
            .vv-nav-actions{ order:2; display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap; justify-content:flex-end; }
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
                z-index:140;
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
            .vv-mobile-nav a:hover{ background:#f7f0fa; color: var(--vv-accent); }
        }

        .vv-section-sub{ font-size: 0.9rem; color: var(--vv-text-soft); margin-bottom: 1.15rem; }

        .vv-checkout-grid{
            display:grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 340px);
            gap: 1.2rem;
            align-items:start;
        }
        @media (max-width: 991.98px){ .vv-checkout-grid{ grid-template-columns: 1fr; } }

        .vv-checkout-card{
            background:#fff;
            border-radius:22px;
            border:1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 1rem 1.2rem 1.2rem;
            margin-bottom: 1rem;
        }
        .vv-checkout-card h3{ font-size: 1rem; margin-bottom: 0.35rem; }
        .vv-checkout-card small{ font-size: 0.8rem; color: var(--vv-text-soft); }

        .vv-checkout-summary-card{ position: sticky; top: 6.6rem; }
        @media (max-width: 991.98px){ .vv-checkout-summary-card{ position: static; top: auto; } }

        .vv-checkout-summary-row{ display:flex; justify-content:space-between; margin-bottom: 0.35rem; font-size: 0.9rem; }
        .vv-checkout-summary-row.total{ font-weight:600; font-size:1rem; margin-top:0.3rem; }
        .vv-order-datetime{ font-size:0.8rem; color: var(--vv-text-soft); margin-bottom:0.6rem; }

        .vv-checkout-items-list{ margin-top:0.7rem; border-top:1px dashed var(--vv-border-soft); padding-top:0.7rem; }
        .vv-checkout-item-row{ display:flex; justify-content:space-between; gap:0.75rem; margin-bottom:0.6rem; font-size:0.85rem; align-items:flex-start; }
        .vv-checkout-item-thumb{
            width: 58px;
            aspect-ratio: 1 / 1;
            border-radius: 14px;
            overflow:hidden;
            background:#f4f3fb;
            flex: 0 0 auto;
        }
        .vv-checkout-item-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
        .vv-checkout-item-thumb img.vv-img-fallback{ object-fit:contain !important; background:#f4f3fb; }

        .vv-checkout-item-main{ flex: 1 1 auto; min-width: 0; }
        .vv-checkout-item-main .name{ display:block; font-weight:500; margin-bottom:0.1rem; line-height: 1.25; }
        .vv-checkout-item-main .meta{ display:block; font-size:0.78rem; color: var(--vv-text-soft); }
        .vv-checkout-item-main .meta span + span::before{ content:"·"; margin: 0 0.25rem; }

        .vv-banner-warning{
            display:inline-flex;
            align-items:center;
            gap:0.35rem;
            padding:0.35rem 0.8rem;
            border-radius:999px;
            background:#fff7e6;
            color:#b36b00;
            font-size:0.8rem;
            margin-bottom: 1rem;
        }

        /* User dropdown */
        .vv-user-menu{ position:relative; }
        .vv-user-toggle{ cursor:pointer; white-space:nowrap; }
        .vv-user-dropdown{
            position:absolute;
            right:0;
            top:calc(100% + 0.35rem);
            min-width: 180px;
            background:#fff;
            border-radius:14px;
            border:1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding:0.4rem 0.2rem;
            display:none;
            z-index: 160;
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

        #paypal-button-container{ width:100%; margin-top:0.8rem; }
        .vv-paypal-fake-btn{
            width:100%;
            border:none;
            border-radius:999px;
            padding:0.55rem 1rem;
            font-size:0.9rem;
            font-weight:500;
            cursor:pointer;
            background:#ffc439;
            box-shadow:0 8px 16px rgba(0,0,0,0.15);
            display:flex;
            align-items:center;
            justify-content:center;
            gap:0.4rem;
        }
        .vv-paypal-fake-btn i{ font-size:1.2rem; }
        .vv-paypal-fake-btn:hover{ filter: brightness(1.05); }

        .vv-empty{
            background:#fff;
            border-radius: 22px;
            border:1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 1.1rem 1.1rem;
        }
    </style>
</head>

<body class="vv-body checkout-page"
      data-logged-in="<?php echo $isCustomer ? '1' : '0'; ?>"
      data-cart-empty="<?php echo $cartEmpty ? '1' : '0'; ?>">

<header class="vv-header">
    <div class="vv-container vv-nav-wrapper">

        <!-- Mobile toggle -->
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
        </nav>

        <!-- Mobile nav -->
        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php">Home</a>
            <a href="shop.php">Shop</a>
            <a href="wishlist.php">Wishlist</a>
        </div>

        <div class="vv-nav-actions">
            <a href="wishlist.php" class="vv-icon-btn" aria-label="Wishlist">
                <i class="bx bx-heart"></i>
                <span class="vv-count-badge" id="wishlistCount"><?php echo $wishlist_count; ?></span>
            </a>

            <a href="cart.php" class="vv-icon-btn" aria-label="Cart">
                <i class="bx bx-shopping-bag"></i>
                <span class="vv-count-badge" id="cartCount"><?php echo $cart_distinct_count; ?></span>
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

<main class="vv-checkout-main">
    <div class="vv-container">
        <h1>Checkout</h1>
        <p class="vv-section-sub">
            <?php echo (int)$cart_distinct_count; ?> product(s) ·
            <?php echo (int)$total_items_in_cart; ?> item(s) ·
            <?php echo htmlspecialchars($checkoutDateTime); ?>
        </p>

        <?php if (!$isCustomer): ?>
            <div class="vv-banner-warning">
                <i class="bx bx-info-circle"></i>
                Checkout – You need to be logged in first to proceed the process.
            </div>
        <?php endif; ?>

        <?php if ($cartEmpty): ?>
            <div class="vv-empty">
                <p>Your cart is empty. <a href="shop.php">Go back to the shop</a> to add items.</p>
            </div>
        <?php else: ?>
            <div class="vv-checkout-grid">
                <div>
                    <!-- Contact & shipping -->
                    <section class="vv-checkout-card">
                        <h3>Contact &amp; shipping</h3>
                        <?php if ($customerRow): ?>
                            <p><strong>Name</strong><br><?php echo htmlspecialchars($customerRow['full_name']); ?></p>
                            <p><strong>Email</strong><br><?php echo htmlspecialchars($customerRow['email']); ?></p>
                            <p><strong>Phone</strong><br><?php echo htmlspecialchars($customerRow['phone']); ?></p>
                            <p><strong>Shipping address</strong><br>
                                <?php echo htmlspecialchars($shippingAddress ?: 'No shipping address on file'); ?>
                            </p>
                            <p style="font-size:0.82rem;color:var(--vv-text-soft);margin-top:0.3rem;">
                                <a href="customer-profile.php">✏ Edit profile &amp; address</a>
                            </p>
                        <?php else: ?>
                            <p style="font-size:0.9rem;">
                                You are not signed in. Please <a href="customer-login.php">sign in</a> so we can prefill your details.
                            </p>
                        <?php endif; ?>
                    </section>

                    <!-- Order items -->
                    <section class="vv-checkout-card">
                        <h3>Order items</h3>
                        <small>Review the items in your bag before you continue.</small>

                        <div class="vv-checkout-items-list">
                            <?php foreach ($displayItems as $item): ?>
                                <div class="vv-checkout-item-row">
                                    <div class="vv-checkout-item-thumb">
                                        <?php if (!empty($item['image_url'])): ?>
                                            <img
                                                src="<?php echo htmlspecialchars($item['image_url']); ?>"
                                                alt="<?php echo htmlspecialchars($item['name']); ?>"
                                                loading="lazy"
                                                data-fallback-text="Product image"
                                                onerror="vvImgFallback(this)"
                                            >
                                        <?php else: ?>
                                            <img
                                                src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="
                                                alt="<?php echo htmlspecialchars($item['name']); ?>"
                                                data-fallback-text="Product image"
                                                onerror="vvImgFallback(this)"
                                            >
                                            <script>
                                              document.currentScript.previousElementSibling && vvImgFallback(document.currentScript.previousElementSibling);
                                            </script>
                                        <?php endif; ?>
                                    </div>

                                    <div class="vv-checkout-item-main">
                                        <span class="name"><?php echo htmlspecialchars($item['name']); ?></span>
                                        <span class="meta">
                                            <?php if (!empty($item['sku'])): ?>
                                                <span>SKU: <?php echo htmlspecialchars($item['sku']); ?></span>
                                            <?php endif; ?>
                                            <span>Color: <?php echo $item['color'] ? htmlspecialchars($item['color']) : 'N/A'; ?></span>
                                            <span>Size: <?php echo $item['size'] ? htmlspecialchars($item['size']) : 'N/A'; ?></span>
                                            <span>Qty: <?php echo (int)$item['qty']; ?></span>
                                        </span>
                                    </div>

                                    <div><?php echo money_fmt($item['line_total']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <p style="font-size:0.8rem;color:var(--vv-text-soft);margin-top:0.5rem;">
                            If this looks wrong, please go back to your <a href="cart.php">cart</a>.
                        </p>
                    </section>

                    <!-- Payment -->
                    <section class="vv-checkout-card">
                        <h3>Payment</h3>
                        <small>We currently support <strong>PayPal</strong> for secure online payments.</small>

                        <!-- ✅ Only error shown here; success will be shown on customer-orders.php -->
                        <div id="checkoutErr" class="vv-flash error" style="display:none;" aria-live="polite"></div>

                        <div id="paypal-button-container"></div>

                        <p style="font-size:0.78rem;color:var(--vv-text-soft);margin-top:0.5rem;">
                            After PayPal confirms your payment, we’ll create your order and redirect you to Orders.
                        </p>
                    </section>
                </div>

                <!-- Summary -->
                <aside class="vv-checkout-card vv-checkout-summary-card">
                    <h3>Order summary</h3>
                    <p class="vv-order-datetime">
                        Order date &amp; time: <?php echo htmlspecialchars($checkoutDateTime); ?>
                    </p>

                    <div class="vv-checkout-summary-row">
                        <span>Products (distinct)</span>
                        <span><?php echo (int)$cart_distinct_count; ?></span>
                    </div>
                    <div class="vv-checkout-summary-row">
                        <span>Total quantity</span>
                        <span><?php echo (int)$total_items_in_cart; ?></span>
                    </div>

                    <div class="vv-checkout-summary-row">
                        <span>Items cost</span>
                        <span><?php echo money_fmt($subtotal); ?></span>
                    </div>
                    <div class="vv-checkout-summary-row">
                        <span>Discount</span>
                        <span>- <?php echo money_fmt($discount); ?></span>
                    </div>
                    <div class="vv-checkout-summary-row">
                        <span>Shipping</span>
                        <span><?php echo money_fmt($shipping); ?></span>
                    </div>
                    <div class="vv-checkout-summary-row">
                        <span>Tax</span>
                        <span><?php echo money_fmt($tax); ?></span>
                    </div>
                    <hr>
                    <div class="vv-checkout-summary-row total">
                        <span>Total</span>
                        <span><?php echo money_fmt($grandTotal); ?></span>
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

    // User menu dropdown
    const menu = document.querySelector('.vv-user-menu');
    if (menu) {
        const toggle = menu.querySelector('.vv-user-toggle');
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            menu.classList.toggle('open');
        });
        document.addEventListener('click', function () {
            menu.classList.remove('open');
        });
    }

    const loggedIn  = document.body.dataset.loggedIn === '1';
    const cartEmpty = document.body.dataset.cartEmpty === '1';
    const container = document.getElementById('paypal-button-container');

    const errBox = document.getElementById('checkoutErr');

    function showError(text){
        if (!errBox) return;
        errBox.textContent = text || '';
        errBox.style.display = text ? 'block' : 'none';
    }
    function clearError(){ showError(''); }

    if (!container) return;

    // If not logged in or cart empty -> fake button
    if (!loggedIn || cartEmpty) {
        container.innerHTML =
            '<button type="button" class="vv-paypal-fake-btn" id="fakePayPalBtn">' +
            '<i class="bx bxl-paypal"></i>Pay with PayPal' +
            '</button>';

        document.getElementById('fakePayPalBtn').addEventListener('click', function () {
            clearError();
            if (cartEmpty) showError('Your cart is empty. Please add items before proceeding to checkout.');
            else showError('You need to be logged in first to proceed the process.');
        });
        return;
    }

    if (typeof paypal === 'undefined') {
        console.error('PayPal SDK did not load.');
        container.innerHTML = '<p style="color:red;font-size:0.85rem;">Could not load PayPal. Please refresh the page.</p>';
        return;
    }

    paypal.Buttons({
        style: { layout: 'vertical', shape: 'pill', height: 42, label: 'paypal' },

        funding: { allowed: [ paypal.FUNDING.PAYPAL, paypal.FUNDING.CARD ] },

        onClick: function (data, actions) {
            clearError();
            if (data.fundingSource === paypal.FUNDING.CARD) {
                document.body.classList.add('paypal-card-open');
            }
            return actions.resolve();
        },

        onCancel: function () {
            document.body.classList.remove('paypal-card-open');
        },

        createOrder: function (data, actions) {
            return actions.order.create({
                purchase_units: [{ amount: { value: '<?php echo $paypalAmount; ?>' } }]
            });
        },

        onApprove: function (data, actions) {
            clearError();

            return actions.order.capture().then(function (details) {
                return fetch('checkout.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_order',
                        paypal_order_id: data.orderID,
                        paypal_details: details
                    })
                })
                .then(r => r.json())
                .then(function (resp) {
                    document.body.classList.remove('paypal-card-open');

                    if (!resp || !resp.success) {
                        const msg = resp && resp.message ? resp.message
                            : 'Payment completed, but we could not save your order properly.';
                        showError(msg);
                        return;
                    }

                    // ✅ SUCCESS MESSAGE is stored in SESSION (server-side) and will show in customer-orders.php
                    window.location.href = 'customer-orders.php';
                })
                .catch(function (err) {
                    document.body.classList.remove('paypal-card-open');
                    console.error('Error saving order', err);
                    showError('Payment went through, but we could not contact the server. Please check your orders page.');
                });
            });
        },

        onError: function (err) {
            document.body.classList.remove('paypal-card-open');
            console.error('PayPal error', err);
            showError('There was a problem with PayPal. Please try again in a moment.');
        }
    }).render('#paypal-button-container');
});
</script>

</body>
</html>
