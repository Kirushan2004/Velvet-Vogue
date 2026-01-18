<?php
// customer-forgot-password.php
session_start();
require_once 'db.php';

$redirect = trim($_GET['redirect'] ?? '');
$errors = [];
$popupPayload = null;

function clean($v) { return trim($v ?? ''); }
function normalize_answer(string $s): string {
    $s = trim(mb_strtolower($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}
function safe_local_redirect(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('/^\s*https?:\/\//i', $path)) return '';
    if (!preg_match('/^[a-zA-Z0-9_\-\/\.?&=]+$/', $path)) return '';
    return $path;
}

function clear_verify_session_only(): void {
    unset(
        $_SESSION['pw_verify_customer_id'],
        $_SESSION['pw_verify_email'],
        $_SESSION['pw_verify_q'],
        $_SESSION['pw_verify_redirect']
    );
}
function clear_all_forgot_flow(): void {
    clear_verify_session_only();
    unset(
        $_SESSION['pw_reset_customer_id'],
        $_SESSION['pw_reset_ok_until'],
        $_SESSION['pw_reset_redirect']
    );
}

// step: email | verify | verified
$step = $_POST['step'] ?? ($_GET['step'] ?? 'email');
$email = clean($_POST['email'] ?? ($_GET['email'] ?? ''));

$questionsForCustomer = [];

if (isset($_GET['startover']) && $_GET['startover'] === '1') {
    clear_all_forgot_flow();
    $step = 'email';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($step === 'email') {

        // entering a new email should reset old flow
        clear_all_forgot_flow();

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $stmt = $conn->prepare("SELECT id, is_active FROM customers WHERE email = ? LIMIT 1");
            if (!$stmt) {
                $errors[] = 'Something went wrong. Please try again.';
            } else {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $cust = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$cust) {
                    $errors[] = 'No account found with that email.';
                } elseif ((int)$cust['is_active'] !== 1) {
                    $errors[] = 'This account is inactive. Please contact support.';
                } else {
                    $customerId = (int)$cust['id'];

                    $stmt = $conn->prepare("
                        SELECT csa.question_id, sq.question, csa.answer_hash
                        FROM customer_security_answers csa
                        INNER JOIN security_questions sq ON sq.id = csa.question_id
                        WHERE csa.customer_id = ?
                        ORDER BY csa.id ASC
                        LIMIT 2
                    ");
                    if (!$stmt) {
                        $errors[] = 'Something went wrong. Please try again.';
                    } else {
                        $stmt->bind_param('i', $customerId);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        while ($row = $res->fetch_assoc()) $questionsForCustomer[] = $row;
                        $stmt->close();

                        if (count($questionsForCustomer) < 2) {
                            $errors[] = 'Security questions are not set for this account. Please contact support.';
                        } else {
                            $_SESSION['pw_verify_customer_id'] = $customerId;
                            $_SESSION['pw_verify_email'] = $email;
                            $_SESSION['pw_verify_q'] = [
                                [
                                    'question_id' => (int)$questionsForCustomer[0]['question_id'],
                                    'question'    => $questionsForCustomer[0]['question'],
                                    'answer_hash' => $questionsForCustomer[0]['answer_hash'],
                                ],
                                [
                                    'question_id' => (int)$questionsForCustomer[1]['question_id'],
                                    'question'    => $questionsForCustomer[1]['question'],
                                    'answer_hash' => $questionsForCustomer[1]['answer_hash'],
                                ],
                            ];

                            $safe = safe_local_redirect(clean($_POST['redirect'] ?? $redirect));
                            $_SESSION['pw_verify_redirect'] = $safe;

                            $step = 'verify';
                        }
                    }
                }
            }
        }

        if (!empty($errors)) {
            $popupPayload = [
                'type'   => 'danger',
                'title'  => 'Cannot continue',
                'message'=> 'Please fix the issues below and try again.',
                'errors' => $errors
            ];
            $step = 'email';
        }

    } elseif ($step === 'verify') {

        // Keep verify session ALWAYS (even if wrong) so we don't go back to email
        $a1 = clean($_POST['a1'] ?? '');
        $a2 = clean($_POST['a2'] ?? '');

        $stored = $_SESSION['pw_verify_q'] ?? null;
        $customerId = (int)($_SESSION['pw_verify_customer_id'] ?? 0);
        $email = (string)($_SESSION['pw_verify_email'] ?? '');

        if (!$stored || $customerId <= 0 || $email === '') {
            $popupPayload = [
                'type' => 'danger',
                'title' => 'Session expired',
                'message' => 'Please start again.',
                'redirect' => 'customer-forgot-password.php' . ($redirect ? '?redirect=' . urlencode($redirect) : '')
            ];
            $step = 'email';
            clear_all_forgot_flow();
        } else {

            if ($a1 === '' || $a2 === '') {
                $popupPayload = [
                    'type' => 'danger',
                    'title' => 'Missing answers',
                    'message' => 'Please answer both security questions.',
                    'clear_answers' => true
                ];
                $step = 'verify';
            } else {
                $ok1 = password_verify(normalize_answer($a1), $stored[0]['answer_hash']);
                $ok2 = password_verify(normalize_answer($a2), $stored[1]['answer_hash']);

                if (!$ok1 || !$ok2) {
                    // WRONG: stay on verify, clear inputs, show error popup
                    $popupPayload = [
                        'type' => 'danger',
                        'title' => 'Wrong answers',
                        'message' => 'Your answers did not match. Please re-enter the answers and try again.',
                        'clear_answers' => true
                    ];
                    $step = 'verify';
                } else {
                    // CORRECT: show success popup + show Continue button (no auto redirect)
                    $_SESSION['pw_reset_customer_id'] = $customerId;
                    $_SESSION['pw_reset_ok_until']    = time() + (10 * 60);
                    $_SESSION['pw_reset_redirect']    = (string)($_SESSION['pw_verify_redirect'] ?? '');

                    // (optional) keep verify data or clear it; we can clear verify now because we go to "verified" state
                    clear_verify_session_only();

                    $popupPayload = [
                        'type' => 'success',
                        'title' => 'Answers verified!',
                        'message' => 'Your security answers are correct. You can now change your password.',
                        'redirect' => 'customer-reset-password.php'
                    ];
                    $step = 'verified';
                }
            }
        }
    }
}

