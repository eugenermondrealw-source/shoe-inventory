<?php
declare(strict_types=1);
require __DIR__ . '/layout.php';

$me = require_role('admin');

$errors = [];
$old    = ['display_name' => '', 'username' => '', 'role' => 'staff'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'Security token invalid. Please reload the page and try again.';

    } elseif ($action === 'add_user') {
        $old['display_name'] = trim((string) ($_POST['display_name'] ?? ''));
        $old['username']     = trim((string) ($_POST['username'] ?? ''));
        $old['role']         = (string) ($_POST['role'] ?? 'staff');
        $password            = (string) ($_POST['password'] ?? '');

        if ($old['display_name'] === '' || mb_strlen($old['display_name']) > 80) $errors[] = 'Name is required (max 80 characters).';
        if ($msg = validate_username($old['username'])) $errors[] = $msg;
        if ($msg = validate_password($password))        $errors[] = $msg;
        if (!isset(ROLES[$old['role']]))                $errors[] = 'Choose a valid role.';

        if (!$errors) {
            $exists = $pdo->prepare('SELECT 1 FROM users WHERE username = :u');
            $exists->execute([':u' => $old['username']]);
            if ($exists->fetchColumn()) {
                $errors[] = 'That username is already taken.';
            }
        }
        if (!$errors) {
            try {
                $pdo->prepare(
                    'INSERT INTO users (username, display_name, password_hash, role, is_active, created_at)
                     VALUES (:u, :d, :h, :r, 1, :t)'
                )->execute([
                    ':u' => $old['username'], ':d' => $old['display_name'],
                    ':h' => password_hash($password, PASSWORD_DEFAULT), ':r' => $old['role'], ':t' => time(),
                ]);
                flash('success', 'Account "' . $old['username'] . '" created as ' . ROLES[$old['role']] . '.');
                redirect('users.php');
            } catch (PDOException $ex) {
                error_log($ex->getMessage());
                $errors[] = 'Database error: the account was not created.';
            }
        }

    } elseif ($action === 'update_user') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $st = $pdo->prepare('SELECT id, username, role, is_active FROM users WHERE id = :id');
        $st->execute([':id' => $id ?: 0]);
        $target = $st->fetch();

        if (!$target) {
            $errors[] = 'That account was not found.';
        } else {
            $role   = (string) ($_POST['role'] ?? $target['role']);
            $active = isset($_POST['is_active']) ? 1 : 0;
            $newPw  = (string) ($_POST['new_password'] ?? '');

            // Admins can't demote or disable their own account (prevents locking everyone out).
            if ((int) $target['id'] === $me['id']) {
                $role   = $target['role'];
                $active = 1;
            }
            if (!isset(ROLES[$role])) $errors[] = 'Choose a valid role.';
            if ($newPw !== '' && ($msg = validate_password($newPw))) $errors[] = $msg;

            if (!$errors) {
                $sql    = 'UPDATE users SET role = :r, is_active = :a, failed_attempts = 0, locked_until = NULL';
                $params = [':r' => $role, ':a' => $active, ':id' => $target['id']];
                if ($newPw !== '') {
                    $sql .= ', password_hash = :h';
                    $params[':h'] = password_hash($newPw, PASSWORD_DEFAULT);
                }
                $pdo->prepare($sql . ' WHERE id = :id')->execute($params);
                flash('success', 'Account "' . $target['username'] . '" updated.' . ($newPw !== '' ? ' Password was reset.' : ''));
                redirect('users.php');
            }
        }
    }
}

$users = $pdo->query(
    'SELECT id, username, display_name, role, is_active, last_login, created_at FROM users ORDER BY role ASC, display_name ASC'
)->fetchAll();

