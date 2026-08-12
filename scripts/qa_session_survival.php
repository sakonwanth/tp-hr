<?php
/**
 * session ยังอยู่ครบไหม — ตรวจด้วย session จริงของคุณเอง
 *
 *   php scripts/qa_session_survival.php --cookie="tp_session=ค่าจากเบราว์เซอร์"
 *
 * READ ONLY. ยิง GET อย่างเดียว ไม่ล็อกอิน ไม่เขียนอะไร ไม่เก็บคุกกี้ไว้ที่ไหน
 *
 * รันบนเครื่องคุณ คุกกี้ไม่ต้องส่งให้ใคร
 *
 * หาค่าคุกกี้: เปิด https://hr.tp-asset.com ล็อกอินให้เรียบร้อย แล้วกด F12 →
 * Application → Storage → Cookies → https://hr.tp-asset.com → แถว tp_session
 * → copy ช่อง Value
 *
 * สิ่งที่ตอบได้: ตอนนี้ session ใช้ได้กี่ระบบ · คุกกี้หมดอายุเมื่อไหร่ ·
 * ระบบไหนกำลังล็อกอยู่
 *
 * สิ่งที่ตอบไม่ได้: "อีก 3 ชั่วโมงจะยังอยู่ไหม" — ต้องรอจริงแล้วรันซ้ำ ดูวิธี
 * ท้ายผลลัพธ์
 */

$opts = getopt('', ['cookie::', 'verbose']);
$cookieValue = trim((string)($opts['cookie'] ?? ''));
$verbose = isset($opts['verbose']);

/**
 * แต่ละระบบ: หน้าที่ต้องล็อกอินก่อนถึงจะเห็น และขนาดที่ guest ได้รับ
 *
 * guest ได้ redirect stub สั้น ๆ ทุกระบบ (0–857 bytes ตอนวัดครั้งล่าสุด) ส่วน
 * หน้าจริงมีขนาดเป็นหลักหมื่น ความต่างจึงชัดพอจะใช้ตัดสิน โดยไม่ต้องผูกกับ
 * ข้อความบนหน้าที่เปลี่ยนได้ตลอด
 */
$systems = [
    'tp-hr'      => ['url' => 'https://hr.tp-asset.com/hr/employees.php', 'unlock' => '/unlock.php'],
    'tp-crm'     => ['url' => 'https://crm.tp-asset.com/index.php',       'unlock' => '/reauth.php'],
    'tp-erp'     => ['url' => 'https://erp.tp-asset.com/dashboard',       'unlock' => '/reauth'],
    'tp-checkin' => ['url' => 'https://checkin.tp-asset.com/index.php',   'unlock' => null],
];

const RENDERED_MIN_BYTES = 3000;

function fetchPage(string $url, string $cookie): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'tp-session-survival/1.0',
    ]);
    if ($cookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    $expires = null;
    if (preg_match('/^set-cookie:\s*tp_session=[^;]*;([^\r\n]*)/im', $headers, $m)) {
        $expires = preg_match('/max-age=(\d+)/i', $m[1], $a) ? (int)$a[1] : 0;
    }

    $location = preg_match('/^location:\s*(\S+)/im', $headers, $l) ? $l[1] : '';

    return [
        'status' => $status,
        'bytes' => strlen($body),
        'body' => $body,
        'location' => $location,
        'cookieMaxAge' => $expires,
    ];
}

echo "TP — session ยังอยู่ครบไหม (read only)\n";
echo str_repeat('=', 70) . "\n\n";

if ($cookieValue === '') {
    echo "ยังไม่ได้ใส่ --cookie จึงตรวจได้แค่ฝั่ง guest\n\n";
} else {
    if (!str_contains($cookieValue, '=')) {
        $cookieValue = 'tp_session=' . $cookieValue;   // เผลอ copy มาแต่ค่า
    }
    echo "ใช้คุกกี้ที่ใส่มา (ไม่ถูกเก็บไว้ที่ไหน)\n\n";
}

$signedIn = 0;
$locked = [];
$rows = [];

