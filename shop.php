<?php
// shop.php – Storefront listing with filtering + pagination (6 per page) + fixed single-product sizing
session_start();
require_once 'db.php';

/* ----------------- helpers ----------------- */
function money_fmt($v) {
    return '$' . number_format((float)$v, 2);
}

function build_store_url(array $params = []): string {
    $base  = 'shop.php';
    $query = http_build_query($params);
    return $query ? $base . '?' . $query : $base;
}

function db_name(mysqli $conn): string {
    $res = $conn->query("SELECT DATABASE() AS db");
    $row = $res ? $res->fetch_assoc() : null;
    return $row['db'] ?? '';
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $db = db_name($conn);
    if ($db === '') return false;

    $sql = "SELECT COUNT(*) AS c
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('sss', $db, $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function placeholders(int $n): string {
    if ($n <= 0) return '';
    return implode(',', array_fill(0, $n, '?'));
}

function parse_int_list($value): array {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $v) {
            if (ctype_digit((string)$v)) $out[] = (int)$v;
        }
        return array_values(array_unique($out));
    }
    $value = trim((string)$value);
    if ($value === '') return [];
    $parts = preg_split('/\s*,\s*/', $value);
    $out = [];
    foreach ($parts as $p) {
        if (ctype_digit($p)) $out[] = (int)$p;
    }
    return array_values(array_unique($out));
}

function parse_str_list($value): array {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $v) {
            $v = trim((string)$v);
            if ($v !== '') $out[] = $v;
        }
        return array_values(array_unique($out));
    }
    $value = trim((string)$value);
    if ($value === '') return [];
    $parts = preg_split('/\s*,\s*/', $value);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $out[] = $p;
    }
    return array_values(array_unique($out));
}

/* ----------------- header data ----------------- */

// wishlist & cart counts for header
$wishlist_ids   = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])
    ? $_SESSION['wishlist']
    : [];
$wishlist_count = count($wishlist_ids);

// cart count (supports both qty int and ['qty'=>..] format)
$cart_items = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $pid => $entry) {
        if (is_array($entry)) $cart_items += (int)($entry['qty'] ?? 0);
        else $cart_items += (int)$entry;
    }
}

// current customer (storefront user)
$customer_id    = $_SESSION['customer_id']   ?? null;
$customer_name  = $_SESSION['customer_name'] ?? '';
$customer_first = '';
if ($customer_name !== '') {
    $parts = preg_split('/\s+/', trim($customer_name));
    $customer_first = $parts[0] ?? $customer_name;
}

/* ----------------- detect optional columns ----------------- */
$genderCol = column_exists($conn, 'products', 'gender') ? 'gender' : null;

// clothing type column: prefer clothing_type, fallback to type
$typeCol = null;
if (column_exists($conn, 'products', 'clothing_type')) $typeCol = 'clothing_type';
elseif (column_exists($conn, 'products', 'type')) $typeCol = 'type';

/* ----------------- load categories ----------------- */
$categories = [];
$catSql = "SELECT id, name FROM categories ORDER BY name ASC";
if ($res = $conn->query($catSql)) {
    while ($row = $res->fetch_assoc()) $categories[] = $row;
    $res->free();
}

/* ----------------- build filter options (facets) ----------------- */
$genderOptions = [];
if ($genderCol) {
    $sql = "SELECT DISTINCT {$genderCol} AS v
            FROM products
            WHERE is_active = 1 AND {$genderCol} IS NOT NULL AND {$genderCol} <> ''
            ORDER BY v ASC";
    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) $genderOptions[] = $row['v'];
        $res->free();
    }
}

$typeOptions = [];
if ($typeCol) {
    $sql = "SELECT DISTINCT {$typeCol} AS v
            FROM products
            WHERE is_active = 1 AND {$typeCol} IS NOT NULL AND {$typeCol} <> ''
            ORDER BY v ASC";
    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) $typeOptions[] = $row['v'];
        $res->free();
    }
}

// sizes: from comma-separated products.sizes
$sizeOptions = [];
if ($res = $conn->query("SELECT sizes FROM products WHERE is_active = 1 AND sizes IS NOT NULL AND sizes <> ''")) {
    while ($row = $res->fetch_assoc()) {
        $raw = (string)$row['sizes'];
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        foreach ($parts as $s) $sizeOptions[] = $s;
    }
    $res->free();
}
$sizeOptions = array_values(array_unique($sizeOptions));
natcasesort($sizeOptions);
$sizeOptions = array_values($sizeOptions);

// price bounds for UI hint
$priceMinDb = 0.0;
$priceMaxDb = 0.0;
$priceExpr = "CASE WHEN on_sale = 1 AND sale_price > 0 THEN sale_price ELSE price END";
$sql = "SELECT MIN($priceExpr) AS minp, MAX($priceExpr) AS maxp
        FROM products
        WHERE is_active = 1";
