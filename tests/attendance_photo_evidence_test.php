<?php
require dirname(__DIR__) . '/core/Services/AttendancePhotoEvidence.php';
require __DIR__ . '/support/attendance_photo_fixtures.php';
if (!extension_loaded('gd')) throw new RuntimeException('GD is required for attendance photo tests');
$checks = 0;
function checkPhoto(bool $pass, string $label): void {
    global $checks;
    if (!$pass) throw new RuntimeException($label);
    $checks++; echo "PASS $label\n";
}
foreach (['black', 'white', 'color', 'noise', 'hot_pixel', 'transparent'] as $kind) {
    checkPhoto(!AttendancePhotoEvidence::isValid(photoDataUrl(photoFixture(320, 240, $kind))), "$kind rejected");
}
checkPhoto(!AttendancePhotoEvidence::isValid(photoDataUrl(photoFixture(800, 600, 'black', 'jpeg'), 'jpeg')), 'mirrored black JPEG frame rejected');
checkPhoto(!AttendancePhotoEvidence::isValid(photoDataUrl(photoFixture(32, 32))), 'tiny frame rejected');
checkPhoto(!AttendancePhotoEvidence::isValid(photoDataUrl(photoFixture(120, 120))), 'insufficient image area rejected');
checkPhoto(AttendancePhotoEvidence::isValid(photoDataUrl(photoFixture(160, 120))), 'minimum detailed frame accepted');
foreach (['png', 'jpeg'] as $format) {
    checkPhoto(AttendancePhotoEvidence::isValid(photoDataUrl(photoFixture(320, 240, 'detail', $format), $format)), "detailed $format accepted");
    checkPhoto(AttendancePhotoEvidence::isValid(photoDataUrl(photoFixture(320, 240, 'dark_detail', $format), $format)), "dim detailed $format accepted without brightness floor");
}
$valid = photoFixture();
checkPhoto(AttendancePhotoEvidence::isValid(base64_encode($valid)), 'legacy raw base64 accepted');
checkPhoto(AttendancePhotoEvidence::isValidBinary($valid), 'server-controlled binary validation accepted');
checkPhoto(!AttendancePhotoEvidence::isValid(photoDataUrl($valid, 'jpeg')), 'misdeclared MIME rejected');
checkPhoto(!AttendancePhotoEvidence::isValid('data:image/svg+xml;base64,' . base64_encode('<svg/>')), 'unsupported image type rejected');
checkPhoto(!AttendancePhotoEvidence::isValid('data:image/png;base64,%%%'), 'invalid base64 rejected');
checkPhoto(!AttendancePhotoEvidence::isValid(base64_encode('not an image')), 'invalid decoded image rejected');
checkPhoto(!AttendancePhotoEvidence::isValid(photoDataUrl($valid), 10), 'encoded payload limit enforced');
checkPhoto(!AttendancePhotoEvidence::isValidBinary($valid, 10), 'binary payload limit enforced');
checkPhoto(!AttendancePhotoEvidence::isValidBinary(photoWithPngDimensions($valid, 9000, 120)), 'excessive declared side rejected before decode');
checkPhoto(!AttendancePhotoEvidence::isValidBinary(photoWithPngDimensions($valid, 5000, 5000)), 'excessive declared pixels rejected before decode');
$oldLimit = ini_get('memory_limit');
try {
    ini_set('memory_limit', '16M');
    checkPhoto(!AttendancePhotoEvidence::isValidBinary(photoWithPngDimensions($valid, 2000, 2000)), 'memory budget checked before GD allocation');
} finally { ini_set('memory_limit', $oldLimit); }
checkPhoto(!AttendancePhotoEvidence::isValid('', 0), 'invalid upload budget rejected');
echo "Photo admission policy: $checks passed\n";
