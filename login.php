<?php
declare(strict_types=1);
require __DIR__ . '/layout.php';

if (current_user() !== null) {
    redirect('admin.php');
}

try {
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    http_response_code(500);
    exit('The "users" table is missing. Run schema.sql in your database, then reload this page.');
}

$setupMode = $userCount === 0;   // no accounts yet: show the one-time "create first admin" form
$errors    = [];
$old       = ['username' => '', 'display_name' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $old['username']     = trim((string) ($_POST['username'] ?? ''));
    $old['display_name'] = trim((string) ($_POST['display_name'] ?? ''));
    $password            = (string) ($_POST['password'] ?? '');

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';

    } elseif ($setupMode && $action === 'setup') {
        // ----- Create the very first administrator -----
        if ($old['display_name'] === '' || mb_strlen($old['display_name']) > 80) $errors[] = 'Enter your name (max 80 characters).';
        if ($msg = validate_username($old['username'])) $errors[] = $msg;
        if ($msg = validate_password($password))        $errors[] = $msg;
        if ($password !== (string) ($_POST['password2'] ?? '')) $errors[] = 'The two passwords do not match.';

        if (!$errors) {
            $recheck = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($recheck > 0) {
                $errors[] = 'An account already exists. Please sign in.';
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO users (username, display_name, password_hash, role, is_active, created_at)
                     VALUES (:u, :d, :h, :r, 1, :t)'
                );
                $ins->execute([
                    ':u' => $old['username'], ':d' => $old['display_name'],
                    ':h' => password_hash($password, PASSWORD_DEFAULT), ':r' => 'admin', ':t' => time(),
                ]);
                flash('success', 'Admin account created. Please sign in.');
                redirect('login.php');
            }
        }

    } elseif (!$setupMode && $action === 'login') {
        // ----- Sign in -----
        $st = $pdo->prepare('SELECT * FROM users WHERE username = :u');
        $st->execute([':u' => $old['username']]);
        $row = $st->fetch();
        $now = time();
        $generic = 'Incorrect username or password.';

        if ($row && $row['locked_until'] !== null && (int) $row['locked_until'] > $now) {
            $mins = (int) ceil(((int) $row['locked_until'] - $now) / 60);
            $errors[] = "Too many failed attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.';
        } else {
            // Verify even when the user doesn't exist, so response time doesn't reveal valid usernames.
            $hash = $row['password_hash'] ?? password_hash('placeholder', PASSWORD_DEFAULT);
            $passwordOk = password_verify($password, $hash) && $row;

            if ($passwordOk && (int) $row['is_active'] !== 1) {
                $errors[] = 'This account is disabled. Please contact an administrator.';
            } elseif ($passwordOk) {
                $upd = $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = :t WHERE id = :id');
                $upd->execute([':t' => $now, ':id' => $row['id']]);
                if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                    $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                        ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $row['id']]);
                }
                sign_in($row);
                $to = $_SESSION['return_to'] ?? 'admin.php';
                unset($_SESSION['return_to']);
                redirect(in_array($to, APP_PAGES, true) ? $to : 'admin.php');
            } else {
                if ($row) {
                    $fails = (int) $row['failed_attempts'] + 1;
                    if ($fails >= MAX_FAILED) {
                        $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = :l WHERE id = :id')
                            ->execute([':l' => $now + LOCK_SECONDS, ':id' => $row['id']]);
                    } else {
                        $pdo->prepare('UPDATE users SET failed_attempts = :f WHERE id = :id')
                            ->execute([':f' => $fails, ':id' => $row['id']]);
                    }
                }
                $errors[] = $generic;
            }
        }
    }
}

render_header($setupMode ? 'First-time setup' : 'Sign in', '', false);
?>
<main id="main" class="container py-5" style="max-width: 440px;">
    <div class="text-center mb-4">
        <h1 class="h3 mb-1">Footwear Inventory</h1>
        <p class="text-muted mb-0"><?= $setupMode ? 'First-time setup' : 'Sign in to continue' ?></p>
    </div>

    <?php render_flash(); ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <?php if ($setupMode): ?>
                <p class="small text-muted">No accounts exist yet. Create the first administrator account. You can add staff accounts afterwards from the Users page.</p>
                <form method="post" action="login.php" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="setup">
                    <div class="mb-3">
                        <label for="display_name" class="form-label">Your name</label>
                        <input type="text" class="form-control" id="display_name" name="display_name" maxlength="80" required autofocus value="<?= e($old['display_name']) ?>">
                        <div class="invalid-feedback">Please enter your name.</div>
                    </div>
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" minlength="3" maxlength="30"
                               pattern="[A-Za-z0-9_.\-]+" autocomplete="username" required value="<?= e($old['username']) ?>">
                        <div class="form-text">3-30 characters: letters, numbers, . - _</div>
                        <div class="invalid-feedback">Use 3-30 letters, numbers, dots, dashes or underscores.</div>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required>
                        <div class="form-text">At least 8 characters.</div>
                        <div class="invalid-feedback">Password must be at least 8 characters.</div>
                    </div>
                    <div class="mb-4">
                        <label for="password2" class="form-label">Confirm password</label>
                        <input type="password" class="form-control" id="password2" name="password2" autocomplete="new-password" required>
                        <div class="invalid-feedback" id="pw2-msg">Passwords must match.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Create admin account
                        <span class="spinner-border spinner-border-sm btn-spinner ms-1" aria-hidden="true"></span></button>
                </form>
            <?php else: ?>
                <form method="post" action="login.php" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" autocomplete="username" required autofocus value="<?= e($old['username']) ?>">
                        <div class="invalid-feedback">Enter your username.</div>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group has-validation">
                            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                            <button class="btn btn-outline-secondary" type="button" id="toggle-pw" aria-label="Show password" aria-pressed="false">Show</button>
                            <div class="invalid-feedback">Enter your password.</div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Sign in
                        <span class="spinner-border spinner-border-sm btn-spinner ms-1" aria-hidden="true"></span></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php render_scripts(); ?>
<script>
    const form = document.querySelector('form.needs-validation');
    const pw = document.getElementById('password');
    const pw2 = document.getElementById('password2');

    form.addEventListener('submit', function (ev) {
        if (pw2) { pw2.setCustomValidity(pw.value === pw2.value ? '' : 'Passwords must match.'); }
        if (!form.checkValidity()) {
            ev.preventDefault();
            form.classList.add('was-validated');
            const bad = form.querySelector(':invalid');
            if (bad) bad.focus();
            return;
        }
        const btn = form.querySelector('button[type=submit]');
        btn.classList.add('is-loading');
        setTimeout(function () { btn.disabled = true; }, 0);   // after the form data is captured
    });

    const toggle = document.getElementById('toggle-pw');
    if (toggle) {
        toggle.addEventListener('click', function () {
            const show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            toggle.textContent = show ? 'Hide' : 'Show';
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    }
</script>
</body>
</html>
