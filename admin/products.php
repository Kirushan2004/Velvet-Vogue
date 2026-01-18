<?php
// admin/products.php
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// ---------- helpers ----------
function redirect_with_msg($url, $msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    header("Location: {$url}");
    exit;
}

function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- HANDLE POST ACTIONS (toggle active / delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Toggle active / inactive
    if (isset($_POST['toggle_active_id'], $_POST['new_status'])) {
        $id        = (int)$_POST['toggle_active_id'];
        $newStatus = (int)$_POST['new_status'];

        $stmt = $conn->prepare("UPDATE products SET is_active = ? WHERE id = ?");
        $stmt->bind_param('ii', $newStatus, $id);
        if ($stmt->execute()) {
            redirect_with_msg('products.php', 'Product status updated.');
        } else {
            redirect_with_msg('products.php', 'Could not update product status.', 'danger');
        }
    }

    // Delete product
    if (isset($_POST['delete_id'])) {
        $deleteId = (int)$_POST['delete_id'];

        // Get image (optional for later file deletion logic)
        $stmt = $conn->prepare("SELECT image_url FROM products WHERE id = ?");
        $stmt->bind_param('i', $deleteId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        try {
            $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
            $stmt->bind_param('i', $deleteId);
            if ($stmt->execute()) {
                redirect_with_msg('products.php', 'Product deleted successfully.');
            } else {
                redirect_with_msg(
                    'products.php',
                    'Unable to delete this product because it is used in orders or related records.',
                    'danger'
                );
            }
        } catch (Throwable $e) {
            redirect_with_msg(
                'products.php',
                'Unable to delete this product because it is used in orders or related records.',
                'danger'
            );
        }
    }
}

// ---------- FILTER & SEARCH (GET) ----------
$search       = trim($_GET['q'] ?? '');
$status       = $_GET['status']   ?? ''; // '', 'active', 'inactive'
$genderFilter = $_GET['gender']   ?? ''; // '', 'women','men','unisex'
$categoryId   = $_GET['category'] ?? ''; // '', numeric id

$allowedStatus = ['active', 'inactive'];
$allowedGender = ['women', 'men', 'unisex'];

// ---------- LOAD CATEGORIES FOR FILTER ----------
$categories = [];
$resCat = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($resCat) {
    while ($row = $resCat->fetch_assoc()) {
        $categories[] = $row;
    }
    $resCat->free();
}

// ---------- LOAD PRODUCTS WITH FILTERS ----------
$sql = "
    SELECT 
        p.*,
        c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE 1=1
";

$params = [];
$types  = '';

// status filter
if (in_array($status, $allowedStatus, true)) {
    if ($status === 'active') {
        $sql .= " AND p.is_active = 1";
    } elseif ($status === 'inactive') {
        $sql .= " AND p.is_active = 0";
    }
}

// gender filter
if (in_array($genderFilter, $allowedGender, true)) {
    $sql   .= " AND p.gender = ?";
    $types .= 's';
    $params[] = $genderFilter;
}

// category filter
if ($categoryId !== '' && ctype_digit($categoryId)) {
    $cid    = (int)$categoryId;
    $sql   .= " AND p.category_id = ?";
    $types .= 'i';
    $params[] = $cid;
}

// text search filter: name, sku, category name, collection
if ($search !== '') {
    $sql   .= " AND (p.name LIKE ? OR p.sku LIKE ? OR c.name LIKE ? OR p.collection LIKE ?)";
    $like  = '%' . $search . '%';
    $types .= 'ssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY p.created_at DESC";

$products = [];
if ($types !== '') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($sql);
}

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $products[] = $row;
    }
    $res->free();
}
if (isset($stmt)) {
    $stmt->close();
}

// ---------- TOP PRODUCTS (ranking snippet on this page) ----------
$top_products = [];
$sqlTop = "
    SELECT 
        p.id,
        p.name,
        p.image_url,
        COALESCE(SUM(oi.quantity),0) AS total_qty,
        COALESCE(SUM(oi.line_total),0.00) AS total_revenue
    FROM order_items oi
    INNER JOIN products p ON oi.product_id = p.id
    INNER JOIN orders o   ON oi.order_id = o.id
    WHERE o.status IN ('paid','shipped','completed')
    GROUP BY p.id, p.name, p.image_url
    ORDER BY total_qty DESC
    LIMIT 10
