<?php
// customer-register.php
session_start();
require_once 'db.php';

$redirect = trim($_GET['redirect'] ?? '');

if (!empty($_SESSION['customer_id'])) {
    if ($redirect !== '' && preg_match('/^[a-zA-Z0-9_\-\/\.?&=]+$/', $redirect)) {
        header('Location: ' . $redirect);
    } else {
        header('Location: index.php');
    }
    exit;
}

$errors = [];
$popupPayload = null;

/* ---------------- helpers ---------------- */
function clean($v) { return trim($v ?? ''); }

function safe_local_redirect(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('/^\s*https?:\/\//i', $path)) return '';
    if (!preg_match('/^[a-zA-Z0-9_\-\/\.?&=]+$/', $path)) return '';
    return $path;
}

function normalize_answer(string $s): string {
    $s = trim(mb_strtolower($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

/* ---------------- load security questions ---------------- */
$questions = [];
$qsql = "SELECT id, question FROM security_questions WHERE is_active = 1 ORDER BY id ASC";
if ($qres = $conn->query($qsql)) {
    while ($row = $qres->fetch_assoc()) $questions[] = $row;
    $qres->free();
}

/* ---------------- default values ---------------- */
$full_name      = '';
$email          = '';
$phone          = '';
$gender         = '';
$date_of_birth  = '';
$address_line1  = '';
$address_line2  = '';
$city           = '';
$state          = '';
$postal_code    = '';
$country        = 'Sri Lanka';

$sec_q1 = '';
$sec_a1 = '';
$sec_q2 = '';
$sec_a2 = '';

/* ---------------- handle POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect      = clean($_POST['redirect'] ?? $redirect);

    $full_name     = clean($_POST['full_name'] ?? '');
    $email         = clean($_POST['email'] ?? '');
    $phone         = clean($_POST['phone'] ?? '');
    $gender        = clean($_POST['gender'] ?? '');
    $date_of_birth = clean($_POST['date_of_birth'] ?? '');
    $address_line1 = clean($_POST['address_line1'] ?? '');
    $address_line2 = clean($_POST['address_line2'] ?? '');
    $city          = clean($_POST['city'] ?? '');
    $state         = clean($_POST['state'] ?? '');
    $postal_code   = clean($_POST['postal_code'] ?? '');
    $country       = clean($_POST['country'] ?? 'Sri Lanka');

    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password_confirm'] ?? '';

    $sec_q1 = (string)($_POST['sec_q1'] ?? '');
    $sec_a1 = (string)($_POST['sec_a1'] ?? '');
    $sec_q2 = (string)($_POST['sec_q2'] ?? '');
    $sec_a2 = (string)($_POST['sec_a2'] ?? '');

    // validations
    if ($full_name === '') $errors[] = 'Full name is required.';

    if ($email === '') {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($password === '' || $password2 === '') {
        $errors[] = 'Password and confirmation are required.';
    } elseif ($password !== $password2) {
        $errors[] = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if ($phone === '') $errors[] = 'Phone number is required.';

    // match DB enum: male/female/other/prefer_not_say
    if ($gender !== '' && !in_array($gender, ['male', 'female', 'other', 'prefer_not_say'], true)) {
        $errors[] = 'Invalid gender selected.';
    }

    // HTML date input returns YYYY-MM-DD
    if ($date_of_birth !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_birth)) {
        $errors[] = 'Date of birth must be in YYYY-MM-DD format.';
    }

    // security questions required
    $q1 = (int)$sec_q1;
    $q2 = (int)$sec_q2;

    if ($q1 <= 0 || $q2 <= 0) {
        $errors[] = 'Please select both security questions.';
    } elseif ($q1 === $q2) {
        $errors[] = 'Please choose two different security questions.';
    }

    if (trim($sec_a1) === '' || trim($sec_a2) === '') {
        $errors[] = 'Please answer both security questions.';
    } elseif (mb_strlen(trim($sec_a1)) < 2 || mb_strlen(trim($sec_a2)) < 2) {
        $errors[] = 'Security answers must be at least 2 characters.';
    }

    // Ensure selected IDs exist & active
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM security_questions WHERE is_active = 1 AND id IN (?, ?)");
        if ($stmt) {
            $stmt->bind_param('ii', $q1, $q2);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $count = (int)($row['c'] ?? 0);
            $stmt->close();
            if ($count !== 2) $errors[] = 'Invalid security questions selected.';
        } else {
            $errors[] = 'Something went wrong. Please try again later.';
        }
    }

    // Check email uniqueness
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) $errors[] = 'An account already exists with this email.';
            $stmt->close();
        } else {
            $errors[] = 'Something went wrong. Please try again later.';
        }
    }

    // create account
    if (empty($errors)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO customers
                (full_name, email, password_hash, phone, gender, date_of_birth,
                 address_line1, address_line2, city, state, postal_code, country, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param(
                'ssssssssssss',
                $full_name,
                $email,
                $password_hash,
                $phone,
                $gender,
                $date_of_birth,
                $address_line1,
                $address_line2,
                $city,
                $state,
                $postal_code,
                $country
            );

            if ($stmt->execute()) {
                $new_id = (int)$stmt->insert_id;
                $stmt->close();

                // Save security answers (hashed, normalized)
                $a1hash = password_hash(normalize_answer($sec_a1), PASSWORD_DEFAULT);
                $a2hash = password_hash(normalize_answer($sec_a2), PASSWORD_DEFAULT);

                $stmt2 = $conn->prepare("
                    INSERT INTO customer_security_answers (customer_id, question_id, answer_hash)
                    VALUES (?, ?, ?), (?, ?, ?)
                ");

                if ($stmt2) {
                    $stmt2->bind_param('iisiis', $new_id, $q1, $a1hash, $new_id, $q2, $a2hash);
                    $stmt2->execute();
                    $stmt2->close();

                    // Login customer
                    $_SESSION['customer_id']    = $new_id;
                    $_SESSION['customer_name']  = $full_name;
                    $_SESSION['customer_email'] = $email;

                    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_email']);

                    // ✅ Success popup (NO auto redirect)
                    $popupPayload = [
                        'type'     => 'success',
                        'title'    => 'Account created!',
                        'message'  => 'Your account was created successfully.',
                        'redirect' => 'index.php'
                    ];
                } else {
                    // rollback
                    $conn->query("DELETE FROM customers WHERE id = " . (int)$new_id);
                    $errors[] = 'Could not save security questions. Please try again.';
                }
            } else {
                $errors[] = 'Could not create your account. Please try again.';
                $stmt->close();
            }
        } else {
            $errors[] = 'Something went wrong. Please try again later.';
        }
    }

    // if errors -> popup (stay on page)
    if (!empty($errors)) {
        $popupPayload = [
            'type'    => 'danger',
            'title'   => 'Account not created',
            'message' => 'Please fix the errors and try again.',
            'errors'  => $errors
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Create Account | Velvet Vogue</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/admin-login.css">

    <style>
        /* ✅ Desktop width: not too big */
        .admin-login-wrapper{
            width: min(720px, 96vw) !important;
            max-width: none !important;
            margin-left: auto !important;
            margin-right: auto !important;
            padding-left: 12px !important;
            padding-right: 12px !important;
        }
        .admin-login-card{
            width: 100% !important;
            max-width: none !important;
        }

        .admin-login-card .row > [class*="col-"]{ min-width: 0; }

        .vv-section-divider {
            height: 1px;
            background: rgba(0,0,0,0.06);
            margin: 0.9rem 0 0.8rem;
        }
        .vv-mini-help {
            font-size: 0.78rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
    </style>
</head>

<body>
<div class="admin-login-wrapper">
    <div class="mb-3">
        <a href="index.php" class="back-to-site">
            <i class='bx bx-arrow-back'></i> Back to store
        </a>
    </div>

    <div class="admin-login-card">
        <div class="admin-login-title-row mb-3">
            <div>
                <div class="admin-login-logo">
                    Velvet <span>Vogue</span>
                </div>
                <p class="admin-login-subtitle mb-0">Customer · Create your account</p>
            </div>
            <div class="admin-login-icon">
                <i class='bx bx-user-plus'></i>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger py-2 px-3 small">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="customer-register.php" autocomplete="off" class="mt-3">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label for="full_name" class="form-label">Full name</label>
                    <input type="text" class="form-control" id="full_name" name="full_name"
                           value="<?php echo htmlspecialchars($full_name); ?>" required>
                </div>

                <div class="col-12">
                    <label for="email" class="form-label">Email address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class='bx bxs-envelope'></i></span>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?php echo htmlspecialchars($email); ?>" required>
                    </div>
                </div>

                <div class="col-12 col-lg-6">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class='bx bxs-key'></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="At least 6 characters" required>
                    </div>
                </div>

                <div class="col-12 col-lg-6">
                    <label for="password_confirm" class="form-label">Confirm password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class='bx bxs-key'></i></span>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" required>
                    </div>
                </div>

                <div class="col-12 col-lg-6">
                    <label for="phone" class="form-label">Phone</label>
                    <input type="text" class="form-control" id="phone" name="phone"
                           value="<?php echo htmlspecialchars($phone); ?>" required>
                </div>

                <div class="col-6 col-lg-3">
                    <label for="gender" class="form-label">Gender</label>
                    <select id="gender" name="gender" class="form-select">
                        <option value="">Select</option>
                        <option value="female" <?php echo $gender === 'female' ? 'selected' : ''; ?>>Female</option>
                        <option value="male" <?php echo $gender === 'male' ? 'selected' : ''; ?>>Male</option>
                        <option value="other" <?php echo $gender === 'other' ? 'selected' : ''; ?>>Other</option>
                        <option value="prefer_not_say" <?php echo $gender === 'prefer_not_say' ? 'selected' : ''; ?>>Prefer not to say</option>
                    </select>
                </div>

                <div class="col-6 col-lg-3">
                    <label for="date_of_birth" class="form-label">Date of birth</label>
                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth"
                           value="<?php echo htmlspecialchars($date_of_birth); ?>">
                </div>

                <div class="col-12">
                    <label for="address_line1" class="form-label">Address line 1</label>
                    <input type="text" class="form-control" id="address_line1" name="address_line1"
                           value="<?php echo htmlspecialchars($address_line1); ?>">
                </div>

                <div class="col-12">
                    <label for="address_line2" class="form-label">Address line 2 (optional)</label>
                    <input type="text" class="form-control" id="address_line2" name="address_line2"
                           value="<?php echo htmlspecialchars($address_line2); ?>">
                </div>

                <div class="col-12 col-lg-4">
                    <label for="city" class="form-label">City</label>
                    <input type="text" class="form-control" id="city" name="city"
                           value="<?php echo htmlspecialchars($city); ?>">
                </div>

                <div class="col-12 col-lg-4">
                    <label for="state" class="form-label">State / Province</label>
                    <input type="text" class="form-control" id="state" name="state"
                           value="<?php echo htmlspecialchars($state); ?>">
                </div>

                <div class="col-12 col-lg-4">
                    <label for="postal_code" class="form-label">Postal Code</label>
                    <input type="text" class="form-control" id="postal_code" name="postal_code"
                           value="<?php echo htmlspecialchars($postal_code); ?>">
                </div>

                <div class="col-12">
                    <label for="country" class="form-label">Country</label>
                    <input type="text" class="form-control" id="country" name="country"
                           value="<?php echo htmlspecialchars($country); ?>">
                </div>

                <div class="col-12">
                    <div class="vv-section-divider"></div>
                    <h6 class="mb-1" style="font-weight:600;">Security questions (required)</h6>
                    <div class="vv-mini-help">
                        These will be used to verify you if you forget your password.
                    </div>
                </div>

                <div class="col-12 col-lg-6">
                    <label class="form-label" for="sec_q1">Question 1</label>
                    <select class="form-select" name="sec_q1" id="sec_q1" required>
                        <option value="">Select a question</option>
                        <?php foreach ($questions as $q): ?>
                            <option value="<?php echo (int)$q['id']; ?>" <?php echo ((int)$sec_q1 === (int)$q['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($q['question']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-lg-6">
                    <label class="form-label">Answer 1</label>
                    <input type="text" class="form-control" name="sec_a1"
                           value="<?php echo htmlspecialchars($sec_a1); ?>" required>
                </div>

                <div class="col-12 col-lg-6">
                    <label class="form-label" for="sec_q2">Question 2</label>
                    <select class="form-select" name="sec_q2" id="sec_q2" required>
                        <option value="">Select a question</option>
                        <?php foreach ($questions as $q): ?>
                            <option value="<?php echo (int)$q['id']; ?>" <?php echo ((int)$sec_q2 === (int)$q['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($q['question']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-lg-6">
                    <label class="form-label">Answer 2</label>
                    <input type="text" class="form-control" name="sec_a2"
                           value="<?php echo htmlspecialchars($sec_a2); ?>" required>
                </div>
            </div>

            <div class="d-grid mt-3 mb-2">
                <button type="submit" class="btn btn-primary rounded-pill">
                    <i class='bx bx-user-plus me-1'></i>
                    Create account
                </button>
            </div>

            <div class="mb-2 text-center small">
                Already have an account?
                <a href="customer-login.php<?php echo $redirect ? '?redirect=' . urlencode($redirect) : ''; ?>"
                   class="text-decoration-none">
                    Sign in
                </a>
            </div>

            <div class="admin-login-footer-links d-flex justify-content-between">
                <span class="text-muted">Velvet Vogue Customer</span>
                <a href="contactsupport.php">
                    <i class='bx bx-support me-1'></i> Need help?
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ✅ Status Modal -->
<div class="modal fade" id="vvStatusModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4">
      <div class="modal-header">
        <h5 class="modal-title" id="vvStatusTitle">Status</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <p id="vvStatusMessage" class="mb-2"></p>
        <ul id="vvStatusErrors" class="mb-0 small" style="display:none;"></ul>
      </div>

      <div class="modal-footer" id="vvStatusFooter">
        <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // ✅ Prevent selecting same security questions
  const q1 = document.getElementById('sec_q1');
  const q2 = document.getElementById('sec_q2');

  function syncSecurityQuestions(changed) {
    if (!q1 || !q2) return;

    if (q1.value && q2.value && q1.value === q2.value) {
      if (changed === 'q1') q2.value = '';
      else q1.value = '';
    }

    const v1 = q1.value;
    const v2 = q2.value;

    Array.from(q2.options).forEach(opt => {
      if (!opt.value) return;
      const block = (opt.value === v1) && (opt.value !== v2);
      opt.hidden = block;
      opt.disabled = block;
    });

    Array.from(q1.options).forEach(opt => {
      if (!opt.value) return;
      const block = (opt.value === v2) && (opt.value !== v1);
      opt.hidden = block;
      opt.disabled = block;
    });
  }

  if (q1 && q2) {
    q1.addEventListener('change', () => syncSecurityQuestions('q1'));
    q2.addEventListener('change', () => syncSecurityQuestions('q2'));
    syncSecurityQuestions();
  }

  // ✅ Popup handling
  const payload = window.__VV_POPUP || null;
  if (!payload) return;

  const modalEl = document.getElementById('vvStatusModal');
  const titleEl = document.getElementById('vvStatusTitle');
  const msgEl   = document.getElementById('vvStatusMessage');
  const errUl   = document.getElementById('vvStatusErrors');
  const footer  = document.getElementById('vvStatusFooter');

  titleEl.textContent = payload.title || 'Status';
  msgEl.textContent   = payload.message || '';

  errUl.innerHTML = '';
  if (payload.errors && Array.isArray(payload.errors) && payload.errors.length) {
    errUl.style.display = 'block';
    payload.errors.forEach(e => {
      const li = document.createElement('li');
      li.textContent = e;
      errUl.appendChild(li);
    });
  } else {
    errUl.style.display = 'none';
  }

  // ✅ If success => show button to redirect (ONLY ON CLICK)
  footer.innerHTML = '';
  if (payload.type === 'success' && payload.redirect) {
    const btnStay = document.createElement('button');
    btnStay.type = 'button';
    btnStay.className = 'btn btn-outline-secondary rounded-pill px-4';
    btnStay.setAttribute('data-bs-dismiss','modal');
    btnStay.textContent = 'Stay';

    const btnGo = document.createElement('button');
    btnGo.type = 'button';
    btnGo.className = 'btn btn-primary rounded-pill px-4';
    btnGo.textContent = 'Go to Home';
    btnGo.addEventListener('click', () => {
      window.location.href = payload.redirect;
    });

    footer.appendChild(btnStay);
    footer.appendChild(btnGo);
  } else {
    const btnOk = document.createElement('button');
    btnOk.type = 'button';
    btnOk.className = 'btn btn-primary rounded-pill px-4';
    btnOk.setAttribute('data-bs-dismiss','modal');
    btnOk.textContent = 'OK';
    footer.appendChild(btnOk);
  }

  const modal = new bootstrap.Modal(modalEl);
  modal.show();
});
</script>

<?php if ($popupPayload): ?>
<script>
window.__VV_POPUP = <?php echo json_encode($popupPayload, JSON_UNESCAPED_SLASHES); ?>;
</script>
<?php endif; ?>

</body>
</html>
