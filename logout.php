<?php
/**
 * TP-HR Logout — POST + CSRF เท่านั้น (กัน CSRF logout / prefetch)
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (Auth::check()) {
        redirect('/', 303);
    } else {
        redirect('/login.php', 303);
    }
}

if (!verifyCsrfToken()) {
    redirect('/', 303);
}

Auth::logout();
redirect('/login.php?logout=1', 303);