";
if ($resTop = $conn->query($sqlTop)) {
    while ($row = $resTop->fetch_assoc()) {
        $top_products[] = $row;
    }
    $resTop->free();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Products | Velvet Vogue Admin</title>
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
        .product-thumb {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            overflow: hidden;
            background-color: #f3f2f8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.5rem;
        }
        .product-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .badge-soft {
            border-radius: 999px;
            font-size: 0.7rem;
            padding: 0.25rem 0.6rem;
        }
        .badge-onsale {
            background: rgba(244, 67, 54, 0.08);
            color: #d32f2f;
            border: 1px solid rgba(244,67,54,0.25);
        }
        .badge-new {
            background: rgba(76, 175, 80, 0.08);
            color: #2e7d32;
            border: 1px solid rgba(76,175,80,0.25);
        }
        .badge-hot {
            background: rgba(255, 152, 0, 0.08);
            color: #ef6c00;
            border: 1px solid rgba(255,152,0,0.25);
        }

        /* ================= Mobile Sidebar MENU (same behavior as dashboard) ================= */
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
                overflow:hidden; /* stop background scroll when menu is open */
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
                <h1 class="admin-page-title">Products</h1>
                <p class="admin-page-subtitle mb-0">
                    Manage your catalog, pricing, and featured products.
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

        <!-- MAIN PRODUCTS TABLE -->
        <div class="admin-panel mb-4">
            <div class="admin-panel-header">
                <h2 class="admin-panel-title mb-0">Product list</h2>
                <a href="product-edit.php" class="btn btn-primary rounded-pill">
                    <i class='bx bx-plus me-1'></i> Add product
                </a>
            </div>

            <div class="admin-panel-body">
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                        <?php echo htmlspecialchars($flash['msg']); ?>
                    </div>
                <?php endif; ?>

                <!-- FILTER / SEARCH BAR -->
                <form class="row g-2 mt-3 mb-3" method="get">
                    <div class="col-lg-4">
                        <input
                            type="text"
                            name="q"
                            class="form-control"
                            placeholder="Search name, SKU, category, collection..."
                            value="<?php echo htmlspecialchars($search); ?>"
                        >
                    </div>

                    <div class="col-lg-2">
                        <select name="status" class="form-select">
                            <option value="">All statuses</option>
                            <option value="active"   <?php echo $status === 'active'   ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="col-lg-2">
                        <select name="gender" class="form-select">
                            <option value="">All genders</option>
                            <option value="women"  <?php echo $genderFilter === 'women'  ? 'selected' : ''; ?>>Women</option>
                            <option value="men"    <?php echo $genderFilter === 'men'    ? 'selected' : ''; ?>>Men</option>
                            <option value="unisex" <?php echo $genderFilter === 'unisex' ? 'selected' : ''; ?>>Unisex</option>
                        </select>
                    </div>

                    <div class="col-lg-2">
                        <select name="category" class="form-select">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int)$cat['id']; ?>"
                                    <?php echo ($categoryId !== '' && (int)$categoryId === (int)$cat['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-2 d-flex flex-wrap gap-2 mt-1">
                        <button type="submit" class="btn btn-outline-secondary">
                            <i class='bx bx-search-alt-2 me-1'></i> Filter
                        </button>
                        <a href="products.php" class="btn btn-link">
                            Reset
                        </a>
                    </div>
                </form>

                <?php if (empty($products)): ?>
                    <p class="text-muted mb-0">No products found for the current filters.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Category</th>
                                <th>Gender</th>
                                <th class="text-end">Price</th>
                                <th class="text-end">Stock</th>
                                <th>Status / Tags</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($products as $p): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="product-thumb">
                                                <?php if (!empty($p['image_url'])): ?>
                                                    <img src="<?php echo '../' . htmlspecialchars($p['image_url']); ?>" alt="">
                                                <?php else: ?>
                                                    <i class='bx bx-image-alt text-muted'></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($p['name']); ?></strong><br>
                                                <?php if (!empty($p['collection'])): ?>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($p['collection']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($p['sku'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($p['category_name'] ?? '—'); ?></td>
                                    <td class="text-capitalize">
                                        <?php echo htmlspecialchars($p['gender']); ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($p['on_sale'] && !is_null($p['sale_price'])): ?>
                                            <div>
                                                <span class="text-decoration-line-through text-muted me-1">
                                                    <?php echo money_fmt($p['price']); ?>
                                                </span>
                                                <span class="fw-semibold">
                                                    <?php echo money_fmt($p['sale_price']); ?>
                                                </span>
                                            </div>
                                            <span class="badge badge-soft badge-onsale">On sale</span>
                                        <?php else: ?>
                                            <span class="fw-semibold">
                                                <?php echo money_fmt($p['price']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php echo (int)$p['stock']; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php if ($p['is_active']): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                    Active
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                                    Inactive
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($p['is_new']): ?>
                                                <span class="badge badge-soft badge-new">New</span>
                                            <?php endif; ?>

                                            <?php if ($p['is_hot']): ?>
                                                <span class="badge badge-soft badge-hot">Hot</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <a href="product-edit.php?id=<?php echo (int)$p['id']; ?>"
                                               class="btn btn-sm btn-light">
                                                <i class='bx bx-edit-alt'></i>
                                            </a>

                                            <form method="post" class="d-inline"
                                                  onsubmit="return confirm('Delete this product? This may fail if it is used in orders.');">
                                                <input type="hidden" name="delete_id" value="<?php echo (int)$p['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class='bx bx-trash'></i>
                                                </button>
                                            </form>

                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="toggle_active_id" value="<?php echo (int)$p['id']; ?>">
                                                <input type="hidden" name="new_status"
                                                       value="<?php echo $p['is_active'] ? 0 : 1; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                    <?php if ($p['is_active']): ?>
                                                        <i class='bx bx-hide'></i>
                                                    <?php else: ?>
                                                        <i class='bx bx-show'></i>
                                                    <?php endif; ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TOP PRODUCTS (RANKING PANEL) -->
        <div class="admin-panel">
            <div class="admin-panel-header">
                <div>
                    <h2 class="admin-panel-title mb-0">Top Sold Products</h2>
                    <p class="admin-panel-subtitle mb-0">
                        Based on quantity sold in paid / shipped / completed orders.
                    </p>
                </div>
                <a href="products-ranking.php" class="btn btn-sm btn-outline-secondary rounded-pill">
                    View full ranking
                </a>
            </div>
            <div class="admin-panel-body">
                <?php if (empty($top_products)): ?>
                    <p class="text-muted mb-0">No sales data yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th class="text-end">Qty sold</th>
                                <th class="text-end">Revenue</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php $rank = 1; ?>
                            <?php foreach ($top_products as $tp): ?>
                                <tr>
                                    <td><?php echo $rank++; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="product-thumb" style="width:36px;height:36px;">
                                                <?php if (!empty($tp['image_url'])): ?>
                                                    <img src="<?php echo '../' . htmlspecialchars($tp['image_url']); ?>" alt="">
                                                <?php else: ?>
                                                    <i class='bx bx-image-alt text-muted'></i>
                                                <?php endif; ?>
                                            </div>
                                            <a href="product-edit.php?id=<?php echo (int)$tp['id']; ?>">
                                                <?php echo htmlspecialchars($tp['name']); ?>
                                            </a>
                                        </div>
                                    </td>
                                    <td class="text-end"><?php echo (int)$tp['total_qty']; ?></td>
                                    <td class="text-end"><?php echo money_fmt($tp['total_revenue']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* ============ Mobile sidebar menu (no sidebar.php edits needed) ============ */
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

document.addEventListener('DOMContentLoaded', setupMobileSidebar);
</script>

</body>
</html>
