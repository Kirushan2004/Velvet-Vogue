<?php
// admin/order-view.php
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// small helper
function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}

// ----- validate order id -----
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    header('Location: orders.php');
    exit;
}
$orderId = (int) $_GET['id'];

// ----- handle status update -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $newStatus = $_POST['order_status'] ?? '';
    $allowed   = ['pending','paid','shipped','completed','cancelled'];

    if (in_array($newStatus, $allowed, true)) {
        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $newStatus, $orderId);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: order-view.php?id=" . $orderId);
    exit;
}

// ----- load order + customer (for shipping details) -----
$sqlOrder = "
    SELECT 
        o.*,
        c.full_name    AS customer_name,
        c.email        AS customer_email,
        c.phone        AS customer_phone,
        c.address_line1,
        c.address_line2,
        c.city,
        c.state,
        c.postal_code,
        c.country
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sqlOrder);
$stmt->bind_param('i', $orderId);
$stmt->execute();
$res   = $stmt->get_result();
$order = $res->fetch_assoc();
$stmt->close();

if (!$order) {
    header('Location: orders.php');
    exit;
}

// ----- derive display values -----
$order_number   = $order['order_number'] ?? ('#' . $orderId);
$order_created  = $order['created_at'] ?? '';
$payment_method = strtoupper($order['payment_method'] ?? 'N/A');
$order_status   = ucfirst($order['status'] ?? 'Pending');

// monetary
$items_subtotal = (float)($order['subtotal_amount'] ?? 0);
$discount_amt   = (float)($order['discount_amount'] ?? 0);
$shipping_amt   = (float)($order['shipping_amount'] ?? 0);
$tax_amt        = (float)($order['tax_amount'] ?? 0);
$total_amount   = (float)($order['total_amount'] ?? 0);

// shipping details purely from customer (no shipping_* columns)
$shipping_name  = $order['customer_name']  ?? '';
$shipping_phone = $order['customer_phone'] ?? '';
$shipping_email = $order['customer_email'] ?? '';

$addr_parts = [];
if (!empty($order['address_line1'])) $addr_parts[] = $order['address_line1'];
if (!empty($order['address_line2'])) $addr_parts[] = $order['address_line2'];
if (!empty($order['city']))          $addr_parts[] = $order['city'];
if (!empty($order['state']))         $addr_parts[] = $order['state'];
if (!empty($order['postal_code']))   $addr_parts[] = $order['postal_code'];
if (!empty($order['country']))       $addr_parts[] = $order['country'];
$shipping_address = $addr_parts ? implode(', ', $addr_parts) : '—';

// ----- load order items (with product image + SKU) -----
$items = [];
$sqlItems = "
    SELECT
        oi.id,
        oi.product_id,
        oi.product_name,
        oi.category_name,
        oi.size,
        oi.color,
        oi.quantity,
        oi.unit_price,
        oi.line_total,
        p.sku,
        p.image_url
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
";
$stmt = $conn->prepare($sqlItems);
$stmt->bind_param('i', $orderId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order <?php echo htmlspecialchars($order_number); ?> | Velvet Vogue Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap & Boxicons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <!-- Site + admin CSS -->
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">

    <style>
        .order-summary-cards .admin-panel { height: 100%; }

        .order-item-thumb {
            width: 64px;
            height: 64px;
            border-radius: 12px;
            overflow: hidden;
            background-color: #f3f2f8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
        }
        .order-item-thumb img { width: 100%; height: 100%; object-fit: cover; }

        /* ================= Mobile Sidebar MENU (same as dashboard) ================= */
        .sidebar-close-btn{
            position:absolute;
            right:10px;
            top:10px;
            border:1px solid rgba(255,255,255,0.25);
            background:rgba(255,255,255,0.1);
            color:#fff;
            border-radius:12px;
            padding:.35rem .55rem;
            line-height:1;
            z-index:2;
        }
        .sidebar-close-btn i{ font-size:1.3rem; }

        .admin-sidebar-backdrop{
            position:fixed;
            inset:0;
            background:rgba(0,0,0,.35);
            z-index:5000;
            opacity:0;
            pointer-events:none;
            transition:150ms ease;
        }
        body.admin-sidebar-open .admin-sidebar-backdrop{
            opacity:1;
            pointer-events:auto;
        }

        @media (max-width: 991.98px){
            .sidebar{
                display:block !important;
                visibility:visible !important;
                opacity:1 !important;

                position:fixed !important;
                left:0;
                top:0;
                height:100vh;
                width:min(300px, 86vw);

                transform:translate3d(-105%,0,0);
                transition:transform 200ms ease;
                z-index:5005 !important;
            }
            body.admin-sidebar-open .sidebar{
                transform:translate3d(0,0,0);
            }
            body.admin-sidebar-open{
                overflow:hidden;
            }
        }

        .admin-mobile-menu-btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border:1px solid rgba(0,0,0,0.12);
            background:#fff;
            border-radius:14px;
            padding:.45rem .55rem;
            line-height:1;
        }
        .admin-mobile-menu-btn i{ font-size:1.35rem; }
    </style>
