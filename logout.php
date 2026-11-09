<?php
declare(strict_types=1);
require __DIR__ . '/layout.php';

// Sign-out is POST-only (with CSRF) so a stray link or image can't log anyone out.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid($_POST['csrf'] ?? null)) {
    sign_out();
    flash('info', 'You have been signed out.');
    redirect('login.php');
}
redirect(current_user() ? 'admin.php' : 'login.php');
