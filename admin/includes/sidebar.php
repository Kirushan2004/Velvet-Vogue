<?php
// admin/includes/sidebar.php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar">
    <!-- Mobile close button -->
    <button type="button" class="sidebar-close-btn d-lg-none" id="adminSidebarClose" aria-label="Close menu">
        <i class='bx bx-x'></i>
    </button>

    <div class="sidebar-brand">
        <span class="brand-main">Velvet</span>
        <span class="brand-sub">Vogue</span>
        <span class="brand-tag">Admin Panel</span>
    </div>

    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-item <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>">
            <i class='bx bx-grid-alt'></i>
            <span>Dashboard</span>
        </a>

        <a href="products.php" class="nav-item <?php echo $currentPage === 'products.php' ? 'active' : ''; ?>">
            <i class='bx bxs-t-shirt'></i>
            <span>Products</span>
        </a>

        <a href="promotions.php" class="nav-item <?php echo $currentPage === 'promotions.php' ? 'active' : ''; ?>">
            <i class='bx bx-purchase-tag-alt'></i>
            <span>Promotions</span>
        </a>

        <a href="reports.php" class="nav-item <?php echo $currentPage === 'reports.php' ? 'active' : ''; ?>">
            <i class='bx bx-bar-chart-square'></i>
            <span>Reports</span>
        </a>

        <a href="customers.php" class="nav-item <?php echo $currentPage === 'customers.php' ? 'active' : ''; ?>">
            <i class='bx bx-user'></i>
            <span>Customers</span>
        </a>

        <a href="orders.php" class="nav-item <?php echo $currentPage === 'orders.php' ? 'active' : ''; ?>">
            <i class='bx bx-receipt'></i>
            <span>Orders</span>
        </a>

        <a href="complaints.php" class="nav-item <?php echo $currentPage === 'complaints.php' ? 'active' : ''; ?>">
            <i class='bx bx-message-square-dots'></i>
            <span>Complaints</span>
        </a>

        <a href="../index.php" target="_blank" class="nav-item">
            <i class='bx bx-store-alt'></i>
            <span>View Store</span>
        </a>

        <a href="logout.php" class="nav-item">
            <i class='bx bx-log-out'></i>
            <span>Logout</span>
        </a>
    </nav>
</aside>
