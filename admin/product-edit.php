<?php
// admin/product-edit.php
session_start();
require_once '../db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// Helpers
function clean($v) { return trim($v ?? ''); }
function redirect_with_msg($url, $msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    header("Location: {$url}");
    exit;
}

// Upload config
$upload_rel_base = 'uploads/products/';
$upload_dir      = __DIR__ . '/../' . $upload_rel_base;
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

// identify mode
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit    = $product_id > 0;

$errors = [];
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// load categories
$categories = [];
$res = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
    $res->free();
}

// default product data
$product = [
    'name'        => '',
    'description' => '',
    'sku'         => '',
    'category_id' => null,
    'gender'      => 'unisex',
    'sizes'       => '',
    'colors'      => '',
    'stock'       => 0,
    'cost_price'  => 0.00,
    'price'       => 0.00,
    'sale_price'  => 0.00,
    'on_sale'     => 0,
    'image_url'   => '',
    'collection'  => '',
    'is_new'      => 0,
    'is_hot'      => 0,
    'is_active'   => 1,
];

// load existing product
if ($is_edit) {
    $stmt = $conn->prepare("
        SELECT *
        FROM products
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $product = $row;
    } else {
        redirect_with_msg('products.php', 'Product not found.', 'danger');
    }
    $stmt->close();
}

// handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Delete product
    if (isset($_POST['delete_product']) && $is_edit) {

        // delete uploaded file only if it is in our uploads folder
        if (!empty($product['image_url']) &&
            str_starts_with($product['image_url'], $upload_rel_base)) {

            $old = __DIR__ . '/../' . $product['image_url'];
            if (is_file($old)) {
                @unlink($old);
            }
        }

        try {
            $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
            $stmt->bind_param('i', $product_id);
            if ($stmt->execute()) {
                redirect_with_msg('products.php', 'Product deleted.');
            } else {
                redirect_with_msg(
                    "product-edit.php?id={$product_id}",
                    'Unable to delete this product because it is linked to orders.',
                    'danger'
                );
            }
        } catch (Throwable $e) {
            redirect_with_msg(
                "product-edit.php?id={$product_id}",
                'Unable to delete this product because it is linked to orders.',
                'danger'
            );
        }
    }

    // Save (add / edit)
    $name        = clean($_POST['name'] ?? '');
    $description = clean($_POST['description'] ?? '');
    $sku         = clean($_POST['sku'] ?? '');
    $category_id = $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
    $gender      = clean($_POST['gender'] ?? 'unisex');
    $sizes       = clean($_POST['sizes'] ?? '');
    $colors      = clean($_POST['colors'] ?? '');
    $stock       = (int)($_POST['stock'] ?? 0);
    $cost_price  = (float)($_POST['cost_price'] ?? 0);
    $price       = (float)($_POST['price'] ?? 0);
    $sale_price  = ($_POST['sale_price'] === '' ? 0 : (float)$_POST['sale_price']);
    $on_sale     = isset($_POST['on_sale']) ? 1 : 0;
    $collection  = clean($_POST['collection'] ?? '');
    $is_new      = isset($_POST['is_new']) ? 1 : 0;
    $is_hot      = isset($_POST['is_hot']) ? 1 : 0;
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    $image_to_save = $product['image_url'] ?? '';

    // validation
    if ($name === '') {
        $errors[] = 'Product name is required.';
    }
    if ($sku === '') {
        $errors[] = 'SKU is required.';
    }
    if ($price <= 0) {
        $errors[] = 'Price must be greater than 0.';
    }

    // unique SKU
    $stmt = $conn->prepare(
        $is_edit
            ? "SELECT id FROM products WHERE sku = ? AND id != ? LIMIT 1"
            : "SELECT id FROM products WHERE sku = ? LIMIT 1"
    );
    if ($is_edit) {
        $stmt->bind_param('si', $sku, $product_id);
    } else {
        $stmt->bind_param('s', $sku);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->fetch_assoc()) {
        $errors[] = 'Another product already uses this SKU.';
    }
    $stmt->close();

    // handle image upload
    if (!empty($_FILES['image']['name'])) {
        $file = $_FILES['image'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Error uploading product image.';
        } elseif ($file['size'] > 3 * 1024 * 1024) {
            $errors[] = 'Product image must be smaller than 3MB.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (!in_array($ext, $allowed, true)) {
                $errors[] = 'Image must be JPG, JPEG, PNG, GIF, or WEBP.';
            } else {
                $new_filename = uniqid('prod_', true) . '.' . $ext;
                $dest_path    = $upload_dir . $new_filename;
                $rel_path     = $upload_rel_base . $new_filename;

                if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
                    $errors[] = 'Could not save product image on the server.';
                } else {
                    // delete previous upload only if it was in uploads folder
                    if (!empty($product['image_url']) &&
                        str_starts_with($product['image_url'], $upload_rel_base)) {

                        $old = __DIR__ . '/../' . $product['image_url'];
                        if (is_file($old)) {
                            @unlink($old);
                        }
                    }
                    $image_to_save = $rel_path;
                }
            }
        }
    }

    if (empty($errors)) {
        if ($is_edit) {
            // UPDATE (18 params)
            $stmt = $conn->prepare("
                UPDATE products
                SET name = ?, description = ?, sku = ?, category_id = ?, gender = ?, sizes = ?, colors = ?,
                    stock = ?, cost_price = ?, price = ?, sale_price = ?, on_sale = ?, image_url = ?,
                    collection = ?, is_new = ?, is_hot = ?, is_active = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                'sssisssidddissiiii',
                $name,
                $description,
                $sku,
                $category_id,
                $gender,
                $sizes,
                $colors,
                $stock,
                $cost_price,
                $price,
                $sale_price,
                $on_sale,
                $image_to_save,
                $collection,
                $is_new,
                $is_hot,
                $is_active,
                $product_id
            );

            if ($stmt->execute()) {
                $stmt->close();
                redirect_with_msg("product-edit.php?id={$product_id}", 'Product updated successfully.');
            } else {
                $errors[] = 'Database error while updating product.';
            }
        } else {
            // INSERT (17 params)
            $stmt = $conn->prepare("
                INSERT INTO products
                    (name, description, sku, category_id, gender, sizes, colors,
                     stock, cost_price, price, sale_price, on_sale, image_url,
                     collection, is_new, is_hot, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");

            $stmt->bind_param(
                'sssisssidddissiii',
                $name,
                $description,
                $sku,
                $category_id,
                $gender,
                $sizes,
                $colors,
                $stock,
                $cost_price,
                $price,
                $sale_price,
                $on_sale,
                $image_to_save,
                $collection,
                $is_new,
                $is_hot,
                $is_active
            );

            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                $stmt->close();
                redirect_with_msg("product-edit.php?id={$new_id}", 'Product created successfully.');
            } else {
                $errors[] = 'Database error while creating product.';
            }
        }
    }

    // keep form values on error
    $product = array_merge($product, [
        'name'        => $name,
        'description' => $description,
        'sku'         => $sku,
        'category_id' => $category_id,
        'gender'      => $gender,
        'sizes'       => $sizes,
        'colors'      => $colors,
        'stock'       => $stock,
        'cost_price'  => $cost_price,
        'price'       => $price,
        'sale_price'  => $sale_price,
        'on_sale'     => $on_sale,
        'image_url'   => $image_to_save,
        'collection'  => $collection,
        'is_new'      => $is_new,
        'is_hot'      => $is_hot,
        'is_active'   => $is_active,
    ]);
}