</head>
<body class="admin-dashboard-body">

<?php include 'includes/sidebar.php'; ?>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>

<div class="admin-main">
    <header class="admin-topbar">
        <div class="d-flex align-items-start gap-2">
            <button type="button" class="admin-mobile-menu-btn d-lg-none" id="adminSidebarOpen" aria-label="Open menu">
                <i class='bx bx-menu'></i>
            </button>

            <div>
                <h1 class="admin-page-title">Order <?php echo htmlspecialchars($order_number); ?></h1>
                <p class="admin-page-subtitle mb-0">
                    Placed on <?php echo $order_created ? date('Y-m-d H:i', strtotime($order_created)) : '—'; ?>
                </p>
            </div>
        </div>

        <a href="admin-profile.php" class="admin-user-pill text-decoration-none text-dark">
            <i class='bx bxs-user-circle'></i>
            <div>
                <span class="admin-user-name"><?php echo htmlspecialchars($admin_name); ?></span>
                <span class="admin-user-role">Administrator</span>
            </div>
        </a>
    </header>

    <main class="admin-content container-fluid">

        <!-- top info cards -->
        <div class="row g-3 mb-4 order-summary-cards">
            <div class="col-md-3">
                <div class="admin-panel">
                    <div class="admin-panel-subtitle text-uppercase small mb-1">Order</div>
                    <h2 class="admin-panel-title mb-1"><?php echo htmlspecialchars($order_number); ?></h2>
                    <p class="mb-0 small">ID #<?php echo (int)$orderId; ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="admin-panel">
                    <div class="admin-panel-subtitle text-uppercase small mb-1">Customer</div>
                    <h2 class="admin-panel-title mb-1"><?php echo htmlspecialchars($order['customer_name'] ?? 'Unknown'); ?></h2>
                    <p class="mb-0 small"><?php echo htmlspecialchars($order['customer_email'] ?? ''); ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="admin-panel">
                    <div class="admin-panel-subtitle text-uppercase small mb-1">Payment</div>
                    <h2 class="admin-panel-title mb-1"><?php echo htmlspecialchars($payment_method); ?></h2>
                    <p class="mb-0 small">Status: <?php echo htmlspecialchars($order_status); ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="admin-panel">
                    <div class="admin-panel-subtitle text-uppercase small mb-1">Total amount</div>
                    <h2 class="admin-panel-title mb-1"><?php echo money_fmt($total_amount); ?></h2>
                    <p class="mb-0 small">Subtotal: <?php echo money_fmt($items_subtotal); ?></p>
                </div>
            </div>
        </div>

        <!-- order items + shipping / status -->
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title mb-0">Order items</h2>
                    </div>
                    <div class="admin-panel-body">
                        <?php if (empty($items)): ?>
                            <p class="text-muted mb-0">This order has no items.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Size</th>
                                        <th>Color</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">Unit price</th>
                                        <th class="text-end">Line total</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="order-item-thumb">
                                                        <?php if (!empty($item['image_url'])): ?>
                                                            <img src="<?php echo '../' . htmlspecialchars($item['image_url']); ?>" alt="">
                                                        <?php else: ?>
                                                            <i class="bx bx-image-alt text-muted"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($item['product_name'] ?? 'Product'); ?></strong><br>
                                                        <?php if (!empty($item['sku'])): ?>
                                                            <small class="text-muted">SKU: <?php echo htmlspecialchars($item['sku']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['category_name'] ?? '—'); ?></td>
                                            <td><?php echo htmlspecialchars($item['size'] ?? '—'); ?></td>
                                            <td><?php echo htmlspecialchars($item['color'] ?? '—'); ?></td>
                                            <td class="text-end"><?php echo (int)$item['quantity']; ?></td>
                                            <td class="text-end"><?php echo money_fmt($item['unit_price']); ?></td>
                                            <td class="text-end"><?php echo money_fmt($item['line_total']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3 text-end">
                                <div>Items subtotal: <?php echo money_fmt($items_subtotal); ?></div>
                                <div>Discount: <?php echo money_fmt($discount_amt); ?></div>
                                <div>Shipping: <?php echo money_fmt($shipping_amt); ?></div>
                                <div>Tax: <?php echo money_fmt($tax_amt); ?></div>
                                <div class="fw-bold fs-5">Total: <?php echo money_fmt($total_amount); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 d-flex flex-column gap-3">
                <!-- Shipping details -->
                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title mb-0">Shipping details</h2>
                    </div>
                    <div class="admin-panel-body">
                        <p class="mb-1"><strong>Name:</strong><br><?php echo htmlspecialchars($shipping_name ?: '—'); ?></p>
                        <p class="mb-1"><strong>Phone:</strong><br><?php echo htmlspecialchars($shipping_phone ?: '—'); ?></p>
                        <p class="mb-1"><strong>Email:</strong><br><?php echo htmlspecialchars($shipping_email ?: '—'); ?></p>
                        <p class="mb-0"><strong>Address:</strong><br><?php echo htmlspecialchars($shipping_address); ?></p>
                    </div>
                </div>

                <!-- Update status -->
                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title mb-0">Update status</h2>
                    </div>
                    <div class="admin-panel-body">
                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label">Order status</label>
                                <select name="order_status" class="form-select">
                                    <?php
                                    $statusOptions = [
                                        'pending'   => 'Pending',
                                        'paid'      => 'Paid',
                                        'shipped'   => 'Shipped',
                                        'completed' => 'Completed',
                                        'cancelled' => 'Cancelled'
                                    ];
                                    foreach ($statusOptions as $value => $label):
                                    ?>
                                        <option value="<?php echo $value; ?>"
                                            <?php echo ($order['status'] ?? '') === $value ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="update_status" class="btn btn-primary rounded-pill">
                                <i class="bx bx-save me-1"></i> Save status
                            </button>
                            <a href="orders.php" class="btn btn-link">Back to orders</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* ============ Mobile sidebar menu (same as dashboard) ============ */
function ensureSidebarCloseBtn(){
  const sidebar = document.querySelector('.sidebar');
  if (!sidebar) return null;

  let btn = document.getElementById('adminSidebarClose');
  if (!btn){
    btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'adminSidebarClose';
    btn.className = 'sidebar-close-btn';
    btn.setAttribute('aria-label', 'Close menu');
    btn.innerHTML = "<i class='bx bx-x'></i>";
    sidebar.appendChild(btn);
  }
  return btn;
}

function setupMobileSidebar(){
  const openBtn  = document.getElementById('adminSidebarOpen');
  const backdrop = document.getElementById('adminSidebarBackdrop');
  const closeBtn = ensureSidebarCloseBtn();
  const sidebar  = document.querySelector('.sidebar');

  const open  = () => document.body.classList.add('admin-sidebar-open');
  const close = () => document.body.classList.remove('admin-sidebar-open');

  if (openBtn) openBtn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    open();
  });

  if (closeBtn) closeBtn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    close();
  });

  if (backdrop) backdrop.addEventListener('click', close);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });

  // If user clicks a sidebar link on mobile, close menu
  if (sidebar){
    sidebar.querySelectorAll('a.nav-item').forEach(a => {
      a.addEventListener('click', () => {
        if (window.matchMedia('(max-width: 991.98px)').matches) close();
      });
    });
  }
}

document.addEventListener('DOMContentLoaded', () => {
  setupMobileSidebar();
});
</script>
</body>
</html>