foreach ($systems as $name => $spec) {
    $guest = fetchPage($spec['url'], '');
    $mine = $cookieValue === '' ? null : fetchPage($spec['url'], $cookieValue);

    $verdict = '—';
    if ($mine !== null) {
        $isLocked = $spec['unlock'] !== null
            && (str_contains($mine['location'], $spec['unlock'])
                || str_contains($mine['body'], 'ยืนยันตัวตนอีกครั้ง'));

        if ($isLocked) {
            $verdict = 'ล็อกอยู่ — ต้องใส่รหัสผ่านที่ ' . $spec['unlock'];
            $locked[] = $name;
        } elseif ($mine['bytes'] >= RENDERED_MIN_BYTES) {
            $verdict = 'ล็อกอินอยู่ ใช้งานได้';
            $signedIn++;
        } else {
            $verdict = 'ไม่ผ่าน — ถูกส่งกลับไปหน้าล็อกอิน';
        }
    }

    $rows[] = [$name, $guest['bytes'], $mine['bytes'] ?? null, $verdict, $guest['cookieMaxAge']];
}

printf("  %-11s %10s %10s   %s\n", 'ระบบ', 'guest', 'ของคุณ', 'ผล');
echo '  ' . str_repeat('-', 66) . "\n";
foreach ($rows as [$name, $g, $m, $verdict, $maxAge]) {
    printf("  %-11s %8d B %8s   %s\n", $name, $g, $m === null ? '-' : $m . ' B', $verdict);
}

echo "\n  อายุคุกกี้ที่แต่ละระบบออกให้ (ตอนสร้าง session ใหม่):\n";
foreach ($rows as [$name, , , , $maxAge]) {
    $label = $maxAge === null ? 'ไม่ได้ออกคุกกี้ใหม่'
        : ($maxAge > 0 ? sprintf('%d วิ (%.0f วัน) — ถาวร', $maxAge, $maxAge / 86400)
                       : 'ไม่มีวันหมดอายุระบุ — ตายเมื่อปิดเบราว์เซอร์');
    printf("    %-11s %s\n", $name, $label);
}

echo "\n" . str_repeat('=', 70) . "\n";

if ($cookieValue === '') {
    echo "ใส่ --cookie แล้วรันใหม่เพื่อดูว่า session ของคุณใช้ได้กี่ระบบ\n";
    exit(0);
}

printf("ล็อกอินอยู่ %d จาก %d ระบบ", $signedIn, count($systems));
echo $locked === [] ? "\n" : (' · ล็อกอยู่: ' . implode(', ', $locked) . "\n");

echo <<<'NEXT'

------------------------------------------------------------------
ทดสอบว่า "อยู่ครบ" จริงตามเวลา — ต้องรอจริง สคริปต์ย่อเวลาให้ไม่ได้
------------------------------------------------------------------

T1  ข้ามโปรเจกต์ (ปัญหาเดิมที่รายงานมา)
    รันสคริปต์นี้ตอนนี้ จดผลไว้ → ทิ้งไว้เกิน 1 ชั่วโมงโดยไม่แตะอะไร
    → เปิด checkin.tp-asset.com ลงเวลา → รันสคริปต์นี้ซ้ำ
    tp-hr ต้องยังขึ้นว่า "ล็อกอินอยู่ ใช้งานได้"
    (เดิม tp-checkin จะฆ่า session ทิ้ง แล้ว tp-hr หลุดตามไปด้วย)

T2  CRM ล็อกตัวเองแต่ไม่ลากคนอื่น
    ทิ้งไว้เกิน 1 ชั่วโมง → รันซ้ำ
    tp-crm ควรขึ้น "ล็อกอยู่" ส่วน tp-hr ยังใช้งานได้
    เข้า crm.tp-asset.com ใส่รหัสผ่านที่หน้า reauth แล้วรันซ้ำอีกครั้ง
    tp-crm ต้องกลับมาใช้งานได้ โดย tp-hr ไม่ถูกกระทบเลย

T3  ปัดแอปทิ้งแล้วเปิดใหม่ (เคสของ PWA)
    เปิด TP-HR หรือ TP-Checkin ที่ติดตั้งบนหน้าจอ → ปัดทิ้งจาก app switcher
    → เปิดใหม่ ต้องไม่ต้องล็อกอินใหม่

T4  ไม่ติ๊ก "จดจำฉัน"
    ออกจากระบบ → ล็อกอินใหม่โดยเอาติ๊กออก → ทิ้งไว้เกิน 8 ชั่วโมง
    → เปิด TP-HR ต้องเจอหน้า "ยืนยันตัวตนอีกครั้ง" ไม่ใช่หน้าล็อกอิน
    และ CRM ที่เปิดค้างไว้ต้องยังใช้งานได้

NEXT;

exit(0);