// values for preview / modal
$hasProductImage = !empty($product['image_url']);
$productImgSrc   = $hasProductImage ? '../' . $product['image_url'] : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $is_edit ? 'Edit Product' : 'Add Product'; ?> | Velvet Vogue Admin</title>
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
        /* Small helper styling for product image box (like customer photo) */
        .product-photo-box {
            width: 140px;
            height: 140px;
            border-radius: 16px;
            border: 1px dashed #ddd;
            background-color: #fafafa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            cursor: pointer;
            position: relative;
        }
        .product-photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .product-photo-placeholder {
            font-size: 2.5rem;
            color: #c7c7c7;
        }
        .product-photo-hint {
            font-size: 0.8rem;
            color: #777;
        }

        /* ======= shared preview modal style (same as customer) ======= */
        .image-preview-modal .modal-dialog {
            max-width: 540px;
        }
        .image-preview-modal-content {
            border-radius: 18px;
            overflow: hidden;
            border: none;
        }
        .image-preview-body {
            padding: 0.75rem;
            background: #ffffff;
        }
        .image-preview-img {
            display: block;
            width: 100%;
            height: auto;
            max-height: 70vh;
            object-fit: contain;
        }
        .image-preview-close {
            position: absolute;
            top: 0.35rem;
            right: 0.5rem;
            z-index: 5;
        }

        /* ================= Mobile Sidebar MENU (same as dashboard/products) ================= */
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
                <h1 class="admin-page-title">
                    <?php echo $is_edit ? 'Edit Product' : 'Add Product'; ?>
                </h1>
                <p class="admin-page-subtitle mb-0">
                    Manage product details, pricing, images and status.
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
        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2 class="admin-panel-title mb-0">
                    <?php echo $is_edit ? 'Product information' : 'New product'; ?>
                </h2>
                <div class="d-flex gap-2">
                    <a href="products.php" class="btn btn-sm btn-outline-secondary rounded-pill">
                        <i class='bx bx-list-ul me-1'></i> Back to list
                    </a>

                    <?php if ($is_edit): ?>
                        <form method="post" onsubmit="return confirm('Delete this product?');">
                            <input type="hidden" name="delete_product" value="1">
                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">
                                <i class='bx bx-trash-alt me-1'></i> Delete
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-panel-body">
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                        <?php echo htmlspecialchars($flash['msg']); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $e): ?>
                                <li><?php echo htmlspecialchars($e); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" novalidate>
                    <div class="row g-3">
                        <!-- image column -->
                        <div class="col-md-3">
                            <label class="form-label">Product image</label>

                            <!-- preview box (click to open modal) -->
                            <div id="productPhotoBox" class="product-photo-box mb-2">
                                <img id="productImagePreview"
                                     src="<?php echo $hasProductImage ? htmlspecialchars($productImgSrc) : ''; ?>"
                                     alt="Product image preview"
                                     style="<?php echo $hasProductImage ? '' : 'display:none;'; ?>">

                                <i id="productPlaceholderIcon"
                                   class="bx bx-image product-photo-placeholder"
                                   style="<?php echo $hasProductImage ? 'display:none;' : ''; ?>"></i>
                            </div>

                            <div class="product-photo-hint mb-2">
                                Click image to view larger.
                            </div>

                            <input type="file" name="image" id="productImageInput"
                                   class="form-control" accept="image/*">
                            <small class="text-muted">
                                PNG, JPG, GIF, or WEBP. Max 3MB.
                                Selecting a file will show preview immediately.
                            </small>
                        </div>

                        <!-- details column -->
                        <div class="col-md-9">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Product name<span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control"
                                           value="<?php echo htmlspecialchars($product['name']); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">SKU<span class="text-danger">*</span></label>
                                    <input type="text" name="sku" class="form-control"
                                           value="<?php echo htmlspecialchars($product['sku']); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Collection</label>
                                    <input type="text" name="collection" class="form-control"
                                           value="<?php echo htmlspecialchars($product['collection']); ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Category</label>
                                    <select name="category_id" class="form-select">
                                        <option value="">-- None --</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo (int)$cat['id']; ?>"
                                                <?php echo ($product['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Gender</label>
                                    <select name="gender" class="form-select">
                                        <?php
                                        $genders = ['women' => 'Women', 'men' => 'Men', 'unisex' => 'Unisex'];
                                        foreach ($genders as $val => $label):
                                        ?>
                                            <option value="<?php echo $val; ?>"
                                                <?php echo ($product['gender'] === $val) ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Stock</label>
                                    <input type="number" name="stock" class="form-control" min="0"
                                           value="<?php echo (int)$product['stock']; ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Cost price</label>
                                    <input type="number" step="0.01" min="0" name="cost_price" class="form-control"
                                           value="<?php echo htmlspecialchars($product['cost_price']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Selling price<span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0" name="price" class="form-control"
                                           value="<?php echo htmlspecialchars($product['price']); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Sale price</label>
                                    <input type="number" step="0.01" min="0" name="sale_price" class="form-control"
                                           value="<?php echo htmlspecialchars($product['sale_price']); ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Available sizes (comma separated)</label>
                                    <input type="text" name="sizes" class="form-control"
                                           placeholder="e.g. XS,S,M,L,XL"
                                           value="<?php echo htmlspecialchars($product['sizes']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Available colors (comma separated)</label>
                                    <input type="text" name="colors" class="form-control"
                                           placeholder="e.g. Black,White,Blue"
                                           value="<?php echo htmlspecialchars($product['colors']); ?>">
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" rows="4" class="form-control"><?php
                                        echo htmlspecialchars($product['description']);
                                    ?></textarea>
                                </div>

                                <div class="col-md-12 mt-2">
                                    <div class="d-flex flex-wrap gap-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="on_sale"
                                                   name="on_sale" value="1"
                                                <?php echo $product['on_sale'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="on_sale">On sale</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="is_new"
                                                   name="is_new" value="1"
                                                <?php echo $product['is_new'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_new">New arrival</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="is_hot"
                                                   name="is_hot" value="1"
                                                <?php echo $product['is_hot'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_hot">Hot / featured</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="is_active"
                                                   name="is_active" value="1"
                                                <?php echo $product['is_active'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_active">Active product</label>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-primary rounded-pill">
                                        <i class='bx bx-save me-1'></i>
                                        <?php echo $is_edit ? 'Save changes' : 'Create product'; ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div> <!-- row -->
                </form>
            </div>
        </div>
    </main>
</div>

<!-- MODAL: large product image preview (same style as customer) -->
<div class="modal fade image-preview-modal" id="productImageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content image-preview-modal-content">
            <button type="button" class="btn-close image-preview-close" aria-label="Close"></button>
            <div class="modal-body image-preview-body">
                <img id="productModalImage"
                     src="<?php echo $hasProductImage ? htmlspecialchars($productImgSrc) : ''; ?>"
                     alt="Product image large preview"
                     class="img-fluid image-preview-img">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* ============ Mobile sidebar menu (same as dashboard/products) ============ */
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

document.addEventListener('DOMContentLoaded', function () {
    setupMobileSidebar();

    const fileInput       = document.getElementById('productImageInput');
    const previewImg      = document.getElementById('productImagePreview');
    const placeholderIcon = document.getElementById('productPlaceholderIcon');
    const photoBox        = document.getElementById('productPhotoBox');
    const modalEl         = document.getElementById('productImageModal');
    const modalImg        = document.getElementById('productModalImage');
    const closeBtn        = modalEl ? modalEl.querySelector('.image-preview-close') : null;

    // Bootstrap modal instance (manual show/hide)
    let modalInstance = null;
    if (modalEl) {
        modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
    }

    // Live preview when selecting a new file
    if (fileInput && previewImg) {
        fileInput.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function (ev) {
                const src = ev.target.result;
                previewImg.src = src;
                previewImg.style.display = 'block';
                if (placeholderIcon) placeholderIcon.style.display = 'none';
                if (modalImg) modalImg.src = src;
            };
            reader.readAsDataURL(file);
        });
    }

    // Click image box -> open modal popup
    if (photoBox && modalInstance && modalImg) {
        photoBox.addEventListener('click', function () {
            if (!previewImg || !previewImg.src) return;
            modalImg.src = previewImg.src; // sync
            modalInstance.show();
        });
    }

    // Close button -> hide modal
    if (closeBtn && modalInstance) {
        closeBtn.addEventListener('click', function () {
            modalInstance.hide();
        });
    }
});
</script>
</body>
</html>