// Rebuild questions for rendering verify step
if ($step === 'verify') {
    $questionsForCustomer = $_SESSION['pw_verify_q'] ?? [];
    $email = (string)($_SESSION['pw_verify_email'] ?? $email);
    if (empty($questionsForCustomer) || $email === '') {
        $step = 'email';
    }
}

$continueUrl = 'customer-reset-password.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Velvet Vogue</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/admin-login.css">

    <style>
        /* Desktop-friendly but not too wide */
        .admin-login-wrapper{
            width: min(640px, 96vw) !important;
            margin-left: auto !important;
            margin-right: auto !important;
            padding-left: 12px !important;
            padding-right: 12px !important;
        }
        .admin-login-card{ width:100% !important; }
    </style>
</head>
<body>

<div class="admin-login-wrapper">
    <div class="mb-3">
        <a href="customer-login.php<?php echo $redirect ? '?redirect=' . urlencode($redirect) : ''; ?>" class="back-to-site">
            <i class='bx bx-arrow-back'></i> Back to login
        </a>
    </div>

    <div class="admin-login-card">
        <div class="admin-login-title-row mb-3">
            <div>
                <div class="admin-login-logo">
                    Velvet <span>Vogue</span>
                </div>
                <p class="admin-login-subtitle mb-0">Customer · Reset your password</p>
            </div>
            <div class="admin-login-icon">
                <i class='bx bx-lock-open'></i>
            </div>
        </div>

        <?php if ($step === 'email'): ?>
            <form method="post" class="mt-3" autocomplete="off">
                <input type="hidden" name="step" value="email">
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">

                <div class="mb-3">
                    <label class="form-label">Your account email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class='bx bxs-envelope'></i></span>
                        <input type="email" class="form-control" name="email"
                               value="<?php echo htmlspecialchars($email); ?>"
                               required>
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary rounded-pill">
                        Continue
                    </button>
                </div>
            </form>

        <?php elseif ($step === 'verify'): ?>
            <form method="post" class="mt-3" autocomplete="off" id="verifyForm">
                <input type="hidden" name="step" value="verify">

                <div class="mb-2 small text-muted">
                    Account: <strong><?php echo htmlspecialchars($email); ?></strong>
                </div>

                <div class="mb-3">
                    <label class="form-label"><?php echo htmlspecialchars($questionsForCustomer[0]['question'] ?? 'Question 1'); ?></label>
                    <input type="text" class="form-control" name="a1" id="a1" required autocomplete="off">
                </div>

                <div class="mb-3">
                    <label class="form-label"><?php echo htmlspecialchars($questionsForCustomer[1]['question'] ?? 'Question 2'); ?></label>
                    <input type="text" class="form-control" name="a2" id="a2" required autocomplete="off">
                </div>

                <div class="d-grid mb-2">
                    <button type="submit" class="btn btn-primary rounded-pill">
                        Verify answers
                    </button>
                </div>

                <div class="text-center small">
                    <a href="customer-forgot-password.php?startover=1<?php echo $redirect ? '&redirect=' . urlencode($redirect) : ''; ?>" class="text-decoration-none">
                        Start over
                    </a>
                </div>
            </form>

        <?php else: /* verified */ ?>
            <div class="mt-3">
                <div class="alert alert-success small mb-3">
                    ✅ Your security answers are correct. Click Continue to change your password.
                </div>

                <div class="d-grid">
                    <a href="<?php echo htmlspecialchars($continueUrl); ?>" class="btn btn-primary rounded-pill">
                        Continue
                    </a>
                </div>

                <div class="text-center small mt-2">
                    <a href="customer-forgot-password.php?startover=1<?php echo $redirect ? '&redirect=' . urlencode($redirect) : ''; ?>" class="text-decoration-none">
                        Use another email
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Status Modal -->
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

<?php if ($popupPayload): ?>
<script>
window.__VV_POPUP = <?php echo json_encode($popupPayload, JSON_UNESCAPED_SLASHES); ?>;

document.addEventListener('DOMContentLoaded', () => {
  const payload = window.__VV_POPUP;
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

  footer.innerHTML = '';

  // If success with redirect -> show button (NO auto redirect)
  if (payload.type === 'success' && payload.redirect) {
    const btnStay = document.createElement('button');
    btnStay.type = 'button';
    btnStay.className = 'btn btn-outline-secondary rounded-pill px-4';
    btnStay.setAttribute('data-bs-dismiss','modal');
    btnStay.textContent = 'Stay';

    const btnGo = document.createElement('button');
    btnGo.type = 'button';
    btnGo.className = 'btn btn-primary rounded-pill px-4';
    btnGo.textContent = 'Continue';
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

  // Clear answers (wrong/missing) and focus again
  if (payload.clear_answers) {
    const a1 = document.getElementById('a1');
    const a2 = document.getElementById('a2');
    if (a1) a1.value = '';
    if (a2) a2.value = '';
    modalEl.addEventListener('hidden.bs.modal', () => {
      if (a1) a1.focus();
    }, { once: true });
  }
});
</script>
<?php endif; ?>

</body>
</html>