if ($res = $conn->query($sql)) {
    $row = $res->fetch_assoc();
    $priceMinDb = (float)($row['minp'] ?? 0);
    $priceMaxDb = (float)($row['maxp'] ?? 0);
    $res->free();
}

/* ----------------- read incoming filters ----------------- */

// search
$q = trim($_GET['q'] ?? '');

// categories (multi)
$selectedCategories = parse_int_list($_GET['category'] ?? []);

// gender (multi)
$selectedGenders = $genderCol ? parse_str_list($_GET['gender'] ?? []) : [];

// clothing type (multi)
$selectedTypes = $typeCol ? parse_str_list($_GET['type'] ?? []) : [];

// sizes (multi)
$selectedSizes = parse_str_list($_GET['size'] ?? []);

// price range
$minPrice = $_GET['min_price'] ?? '';
$maxPrice = $_GET['max_price'] ?? '';
$minPrice = (is_numeric($minPrice) && $minPrice !== '') ? (float)$minPrice : null;
$maxPrice = (is_numeric($maxPrice) && $maxPrice !== '') ? (float)$maxPrice : null;

// toggles
$onlySale = isset($_GET['sale']) && $_GET['sale'] === '1';
$onlyNew  = isset($_GET['new'])  && $_GET['new']  === '1';
$onlyHot  = isset($_GET['hot'])  && $_GET['hot']  === '1';

// sort
$allowedSort = ['newest', 'price_low', 'price_high'];
$sort = $_GET['sort'] ?? 'newest';
if (!in_array($sort, $allowedSort, true)) $sort = 'newest';

// pagination (6 per page)
$perPage = 6;
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

/* ----------------- build SQL (prepared) ----------------- */
$selectCols = "
    id, name, sku, price, sale_price, on_sale,
    image_url, collection, is_new, is_hot, category_id
";
if ($genderCol) $selectCols .= ", {$genderCol} AS gender";
if ($typeCol)   $selectCols .= ", {$typeCol} AS clothing_type";
$selectCols .= ", sizes";

$where = [];
$params = [];
$types  = "";

// base
$where[] = "is_active = 1";

// categories
if (!empty($selectedCategories)) {
    $where[] = "category_id IN (" . placeholders(count($selectedCategories)) . ")";
    foreach ($selectedCategories as $cid) { $types .= "i"; $params[] = $cid; }
}

// gender
if ($genderCol && !empty($selectedGenders)) {
    $where[] = "{$genderCol} IN (" . placeholders(count($selectedGenders)) . ")";
    foreach ($selectedGenders as $g) { $types .= "s"; $params[] = $g; }
}

// clothing type
if ($typeCol && !empty($selectedTypes)) {
    $where[] = "{$typeCol} IN (" . placeholders(count($selectedTypes)) . ")";
    foreach ($selectedTypes as $t) { $types .= "s"; $params[] = $t; }
}

// size filter (comma-separated sizes column)
if (!empty($selectedSizes)) {
    $sizeWheres = [];
    foreach ($selectedSizes as $s) {
        $sClean = str_replace(' ', '', $s);
        $sizeWheres[] = "CONCAT(',', REPLACE(IFNULL(sizes,''),' ',''), ',') LIKE ?";
        $types .= "s";
        $params[] = "%," . $sClean . ",%";
    }
    $where[] = "(" . implode(" OR ", $sizeWheres) . ")";
}

// price range (based on effective price)
if ($minPrice !== null) {
    $where[] = "($priceExpr) >= ?";
    $types .= "d";
    $params[] = $minPrice;
}
if ($maxPrice !== null) {
    $where[] = "($priceExpr) <= ?";
    $types .= "d";
    $params[] = $maxPrice;
}

// toggles
if ($onlySale) $where[] = "(on_sale = 1 AND sale_price > 0)";
if ($onlyNew)  $where[] = "is_new = 1";
if ($onlyHot)  $where[] = "is_hot = 1";

