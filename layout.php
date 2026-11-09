<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Opens the HTML page: head, skip-link and (when signed in) the navbar.
 * Pages open their own <main id="main"> afterwards.
 */
function render_header(string $title, string $active = '', bool $nav = true, string $extraHead = ''): void
{
    $u = current_user();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | Footwear Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        :focus-visible { outline: 3px solid #0d6efd; outline-offset: 2px; }
        .card { border: 0; }
        .btn-spinner { display: none; }

        /* Data tables: vertical column dividers, roomier cells, clearer header */
        .table-grid > :not(caption) > * > * { padding: 1rem 1.25rem; }
        .table-grid th, .table-grid td { border-right: 1px solid #dee2e6; }
        .table-grid th:last-child, .table-grid td:last-child { border-right: 0; }
        .table-grid thead th {
            background: #f1f3f5; color: #495057; font-size: .78rem; font-weight: 700;
            letter-spacing: .05em; text-transform: uppercase; border-bottom: 2px solid #ced4da;
        }
        .table-grid tbody tr:not(.collapse):hover > * { --bs-table-bg-state: #f4f8fd; }
        .table-grid .btn-sm { padding: .35rem .75rem; }
        @media (max-width: 767.98px) {
            .table-grid > :not(caption) > * > * { padding: .75rem; }
        }
        .is-loading .btn-spinner { display: inline-block; }
    </style>
    <?= $extraHead ?>
</head>
<body>
<a class="visually-hidden-focusable position-absolute p-2 bg-white" href="#main">Skip to content</a>
<?php if ($nav && $u): ?>
<nav class="navbar navbar-expand-md navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="admin.php">Footwear Inventory</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-menu"
                aria-controls="nav-menu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav-menu">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link<?= $active === 'inventory' ? ' active' : '' ?>" href="admin.php"
                       <?= $active === 'inventory' ? 'aria-current="page"' : '' ?>>Inventory</a>
                </li>
                <?php if ($u['role'] === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $active === 'add' ? ' active' : '' ?>" href="add-shoe.php"
                       <?= $active === 'add' ? 'aria-current="page"' : '' ?>>Add Shoe</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= $active === 'users' ? ' active' : '' ?>" href="users.php"
                       <?= $active === 'users' ? 'aria-current="page"' : '' ?>>Users</a>
                </li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-2 mt-2 mt-md-0">
                <span class="navbar-text text-white">
                    <?= e($u['display_name']) ?>
                    <span class="badge text-bg-<?= $u['role'] === 'admin' ? 'primary' : 'secondary' ?> ms-1"><?= e(ROLES[$u['role']] ?? $u['role']) ?></span>
                </span>
                <form method="post" action="logout.php" class="m-0">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <button type="submit" class="btn btn-outline-light btn-sm">Sign out</button>
                </form>
            </div>
        </div>
    </div>
</nav>
<?php endif;
}

/** Prints the one-time flash banner, if any. Success banners fade after a few seconds. */
function render_flash(): void
{
    $f = take_flash();
    if (!$f) {
        return;
    }
    $type = in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info';
    ?>
    <div class="alert alert-<?= $type ?> alert-dismissible fade show" role="<?= $type === 'danger' ? 'alert' : 'status' ?>"
         data-autohide="<?= $type === 'success' ? '1' : '0' ?>">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php
}

/** Bootstrap JS + auto-hide for success banners. Pages add their own <script> after this. */
function render_scripts(): void
{
    ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.querySelectorAll('.alert[data-autohide="1"]').forEach(function (el) {
        setTimeout(function () { bootstrap.Alert.getOrCreateInstance(el).close(); }, 6000);
    });
</script>
    <?php
}
