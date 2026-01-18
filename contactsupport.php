<?php
// contactsupport.php – Customer support / contact page
session_start();
require_once 'db.php';

// ---------- helpers ----------
function clean($v) {
    return trim($v ?? '');
}

// wishlist & cart counts for header
$wishlist_ids   = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist'])
    ? $_SESSION['wishlist']
    : [];
$wishlist_count = count($wishlist_ids);

$cart_items = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $pid => $qty) {
        $cart_items += (int)$qty;
    }
}

// current customer (storefront user)
$customer_id     = $_SESSION['customer_id']     ?? null;
$customer_name   = $_SESSION['customer_name']   ?? '';
$customer_email  = $_SESSION['customer_email']  ?? '';
$customer_first  = '';
if ($customer_name !== '') {
    $parts = preg_split('/\s+/', trim($customer_name));
    $customer_first = $parts[0] ?? $customer_name;
}

// ---------- form handling ----------
$success_message = '';
$error_message   = '';

$full_name   = $customer_name;   // default from session
$email       = $customer_email;  // default from session
$topic       = '';
$order_ref   = '';
$message_txt = '';
$preferred   = 'email';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name   = clean($_POST['full_name'] ?? $customer_name);
    $email       = clean($_POST['email'] ?? $customer_email);
    $topic       = clean($_POST['topic'] ?? '');
    $order_ref   = clean($_POST['order_ref'] ?? '');
    $message_txt = clean($_POST['message'] ?? '');
    $preferred   = clean($_POST['preferred'] ?? 'email');

    if ($full_name === '' || $email === '' || $message_txt === '') {
        $error_message = 'Please fill in your name, email and message so we can assist you.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        // Here you could:
        // - Insert into a "support_requests" table, or
        // - Send an email to your support inbox
        //
        // Example (pseudo):
        // $stmt = $conn->prepare("INSERT INTO support_requests (...) VALUES (....)");

        $success_message = 'Thank you! Your message has been received. Our team will get back to you soon.';
        // Clear form values after success
        $topic       = '';
        $order_ref   = '';
        $message_txt = '';
        $preferred   = 'email';
        if (!$customer_id) {
            // If guest, keep their name/email in the form
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Contact support | Velvet Vogue</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Boxicons -->
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <!-- Site CSS -->
    <link rel="stylesheet" href="css/style.css">

    <!-- Page-specific CSS -->
    <style>
        .vv-contact-hero {
            padding-top: 1.8rem;
            padding-bottom: 0.4rem;
        }
        .vv-contact-lead {
            font-size: 0.9rem;
            color: var(--vv-text-muted);
            max-width: 540px;
        }
        .vv-contact-pill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.4rem;
            font-size: 0.78rem;
        }
        .vv-contact-pill {
            padding: 0.18rem 0.7rem;
            border-radius: 999px;
            border: 1px solid var(--vv-border-soft);
            background: rgba(255,255,255,0.8);
            color: var(--vv-text-soft);
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .vv-contact-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(0, 1.1fr);
            gap: 1.6rem;
            align-items: flex-start;
        }
        .vv-contact-form-card {
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 1rem 1.1rem 1.1rem;
        }
        .vv-contact-aside {
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 1rem 1.1rem 1.1rem;
        }
        .vv-contact-aside-header {
            margin-bottom: 0.4rem;
        }
        .vv-contact-aside-header h3 {
            margin-bottom: 0.1rem;
            font-size: 0.95rem;
        }
        .vv-contact-aside-header p {
            font-size: 0.8rem;
            color: var(--vv-text-muted);
        }
        .vv-contact-list {
            list-style: none;
            padding-left: 0;
            margin: 0.45rem 0 0.7rem;
            font-size: 0.82rem;
            color: var(--vv-text-muted);
        }
        .vv-contact-list li {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            margin-bottom: 0.3rem;
        }
        .vv-contact-list i {
            font-size: 1.2rem;
            color: var(--vv-accent);
        }
        .vv-contact-block {
            margin-top: 0.4rem;
        }

        .vv-contact-label {
            display: block;
            font-size: 0.8rem;
            margin-bottom: 0.15rem;
            color: var(--vv-text-main);
        }

        /* Inputs / selects / textarea – adjusted for nicer combo box + multi-line */
        .vv-contact-input {
            width: 100%;
            border-radius: 999px;
            border: 1px solid var(--vv-border-strong);
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            font-family: inherit;
            outline: none;
            background: #fbfaff;
        }

        .vv-contact-select {
            width: 100%;
            border-radius: 14px;              /* not too pill-ish for a larger control */
            border: 1px solid var(--vv-border-strong);
            padding: 0.4rem 2.2rem 0.4rem 0.8rem; /* extra right padding for arrow */
            font-size: 0.8rem;
            font-family: inherit;
            outline: none;
            background: #fbfaff;
            min-height: 38px;
            /* optional custom arrow */
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg width='16' height='16' viewBox='0 0 16 16' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill='%23857d95' d='M4.2 6.2a.75.75 0 0 1 1.06 0L8 8.94l2.74-2.74a.75.75 0 1 1 1.06 1.06L8.53 10.53a.75.75 0 0 1-1.06 0L4.2 7.26a.75.75 0 0 1 0-1.06z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.7rem center;
            background-size: 14px 14px;
        }

        .vv-contact-textarea {
            width: 100%;
            border-radius: 16px;
            border: 1px solid var(--vv-border-strong);
            padding: 0.6rem 0.8rem;
            font-size: 0.82rem;
            line-height: 1.5;
            font-family: inherit;
            outline: none;
            background: #fbfaff;
            min-height: 140px;
            resize: vertical;
        }

        .vv-contact-input:focus,
        .vv-contact-select:focus,
        .vv-contact-textarea:focus {
            border-color: var(--vv-accent);
            background: #ffffff;
        }

        .vv-contact-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.7rem;
        }
        .vv-contact-radio-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            font-size: 0.8rem;
        }
        .vv-contact-radio-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.28rem;
            padding: 0.28rem 0.7rem;
            border-radius: 999px;
            border: 1px solid var(--vv-border-strong);
            background: #fbfaff;
            cursor: pointer;
        }
        .vv-contact-radio-pill input {
            margin: 0;
        }
        .vv-contact-meta-note {
            font-size: 0.78rem;
            color: var(--vv-text-soft);
            margin-top: 0.25rem;
        }

        .vv-alert {
            border-radius: 14px;
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            margin-bottom: 0.75rem;
        }
        .vv-alert-success {
            background: #e3f6ec;
            border: 1px solid #96d7ae;
            color: #256b3a;
        }
        .vv-alert-error {
            background: #fdecea;
            border: 1px solid #f5a5a0;
            color: #b52921;
        }

        .vv-support-topic-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 0.9rem;
            margin-top: 0.8rem;
            margin-bottom: 1.3rem;
        }
        .vv-support-topic-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.7rem 0.9rem;
            font-size: 0.82rem;
        }
        .vv-support-topic-card h3 {
            font-size: 0.9rem;
            margin-bottom: 0.1rem;
        }
        .vv-support-topic-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            background: var(--vv-accent-soft);
            color: var(--vv-accent);
            font-size: 0.75rem;
            margin-bottom: 0.2rem;
        }

        @media (max-width: 991.98px) {
            .vv-contact-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }
                .vv-user-menu {
            position: relative;
        }
        .vv-user-toggle {
            cursor: pointer;
            white-space: nowrap;
        }
        .vv-user-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 0.35rem);
            min-width: 160px;
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid var(--vv-border-soft);
            box-shadow: var(--vv-shadow-subtle);
            padding: 0.4rem 0.2rem;
            display: none;
            z-index: 30;
        }
        .vv-user-menu.open .vv-user-dropdown {
            display: block;
        }
        .vv-user-dropdown a {
            display: block;
            padding: 0.35rem 0.9rem;
            font-size: 0.82rem;
            color: var(--vv-text-main);
            text-decoration: none;
        }
        .vv-user-dropdown a:hover {
            background: #f7f0fa;
            color: var(--vv-accent);
        }
        .vv-user-dropdown-separator {
            height: 1px;
            background: #eee4ff;
            margin: 0.25rem 0.4rem;
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
            <a href="index.php#about">About</a>
        </nav>

        <div class="vv-nav-actions">
            <!-- wishlist icon with counter -->
            <a href="wishlist.php" class="vv-icon-btn" aria-label="Wishlist">
                <i class="bx bx-heart"></i>
                <span class="vv-count-badge" id="wishlistCount"><?php echo $wishlist_count; ?></span>
            </a>

            <!-- cart icon with counter -->
            <a href="cart.php" class="vv-icon-btn" aria-label="Cart">
                <i class="bx bx-shopping-bag"></i>
                <span class="vv-count-badge" id="cartCount"><?php echo $cart_items; ?></span>
            </a>

            <!-- User pill -->
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

<main>
    <!-- HERO / INTRO -->
    <section class="vv-section vv-contact-hero">
        <div class="vv-container">
            <h1>Need help with your order?</h1>
            <p class="vv-contact-lead">
                Our small support team is here to help with orders, returns, sizing, styling and anything else
                about your Velvet Vogue experience.
            </p>
            <div class="vv-contact-pill-row">
                <span class="vv-contact-pill">
                    <i class="bx bx-time-five"></i> Replies within a few hours
                </span>
                <span class="vv-contact-pill">
                    <i class="bx bx-shield-quarter"></i> Secure &amp; private
                </span>
                <span class="vv-contact-pill">
                    <i class="bx bx-chat"></i> Human support – no bots
                </span>
            </div>
        </div>
    </section>

    <!-- QUICK TOPICS -->
    <section class="vv-section">
        <div class="vv-container">
            <div class="vv-support-topic-grid">
                <article class="vv-support-topic-card">
                    <div class="vv-support-topic-chip">
                        <i class="bx bx-package"></i> Orders &amp; delivery
                    </div>
                    <h3>Where is my order?</h3>
                    <p>
                        Track your parcel, update delivery details or let us know if something hasn’t arrived as expected.
                    </p>
                </article>
                <article class="vv-support-topic-card">
                    <div class="vv-support-topic-chip">
                        <i class="bx bx-refresh"></i> Returns &amp; refunds
                    </div>
                    <h3>Returns, exchanges &amp; refunds</h3>
                    <p>
                        We’ll help you with size exchanges, store credit or refunds based on our returns policy.
                    </p>
                </article>
                <article class="vv-support-topic-card">
                    <div class="vv-support-topic-chip">
                        <i class="bx bx-ruler"></i> Sizing &amp; styling
                    </div>
                    <h3>Fit, fabric &amp; styling advice</h3>
                    <p>
                        Not sure which size or fabric works best? Share your measurements and we’ll suggest options.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <!-- MAIN CONTACT GRID -->
    <section class="vv-section vv-section-muted">
        <div class="vv-container">
            <div class="vv-contact-grid">
                <!-- LEFT: FORM -->
                <div>
                    <div class="vv-contact-form-card">
                        <h2 style="margin-bottom:0.4rem;">Send us a message</h2>
                        <p class="vv-section-sub" style="margin-bottom:0.6rem;">
                            Fill in the details below and we’ll reply via your preferred contact method.
                        </p>

                        <?php if ($success_message): ?>
                            <div class="vv-alert vv-alert-success">
                                <?php echo htmlspecialchars($success_message); ?>
                            </div>
                        <?php elseif ($error_message): ?>
                            <div class="vv-alert vv-alert-error">
                                <?php echo htmlspecialchars($error_message); ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="contactsupport.php" autocomplete="off">
                            <div class="vv-contact-row">
                                <div>
                                    <label class="vv-contact-label" for="full_name">Full name</label>
                                    <input
                                        type="text"
                                        id="full_name"
                                        name="full_name"
                                        class="vv-contact-input"
                                        value="<?php echo htmlspecialchars($full_name); ?>"
                                        placeholder="Your name"
                                        required
                                    >
                                </div>
                                <div>
                                    <label class="vv-contact-label" for="email">Email address</label>
                                    <input
                                        type="email"
                                        id="email"
                                        name="email"
                                        class="vv-contact-input"
                                        value="<?php echo htmlspecialchars($email); ?>"
                                        placeholder="you@example.com"
                                        required
                                    >
                                </div>
                            </div>

                            <div style="margin-top:0.7rem;">
                                <label class="vv-contact-label" for="topic">What do you need help with?</label>
                                <select id="topic" name="topic" class="vv-contact-select">
                                    <option value="" <?php echo $topic === '' ? 'selected' : ''; ?>>Choose a topic</option>
                                    <option value="order"    <?php echo $topic === 'order' ? 'selected' : ''; ?>>An order or delivery</option>
                                    <option value="refund"   <?php echo $topic === 'refund' ? 'selected' : ''; ?>>Return / exchange / refund</option>
                                    <option value="product"  <?php echo $topic === 'product' ? 'selected' : ''; ?>>Product or sizing question</option>
                                    <option value="account"  <?php echo $topic === 'account' ? 'selected' : ''; ?>>Account or login</option>
                                    <option value="other"    <?php echo $topic === 'other' ? 'selected' : ''; ?>>Something else</option>
                                </select>
                                <p class="vv-contact-meta-note">
                                    If this is about a specific purchase, sharing your order number will help us respond faster.
                                </p>
                            </div>

                            <div style="margin-top:0.7rem;">
                                <label class="vv-contact-label" for="order_ref">Order number (optional)</label>
                                <input
                                    type="text"
                                    id="order_ref"
                                    name="order_ref"
                                    class="vv-contact-input"
                                    value="<?php echo htmlspecialchars($order_ref); ?>"
                                    placeholder="#VV1234"
                                >
                            </div>

                            <div style="margin-top:0.7rem;">
                                <label class="vv-contact-label" for="message">How can we help?</label>
                                <textarea
                                    id="message"
                                    name="message"
                                    class="vv-contact-textarea"
                                    placeholder="Tell us a bit more about your question or issue..."
                                    required
                                ><?php echo htmlspecialchars($message_txt); ?></textarea>
                            </div>

                            <div style="margin-top:0.7rem;">
                                <span class="vv-contact-label">How would you like us to reply?</span>
                                <div class="vv-contact-radio-row">
                                    <label class="vv-contact-radio-pill">
                                        <input
                                            type="radio"
                                            name="preferred"
                                            value="email"
                                            <?php echo $preferred === 'email' ? 'checked' : ''; ?>
                                        >
                                        <span>Email</span>
                                    </label>
                                    <label class="vv-contact-radio-pill">
                                        <input
                                            type="radio"
                                            name="preferred"
                                            value="phone"
                                            <?php echo $preferred === 'phone' ? 'checked' : ''; ?>
                                        >
                                        <span>Phone / WhatsApp</span>
                                    </label>
                                </div>
                                <p class="vv-contact-meta-note">
                                    If you select phone / WhatsApp, we’ll follow up with you via the contact details saved in your profile,
                                    or the number you’ve shared with us in an order.
                                </p>
                            </div>

                            <div style="margin-top:1rem;display:flex;align-items:center;gap:0.5rem;">
                                <button type="submit" class="vv-btn vv-btn-primary">
                                    <i class="bx bx-send"></i> Send message
                                </button>
                                <?php if (!$customer_id): ?>
                                    <p class="vv-contact-meta-note" style="margin:0;">
                                        Have an account? <a href="customer-login.php">Sign in</a> so we can link this to your orders.
                                    </p>
                                <?php else: ?>
                                    <p class="vv-contact-meta-note" style="margin:0;">
                                        Signed in as <strong><?php echo htmlspecialchars($customer_name); ?></strong>.
                                    </p>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- RIGHT: CONTACT DETAILS / HOURS -->
                <aside class="vv-contact-aside">
                    <div class="vv-contact-aside-header">
                        <h3>Other ways to reach us</h3>
                        <p>
                            Prefer a quick message? Reach out directly using the details below.
                        </p>
                    </div>

                    <ul class="vv-contact-list">
                        <li>
                            <i class="bx bx-phone"></i>
                            <span>
                                Call / WhatsApp: <strong>+94 77 123 4567</strong><br>
                                <span style="font-size:0.78rem;color:var(--vv-text-soft);">
                                    Best for urgent order updates.
                                </span>
                            </span>
                        </li>
                        <li>
                            <i class="bx bx-envelope"></i>
                            <span>
                                Email: <strong>support@velvetvogue.test</strong><br>
                                <span style="font-size:0.78rem;color:var(--vv-text-soft);">
                                    We usually reply within a few hours during working times.
                                </span>
                            </span>
                        </li>
                        <li>
                            <i class="bx bx-time-five"></i>
                            <span>
                                Support hours: <strong>Mon–Sat, 9.00am – 7.00pm</strong><br>
                                <span style="font-size:0.78rem;color:var(--vv-text-soft);">
                                    Messages outside these hours are handled first on the next working day.
                                </span>
                            </span>
                        </li>
                    </ul>

                    <div class="vv-contact-block">
                        <h3 style="font-size:0.92rem;margin-bottom:0.25rem;">Common questions</h3>
                        <ul class="vv-journal-list" style="font-size:0.8rem;">
                            <li>How do I change my delivery address after placing an order?</li>
                            <li>What is your returns and exchange policy?</li>
                            <li>Which sizes are best for my height and measurements?</li>
                            <li>Can I place a custom or bulk order for an event?</li>
                        </ul>
                    </div>

                    <div class="vv-contact-block" style="margin-top:0.8rem;">
                        <h3 style="font-size:0.9rem;margin-bottom:0.25rem;">Need help with an existing order?</h3>
                        <p style="font-size:0.8rem;color:var(--vv-text-muted);margin-bottom:0.4rem;">
                            You can also view your order history and check statuses directly in your account.
                        </p>
                        <a href="customer-orders.php" class="vv-btn vv-btn-secondary vv-btn-sm" style="margin-bottom:0.2rem;">
                            <i class="bx bx-package"></i> View my orders
                        </a>
                        <p class="vv-contact-meta-note">
                            From there, you’ll be able to request a return, leave a product review, or submit a complaint
                            about a delivered item.
                        </p>
                    </div>
                </aside>
            </div>
        </div>
    </section>
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
                <li><a href="customer-orders.php">My orders</a></li>
                <li><a href="#">Shipping &amp; returns</a></li>
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
// header user dropdown (same pattern as index/shop)
document.addEventListener('DOMContentLoaded', function () {
    const userMenu = document.querySelector('.vv-user-menu');
    if (userMenu) {
        const toggle = userMenu.querySelector('.vv-user-toggle');
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            userMenu.classList.toggle('open');
        });
        document.addEventListener('click', function () {
            userMenu.classList.remove('open');
        });
    }
});
</script>
</body>
</html>