// search
if ($q !== '') {
    $where[] = "(name LIKE ? OR sku LIKE ? OR collection LIKE ?)";
    $safeQ = "%" . $q . "%";
    $types .= "sss";
    $params[] = $safeQ;
    $params[] = $safeQ;
    $params[] = $safeQ;
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// count query
$total = 0;
$countSql = "SELECT COUNT(*) AS c FROM products $whereSql";
$stmt = $conn->prepare($countSql);
if ($stmt) {
    if ($types !== "") $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $total = (int)($row['c'] ?? 0);
    $stmt->close();
}

// clamp page to valid range
$totalPages = (int)ceil(($total <= 0 ? 1 : $total) / $perPage);
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// order by
$orderSql = "ORDER BY id DESC";
if ($sort === 'price_low') {
    $orderSql = "ORDER BY ($priceExpr) ASC, id DESC";
} elseif ($sort === 'price_high') {
    $orderSql = "ORDER BY ($priceExpr) DESC, id DESC";
}

// data query
$productSql = "SELECT $selectCols FROM products $whereSql $orderSql LIMIT ? OFFSET ?";
$params2 = $params;
$types2  = $types . "ii";
$params2[] = $perPage;
$params2[] = $offset;

$products = [];
$stmt = $conn->prepare($productSql);
if ($stmt) {
    $stmt->bind_param($types2, ...$params2);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $products[] = $row;
    $stmt->close();
}

// build "clear filters" link
$hasAnyFilter = (
    $q !== '' ||
    !empty($selectedCategories) ||
    !empty($selectedGenders) ||
    !empty($selectedTypes) ||
    !empty($selectedSizes) ||
    $minPrice !== null ||
    $maxPrice !== null ||
    $onlySale || $onlyNew || $onlyHot ||
    $sort !== 'newest'
);

// for pagination links: keep all current query except page
function current_query_without_page(): array {
    $q = $_GET;
    unset($q['page']);
    return $q;
}
$baseQuery = current_query_without_page();

// "Showing X–Y of N"
$showFrom = ($total > 0) ? ($offset + 1) : 0;
$showTo   = ($total > 0) ? min($offset + $perPage, $total) : 0;

// single-page styling flag
$isSingleResultOnPage = (count($products) === 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Shop | Velvet Vogue</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

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
        * { box-sizing: border-box; }
        img { max-width: 100%; height: auto; display:block; }
        .vv-nav-wrapper { position: relative; }

        /* ===== Header: left hamburger on mobile/tablet ===== */
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

        /* ===== User dropdown ===== */
        .vv-user-menu { position: relative; }
        .vv-user-toggle { cursor: pointer; white-space: nowrap; }
        .vv-user-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 0.35rem);
            min-width: 170px;
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
            font-size: 0.84rem;
            color: var(--vv-text-main);
            text-decoration: none;
            border-radius: 12px;
            margin: 0 0.2rem;
        }
        .vv-user-dropdown a:hover { background: #f7f0fa; color: var(--vv-accent); }
        .vv-user-dropdown-separator { height: 1px; background: #eee4ff; margin: 0.25rem 0.6rem; }

        /* =========================================================
           MODERN SHOP LAYOUT
           ========================================================= */
        .vv-shop-shell{ padding: 1.2rem 0 2.2rem; }

        .vv-shop-topbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:1rem;
            margin-bottom: 1rem;
        }

        .vv-shop-title{ margin:0; font-size: 1.35rem; }
        .vv-shop-sub{ margin:0.2rem 0 0; color: var(--vv-text-soft); font-size: 0.9rem; }

        .vv-shop-layout{
            display:grid;
            grid-template-columns: 320px minmax(0,1fr);
            gap: 1rem;
            align-items:start;
        }

        /* ===== Sidebar filters (desktop) ===== */
        .vv-filter-panel{
            background:#fff;
            border:1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            border-radius: 22px;
            padding: 0.85rem;
            position: sticky;
            top: 1rem;
        }

        .vv-filter-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:0.75rem;
            margin-bottom: 0.6rem;
        }
        .vv-filter-head h3{ margin:0; font-size: 1rem; }
        .vv-filter-clear{
            text-decoration:none;
            font-size:0.85rem;
            color: var(--vv-accent);
            display:inline-flex;
            align-items:center;
            gap:0.25rem;
            padding:0.35rem 0.55rem;
            border-radius: 999px;
            border: 1px solid var(--vv-border-soft);
        }
        .vv-filter-clear:hover{ background:#f7f0fa; }

        .vv-filter-group{
            border-top: 1px solid #f0e8ff;
            padding-top: 0.65rem;
            margin-top: 0.65rem;
        }
        .vv-filter-group:first-of-type{
            border-top: none;
            padding-top: 0;
            margin-top: 0;
        }

        .vv-filter-group-title{
            display:flex;
            align-items:center;
            justify-content:space-between;
            margin:0 0 0.45rem;
            font-size: 0.82rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--vv-text-soft);
        }

        .vv-checklist{
            display:flex;
            flex-direction:column;
            gap:0.35rem;
            max-height: 220px;
            overflow:auto;
            padding-right: 0.25rem;
        }
        .vv-check{
            display:flex;
            align-items:center;
            gap:0.5rem;
            font-size: 0.9rem;
            color: var(--vv-text-main);
            cursor:pointer;
            user-select:none;
        }
        .vv-check input{ width: 16px; height: 16px; accent-color: var(--vv-accent); }

        .vv-pill-grid{ display:flex; flex-wrap:wrap; gap:0.45rem; }
        .vv-pill{
            display:inline-flex;
            align-items:center;
            gap:0.45rem;
            border:1px solid var(--vv-border-soft);
            background:#fff;
            border-radius: 999px;
            padding: 0.35rem 0.6rem;
            font-size: 0.85rem;
            cursor:pointer;
        }
        .vv-pill input{ accent-color: var(--vv-accent); }

        .vv-price-row{
            display:grid;
            grid-template-columns: 1fr 1fr;
            gap:0.5rem;
        }
        .vv-price-row input{
            border:1px solid var(--vv-border-soft);
            border-radius: 14px;
            padding: 0.55rem 0.65rem;
            font:inherit;
            width:100%;
            background:#fff;
        }
        .vv-price-hint{ font-size:0.8rem; color: var(--vv-text-soft); margin-top: 0.35rem; }

        .vv-filter-actions{
            margin-top: 0.8rem;
            display:flex;
            gap:0.5rem;
        }
        .vv-filter-actions .vv-btn{
            width:100%;
            justify-content:center;
            border-radius: 14px;
        }

        /* ===== Results panel ===== */
        .vv-results-panel{ background: transparent; }

        .vv-searchbar{
            background:#fff;
            border:1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            border-radius: 18px;
            padding: 0.65rem;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:0.75rem;
            margin-bottom: 0.9rem;
        }

        .vv-search-left{
            display:flex;
            align-items:center;
            gap:0.5rem;
            flex: 1 1 auto;
            border:1px solid #f0e8ff;
            border-radius: 14px;
            padding: 0.5rem 0.7rem;
            background:#fff;
        }
        .vv-search-left i{ color: var(--vv-text-soft); }
        .vv-search-left input{
            border:none;
            outline:none;
            width:100%;
            font:inherit;
            background:transparent;
        }

        .vv-search-right{
            display:flex;
            align-items:center;
            gap:0.5rem;
            flex: 0 0 auto;
        }

        .vv-sort{
            border:1px solid var(--vv-border-soft);
            border-radius: 14px;
            padding: 0.55rem 0.65rem;
            background:#fff;
            font:inherit;
        }

        .vv-filter-open-btn{
            display:none;
            border:1px solid var(--vv-border-soft);
            background:#fff;
            border-radius: 14px;
            padding: 0.55rem 0.75rem;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:0.35rem;
        }

        /* ===== Mobile filter drawer ===== */
        .vv-filter-drawer{ display:none; }
        .vv-filter-backdrop{
            display:none;
            position: fixed;
            inset:0;
            background: rgba(0,0,0,0.25);
            z-index: 80;
        }
        body.vv-filter-open .vv-filter-backdrop{ display:block; }

        @media (max-width: 991.98px){
            .vv-shop-layout{ grid-template-columns: 1fr; }
            .vv-filter-panel{ display:none; }
            .vv-filter-open-btn{ display:inline-flex; }

            .vv-filter-drawer{
                display:block;
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: min(420px, 92vw);
                background:#fff;
                z-index: 90;
                border-right:1px solid var(--vv-border-soft);
                box-shadow: var(--vv-shadow-subtle);
                transform: translateX(-103%);
                transition: 180ms ease;
                padding: 0.85rem;
                overflow:auto;
            }
            body.vv-filter-open .vv-filter-drawer{ transform: translateX(0); }

            .vv-filter-drawer .vv-filter-panel{
                display:block;
                position: static;
                top:auto;
                box-shadow:none;
                border:none;
                padding:0;
                border-radius:0;
            }
        }

        /* =========================================================
           PRODUCT GRID FIX (single result must not stretch)
           ========================================================= */
        .vv-product-grid{
            display:grid !important;

            /* KEY FIX: auto-fill prevents single card from stretching wide */
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)) !important;

            gap:14px !important;
            align-items:stretch;
            justify-content:start;
        }

        /* When the current page has only 1 product, keep card size consistent */
        .vv-product-grid.vv-grid-single{
            justify-content:center;
        }
        .vv-product-grid.vv-grid-single .vv-product-card{
            max-width: 360px;
        }

        @media (max-width: 575.98px){
            .vv-product-grid{
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            /* On small screens, let it fill normally */
            .vv-product-grid.vv-grid-single .vv-product-card{
                max-width:none;
            }
        }
        @media (max-width: 360px){
            .vv-product-grid{ grid-template-columns: 1fr !important; }
        }

        .vv-product-card{
            width:100% !important;
            height:100%;
            display:flex;
            flex-direction:column;
            max-width:none; /* default */
        }

        .vv-product-media-inner{
            position:relative;
            width:100%;
            aspect-ratio: 4 / 5;
            overflow:hidden;
            border-radius:18px;
            background:#f4f3fb;
        }
        .vv-product-media-inner img{
            width:100% !important;
            height:100% !important;
            object-fit:cover;
        }
        .vv-product-media-inner img.vv-img-fallback{
            object-fit:contain !important;
            background:#f4f3fb;
        }

        .vv-product-body{
            flex:1;
            display:flex;
            flex-direction:column;
            padding:12px 12px 14px !important;
        }

        .vv-product-name{
            margin:8px 0 6px !important;
            line-height:1.15;
            display:-webkit-box;
            -webkit-line-clamp:2;
            -webkit-box-orient:vertical;
            overflow:hidden;
            min-height:2.4em;
        }

        .vv-product-meta{ margin:0 0 10px !important; }

        .vv-product-price-row{
            margin-top:auto;
            padding-top:6px;
        }

        .vv-product-actions{
            margin-top:10px !important;
            display:block;
        }
        .vv-product-actions .vv-btn,
        .vv-product-actions .vv-btn-sm{
            width:100% !important;
            display:flex !important;
            justify-content:center !important;
            align-items:center !important;
            white-space:nowrap !important;
            border-radius:14px !important;
            padding:10px 12px !important;
        }

        /* heart icon inside image */
        .vv-product-like-btn{
            position:absolute !important;
            top:10px;
            right:10px;
            z-index:3;
        }

        /* pagination */
        .vv-pagination{
            margin-top: 1rem;
            display:flex;
            gap:0.5rem;
            justify-content:center;
            flex-wrap:wrap;
        }
        .vv-page-btn{
            border:1px solid var(--vv-border-soft);
            background:#fff;
            border-radius: 999px;
            padding:0.45rem 0.75rem;
            text-decoration:none;
            color: var(--vv-text-main);
            font-size:0.9rem;
        }
        .vv-page-btn.active{
            background: var(--vv-accent);
            border-color: var(--vv-accent);
            color:#fff;
        }
        .vv-page-btn:hover{ background:#f7f0fa; }
        .vv-page-btn.active:hover{ background: var(--vv-accent); }
        .vv-page-dots{
            padding:0.45rem 0.4rem;
            color: var(--vv-text-soft);
            font-size:0.9rem;
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
            <a href="shop.php" class="active">Shop</a>
            <a href="index.php#about">About</a>
        </nav>

        <div class="vv-mobile-nav" id="vvMobileNav">
            <a href="index.php">Home</a>
            <a href="shop.php" class="active">Shop</a>
            <a href="index.php#about">About</a>
        </div>

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

<div class="vv-filter-backdrop" id="vvFilterBackdrop"></div>

<!-- Mobile filter drawer -->
<div class="vv-filter-drawer" id="vvFilterDrawer">
    <div class="vv-filter-panel"></div>
</div>

<main class="vv-shop-shell">
    <div class="vv-container">

        <div class="vv-shop-topbar">
            <div>
                <h1 class="vv-shop-title">Shop</h1>
                <p class="vv-shop-sub">
                    Showing <?php echo (int)$showFrom; ?>–<?php echo (int)$showTo; ?> of <?php echo (int)$total; ?> result(s)
                    <?php if ($q !== ''): ?> · searching “<?php echo htmlspecialchars($q); ?>”<?php endif; ?>
                </p>
            </div>

            <button type="button" class="vv-filter-open-btn" id="vvFilterToggle">
                <i class="bx bx-filter-alt"></i> Filters
            </button>
        </div>

        <div class="vv-shop-layout">

            <!-- Desktop sidebar filters -->
            <aside class="vv-filter-panel" id="vvFilterPanelDesktop">
                <div class="vv-filter-head">
                    <h3>Filters</h3>
                    <?php if ($hasAnyFilter): ?>
                        <a class="vv-filter-clear" href="shop.php"><i class="bx bx-reset"></i> Clear</a>
                    <?php endif; ?>
                </div>

                <form method="get" id="vvFilterForm" autocomplete="off">
                    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                    <input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>">

                    <!-- CATEGORY -->
                    <div class="vv-filter-group">
                        <div class="vv-filter-group-title">
                            <span>Categories</span>
                        </div>
                        <div class="vv-checklist">
                            <?php foreach ($categories as $cat): ?>
                                <?php $cid = (int)$cat['id']; ?>
                                <label class="vv-check">
                                    <input type="checkbox" name="category[]" value="<?php echo $cid; ?>"
                                        <?php echo in_array($cid, $selectedCategories, true) ? 'checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars($cat['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- GENDER -->
                    <?php if (!empty($genderOptions)): ?>
                        <div class="vv-filter-group">
                            <div class="vv-filter-group-title">
                                <span>Gender</span>
                            </div>
                            <div class="vv-pill-grid">
                                <?php foreach ($genderOptions as $g): ?>
                                    <label class="vv-pill">
                                        <input type="checkbox" name="gender[]" value="<?php echo htmlspecialchars($g); ?>"
                                            <?php echo in_array($g, $selectedGenders, true) ? 'checked' : ''; ?>>
                                        <span><?php echo htmlspecialchars($g); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- CLOTHING TYPE -->
                    <?php if (!empty($typeOptions)): ?>
                        <div class="vv-filter-group">
                            <div class="vv-filter-group-title">
                                <span>Clothing type</span>
                            </div>
                            <div class="vv-checklist">
                                <?php foreach ($typeOptions as $t): ?>
                                    <label class="vv-check">
                                        <input type="checkbox" name="type[]" value="<?php echo htmlspecialchars($t); ?>"
                                            <?php echo in_array($t, $selectedTypes, true) ? 'checked' : ''; ?>>
                                        <span><?php echo htmlspecialchars($t); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- SIZE -->
                    <?php if (!empty($sizeOptions)): ?>
                        <div class="vv-filter-group">
                            <div class="vv-filter-group-title">
                                <span>Size</span>
                            </div>
                            <div class="vv-pill-grid">
                                <?php foreach ($sizeOptions as $s): ?>
                                    <label class="vv-pill">
                                        <input type="checkbox" name="size[]" value="<?php echo htmlspecialchars($s); ?>"
                                            <?php echo in_array($s, $selectedSizes, true) ? 'checked' : ''; ?>>
                                        <span><?php echo htmlspecialchars($s); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- PRICE RANGE -->
                    <div class="vv-filter-group">
                        <div class="vv-filter-group-title">
                            <span>Price range</span>
                        </div>
                        <div class="vv-price-row">
                            <input type="number" step="0.01" name="min_price" placeholder="Min"
                                   value="<?php echo $minPrice !== null ? htmlspecialchars((string)$minPrice) : ''; ?>">
                            <input type="number" step="0.01" name="max_price" placeholder="Max"
                                   value="<?php echo $maxPrice !== null ? htmlspecialchars((string)$maxPrice) : ''; ?>">
                        </div>
                        <div class="vv-price-hint">
                            Tip: <?php echo money_fmt($priceMinDb); ?> – <?php echo money_fmt($priceMaxDb); ?>
                        </div>
                    </div>

                    <!-- QUICK TOGGLES -->
                    <div class="vv-filter-group">
                        <div class="vv-filter-group-title">
                            <span>More</span>
                        </div>
                        <div class="vv-checklist">
                            <label class="vv-check">
                                <input type="checkbox" name="sale" value="1" <?php echo $onlySale ? 'checked' : ''; ?>>
                                <span>On sale</span>
                            </label>
                            <label class="vv-check">
                                <input type="checkbox" name="new" value="1" <?php echo $onlyNew ? 'checked' : ''; ?>>
                                <span>New arrivals</span>
                            </label>
                            <label class="vv-check">
                                <input type="checkbox" name="hot" value="1" <?php echo $onlyHot ? 'checked' : ''; ?>>
                                <span>Hot / featured</span>
                            </label>
                        </div>
                    </div>

                    <div class="vv-filter-actions">
                        <button type="submit" class="vv-btn vv-btn-primary">Apply</button>
                        <?php if ($hasAnyFilter): ?>
                            <a href="shop.php" class="vv-btn vv-btn-outline">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </aside>

            <!-- Results -->
            <section class="vv-results-panel">
                <!-- Search + sort bar -->
                <form method="get" class="vv-searchbar" id="vvSearchForm">
                    <?php foreach ($selectedCategories as $cid): ?>
                        <input type="hidden" name="category[]" value="<?php echo (int)$cid; ?>">
                    <?php endforeach; ?>
                    <?php foreach ($selectedGenders as $g): ?>
                        <input type="hidden" name="gender[]" value="<?php echo htmlspecialchars($g); ?>">
                    <?php endforeach; ?>
                    <?php foreach ($selectedTypes as $t): ?>
                        <input type="hidden" name="type[]" value="<?php echo htmlspecialchars($t); ?>">
                    <?php endforeach; ?>
                    <?php foreach ($selectedSizes as $s): ?>
                        <input type="hidden" name="size[]" value="<?php echo htmlspecialchars($s); ?>">
                    <?php endforeach; ?>

                    <?php if ($minPrice !== null): ?>
                        <input type="hidden" name="min_price" value="<?php echo htmlspecialchars((string)$minPrice); ?>">
                    <?php endif; ?>
                    <?php if ($maxPrice !== null): ?>
                        <input type="hidden" name="max_price" value="<?php echo htmlspecialchars((string)$maxPrice); ?>">
                    <?php endif; ?>

                    <?php if ($onlySale): ?><input type="hidden" name="sale" value="1"><?php endif; ?>
                    <?php if ($onlyNew):  ?><input type="hidden" name="new"  value="1"><?php endif; ?>
                    <?php if ($onlyHot):  ?><input type="hidden" name="hot"  value="1"><?php endif; ?>

                    <div class="vv-search-left">
                        <i class="bx bx-search"></i>
                        <input type="text" name="q" placeholder="Search products, SKU, collection..."
                               value="<?php echo htmlspecialchars($q); ?>">
                    </div>

                    <div class="vv-search-right">
                        <select class="vv-sort" name="sort" onchange="this.form.submit()">
                            <option value="newest" <?php echo $sort==='newest'?'selected':''; ?>>Newest</option>
                            <option value="price_low" <?php echo $sort==='price_low'?'selected':''; ?>>Price: low → high</option>
                            <option value="price_high" <?php echo $sort==='price_high'?'selected':''; ?>>Price: high → low</option>
                        </select>
                        <button type="submit" class="vv-btn vv-btn-secondary-sm">Search</button>
                    </div>
                </form>

                <?php if (empty($products)): ?>
                    <p class="text-muted" style="margin-top:0.5rem;">No products found for this selection.</p>
                <?php else: ?>
                    <div class="vv-product-grid <?php echo $isSingleResultOnPage ? 'vv-grid-single' : ''; ?>">
                        <?php foreach ($products as $p): ?>
                            <?php
                            $prod_id   = (int)$p['id'];
                            $isLiked   = in_array($prod_id, $wishlist_ids, true);
                            $likeClass = $isLiked ? 'is-liked' : '';
                            $likeIcon  = $isLiked ? 'bxs-heart' : 'bx-heart';
                            ?>
                            <article class="vv-product-card">
                                <div class="vv-product-media">
                                    <div class="vv-product-media-inner">
                                        <?php if (!empty($p['image_url'])): ?>
                                            <img
                                                src="<?php echo htmlspecialchars($p['image_url']); ?>"
                                                alt="<?php echo htmlspecialchars($p['name']); ?>"
                                                loading="lazy"
                                                data-fallback-text="Product image"
                                                onerror="vvImgFallback(this)"
                                            >
                                        <?php else: ?>
                                            <div class="vv-product-placeholder">
                                                <i class="bx bx-image-alt"></i>
                                            </div>
                                        <?php endif; ?>

                                        <div class="vv-product-badges">
                                            <?php if ((int)$p['on_sale'] === 1 && (float)$p['sale_price'] > 0): ?>
                                                <span class="vv-badge vv-badge-sale">Sale</span>
                                            <?php endif; ?>
                                            <?php if ((int)$p['is_new'] === 1): ?>
                                                <span class="vv-badge vv-badge-new">New</span>
                                            <?php endif; ?>
                                            <?php if ((int)$p['is_hot'] === 1): ?>
                                                <span class="vv-badge vv-badge-hot">Hot</span>
                                            <?php endif; ?>
                                        </div>

                                        <button type="button"
                                                class="vv-product-like-btn <?php echo $likeClass; ?>"
                                                data-product-id="<?php echo $prod_id; ?>"
                                                aria-label="Toggle wishlist">
                                            <i class="bx <?php echo $likeIcon; ?>"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="vv-product-body">
                                    <h3 class="vv-product-name"><?php echo htmlspecialchars($p['name']); ?></h3>

                                    <?php if (!empty($p['collection'])): ?>
                                        <p class="vv-product-meta"><?php echo htmlspecialchars($p['collection']); ?> collection</p>
                                    <?php else: ?>
                                        <p class="vv-product-meta">SKU: <?php echo htmlspecialchars($p['sku']); ?></p>
                                    <?php endif; ?>

                                    <div class="vv-product-price-row">
                                        <?php if ((float)$p['sale_price'] > 0 && (int)$p['on_sale'] === 1): ?>
                                            <span class="vv-price-main"><?php echo money_fmt($p['sale_price']); ?></span>
                                            <span class="vv-price-old"><?php echo money_fmt($p['price']); ?></span>
                                        <?php else: ?>
                                            <span class="vv-price-main"><?php echo money_fmt($p['price']); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="vv-product-actions">
                                        <a href="product-details.php?id=<?php echo $prod_id; ?>"
                                           class="vv-btn vv-btn-outline vv-btn-sm">
                                            View details
                                        </a>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="vv-pagination">
                            <?php
                            // Prev
                            if ($page > 1) {
                                $qPrev = $baseQuery; $qPrev['page'] = $page - 1;
                                echo '<a class="vv-page-btn" href="'.htmlspecialchars(build_store_url($qPrev)).'">Prev</a>';
                            }

                            // windowed pages + first/last
                            $window = 2;
                            $start = max(1, $page - $window);
                            $end   = min($totalPages, $page + $window);

                            // First
                            if ($start > 1) {
                                $qFirst = $baseQuery; $qFirst['page'] = 1;
                                $cls = (1 === $page) ? 'vv-page-btn active' : 'vv-page-btn';
                                echo '<a class="'.$cls.'" href="'.htmlspecialchars(build_store_url($qFirst)).'">1</a>';
                                if ($start > 2) echo '<span class="vv-page-dots">…</span>';
                            }

                            // Middle window
                            for ($i = $start; $i <= $end; $i++) {
                                $qPage = $baseQuery; $qPage['page'] = $i;
                                $cls = ($i === $page) ? 'vv-page-btn active' : 'vv-page-btn';
                                echo '<a class="'.$cls.'" href="'.htmlspecialchars(build_store_url($qPage)).'">'.$i.'</a>';
                            }

                            // Last
                            if ($end < $totalPages) {
                                if ($end < $totalPages - 1) echo '<span class="vv-page-dots">…</span>';
                                $qLast = $baseQuery; $qLast['page'] = $totalPages;
                                $cls = ($totalPages === $page) ? 'vv-page-btn active' : 'vv-page-btn';
                                echo '<a class="'.$cls.'" href="'.htmlspecialchars(build_store_url($qLast)).'">'.$totalPages.'</a>';
                            }

                            // Next
                            if ($page < $totalPages) {
                                $qNext = $baseQuery; $qNext['page'] = $page + 1;
                                echo '<a class="vv-page-btn" href="'.htmlspecialchars(build_store_url($qNext)).'">Next</a>';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
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
            <p class="vv-footer-copy">Curated pieces, considered details and a smoother online shopping experience.</p>
            <p class="vv-footer-copy-small">&copy; <?php echo date('Y'); ?> Velvet Vogue. All rights reserved.</p>
        </div>

        <div class="vv-footer-column">
            <h4>Shop</h4>
            <ul class="vv-footer-list">
                <li><a href="shop.php">All products</a></li>
                <li><a href="shop.php?new=1">New arrivals</a></li>
                <li><a href="shop.php?sale=1">On sale</a></li>
            </ul>
        </div>

        <div class="vv-footer-column">
            <h4>Support</h4>
            <ul class="vv-footer-list">
                <li><a href="#">Shipping &amp; returns</a></li>
                <li><a href="contactsupport.php">Contact support</a></li>
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
document.addEventListener('DOMContentLoaded', () => {
  // -------- Header mobile nav --------
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
    toggle.addEventListener('click', (e) => {
      e.stopPropagation();
      userMenu.classList.toggle('open');
    });
    document.addEventListener('click', () => userMenu.classList.remove('open'));
  }

  // -------- Mobile filter drawer --------
  const filterToggle = document.getElementById('vvFilterToggle');
  const filterDrawer = document.getElementById('vvFilterDrawer');
  const filterBackdrop = document.getElementById('vvFilterBackdrop');
  const desktopPanel = document.getElementById('vvFilterPanelDesktop');

  // Copy desktop filter panel into drawer
  if (filterDrawer && desktopPanel) {
    const drawerPanel = filterDrawer.querySelector('.vv-filter-panel');
    if (drawerPanel && drawerPanel.innerHTML.trim() === '') {
      drawerPanel.innerHTML = desktopPanel.innerHTML;

      // Fix duplicate IDs inside drawer
      const form = drawerPanel.querySelector('#vvFilterForm');
      if (form) form.id = 'vvFilterFormMobile';
    }
  }

  function closeFilters() {
    document.body.classList.remove('vv-filter-open');
  }

  if (filterToggle) {
    filterToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      document.body.classList.toggle('vv-filter-open');
    });
  }

  if (filterBackdrop) filterBackdrop.addEventListener('click', closeFilters);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeFilters();
  });

  if (filterDrawer) filterDrawer.addEventListener('click', (e) => e.stopPropagation());

  // -------- Wishlist AJAX (event delegation) --------
  const wishlistCountEl = document.getElementById('wishlistCount');

  function formBody(obj) {
    return Object.keys(obj).map(k => encodeURIComponent(k)+'='+encodeURIComponent(obj[k])).join('&');
  }

  async function postForm(url, data) {
    const res = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: formBody(data),
      credentials: 'same-origin'
    });
    return res.json();
  }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.vv-product-like-btn');
    if (!btn) return;

    const productId = btn.dataset.productId;
    if (!productId) return;

    const isLiked = btn.classList.contains('is-liked');
    const action  = isLiked ? 'remove' : 'add';

    btn.disabled = true;

    try {
      const data = await postForm('wishlist.php', { product_id: productId, action });
      if (!data || !data.success) return;

      const icon = btn.querySelector('i');

      if (data.in_wishlist) {
        btn.classList.add('is-liked');
        if (icon) { icon.classList.remove('bx-heart'); icon.classList.add('bxs-heart'); }
      } else {
        btn.classList.remove('is-liked');
        if (icon) { icon.classList.add('bx-heart'); icon.classList.remove('bxs-heart'); }
      }

      if (wishlistCountEl && typeof data.count !== 'undefined') {
        wishlistCountEl.textContent = data.count;
      }
    } catch (err) {
      console.error(err);
    } finally {
      btn.disabled = false;
    }
  });
});
</script>
</body>
</html>
