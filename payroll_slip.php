<?php
/**
 * Public payslip viewer (token-gated) — alias for payroll_print.php?format=pdf
 * Delivers a real PDF (rendered by headless Chromium) with pixel-identical
 * layout to the HTML view so LINE recipients receive a ready-to-save file.
 */
$_GET['token']  = $_GET['token']  ?? '';
$_GET['format'] = $_GET['format'] ?? 'pdf';
require __DIR__ . '/payroll_print.php';