render_header('Users', 'users');
?>
<main id="main" class="container pb-5">
    <?php render_flash(); ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <strong>Please fix the following:</strong>
            <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <h1 class="h4 mb-3">Users &amp; Roles</h1>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100 shadow-sm"><div class="card-body">
                <span class="badge text-bg-primary mb-2">Admin</span>
                <p class="mb-0 small">Full access: add and delete shoes, update stock, and manage user accounts.</p>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 shadow-sm"><div class="card-body">
                <span class="badge text-bg-secondary mb-2">Staff</span>
                <p class="mb-0 small">View the inventory and update stock quantities. Cannot add or delete shoes, or manage users.</p>
            </div></div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Add a user</div>
        <div class="card-body">
            <form method="post" action="users.php" class="row g-3 needs-validation" novalidate>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_user">
                <div class="col-md-3">
                    <label for="display_name" class="form-label">Name</label>
                    <input type="text" class="form-control" id="display_name" name="display_name" maxlength="80" required value="<?= e($old['display_name']) ?>">
                    <div class="invalid-feedback">Enter a name.</div>
                </div>
                <div class="col-md-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" minlength="3" maxlength="30"
                           pattern="[A-Za-z0-9_.\-]+" autocomplete="off" required value="<?= e($old['username']) ?>">
                    <div class="invalid-feedback">3-30 letters, numbers, . - _</div>
                </div>
                <div class="col-md-3">
                    <label for="password" class="form-label">Temporary password</label>
                    <input type="password" class="form-control" id="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required>
                    <div class="invalid-feedback">At least 8 characters.</div>
                </div>
                <div class="col-md-2">
                    <label for="role" class="form-label">Role</label>
                    <select class="form-select" id="role" name="role">
                        <?php foreach (ROLES as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $old['role'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">Add</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-grid align-middle mb-0">
                <thead class="table-light">
                    <tr><th scope="col">Name</th><th scope="col">Username</th><th scope="col">Role</th>
                        <th scope="col">Status</th><th scope="col">Last sign-in</th><th scope="col" class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): $isMe = (int) $u['id'] === $me['id']; ?>
                    <tr>
                        <td class="fw-semibold"><?= e($u['display_name']) ?><?= $isMe ? ' <span class="badge text-bg-light border">You</span>' : '' ?></td>
                        <td><?= e($u['username']) ?></td>
                        <td><span class="badge text-bg-<?= $u['role'] === 'admin' ? 'primary' : 'secondary' ?>"><?= e(ROLES[$u['role']] ?? $u['role']) ?></span></td>
                        <td><span class="badge text-bg-<?= (int) $u['is_active'] ? 'success' : 'danger' ?>"><?= (int) $u['is_active'] ? 'Active' : 'Disabled' ?></span></td>
                        <td class="small text-muted"><?= e(fmt_time($u['last_login'] !== null ? (int) $u['last_login'] : null)) ?></td>
                        <td class="text-end">
                            <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#edit-user-<?= (int) $u['id'] ?>" aria-expanded="false">Edit</button>
                        </td>
                    </tr>
                    <tr class="collapse" id="edit-user-<?= (int) $u['id'] ?>">
                        <td colspan="6" class="bg-light">
                            <form method="post" action="users.php" class="row g-3 align-items-end p-2">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_user">
                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <div class="col-md-3">
                                    <label class="form-label small mb-1" for="role-<?= (int) $u['id'] ?>">Role</label>
                                    <select class="form-select form-select-sm" id="role-<?= (int) $u['id'] ?>" name="role" <?= $isMe ? 'disabled' : '' ?>>
                                        <?php foreach (ROLES as $key => $label): ?>
                                            <option value="<?= e($key) ?>" <?= $u['role'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="active-<?= (int) $u['id'] ?>"
                                               name="is_active" value="1" <?= (int) $u['is_active'] ? 'checked' : '' ?> <?= $isMe ? 'disabled' : '' ?>>
                                        <label class="form-check-label small" for="active-<?= (int) $u['id'] ?>">Account active</label>
                                    </div>
                                    <?php if ($isMe): ?><div class="form-text">You can't change your own role or status.</div><?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small mb-1" for="pw-<?= (int) $u['id'] ?>">Reset password</label>
                                    <input type="password" class="form-control form-control-sm" id="pw-<?= (int) $u['id'] ?>" name="new_password"
                                           minlength="8" maxlength="72" autocomplete="new-password" placeholder="Leave blank to keep current">
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">Save</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php render_scripts(); ?>
<script>
    document.querySelectorAll('form.needs-validation').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            if (!f.checkValidity()) { ev.preventDefault(); f.classList.add('was-validated'); f.querySelector(':invalid').focus(); }
        });
    });
</script>
</body>
</html>
