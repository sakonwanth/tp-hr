<?php
/**
 * Stream check-in / adjustment images from TP-Checkin storage (same-server).
 * Requires HR dashboard access; path is allowlisted to prevent traversal.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

if (!Auth::check()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unauthorized';
    exit;
}

if (!hr_can_access_hr_dashboard()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

$relative = isset($_GET['path']) ? (string) $_GET['path'] : '';
$disk = checkinStorageResolveDiskPath($relative);
if ($disk === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not Found';
    exit;
}

if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        $detected = finfo_file($fi, $disk);
        finfo_close($fi);
        if (hr_finfo_mime_is_blocked_for_static_serve($detected)) {
            http_response_code(415);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Unsupported media type';
            exit;
        }
    }
}

$ext = strtolower(pathinfo($disk, PATHINFO_EXTENSION));
$types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
];
$mime = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($disk);
exit;
