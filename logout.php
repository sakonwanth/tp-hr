<?php
/**
 * TP-HR Logout
 */

require_once __DIR__ . '/bootstrap.php';

Auth::logout();
redirect('/tp-hr/login.php');
