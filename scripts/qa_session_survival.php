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
        // Follow redirects. Judging a signed-in page by body size alone called
        // every redirect a failure, and a signed-in visit can legitimately
        // bounce once. Where it lands is the answer, not how big the first
        // response was.
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
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
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    return [
        'status' => $status,
        'bytes' => strlen($body),
        'body' => $body,
        'location' => $location,
        'finalUrl' => $finalUrl,
        'cookieMaxAge' => $expires,
        // The clearest signal that a request was not accepted: the server put
        // a password box in front of it.
        'hasLoginForm' => str_contains($body, 'type="password"'),
        'isSsoStub' => stripos($body, 'Redirecting') !== false && strlen($body) < 2000,
    ];
}

echo "TP — session ยังอยู่ครบไหม (read only)\n";
echo str_repeat('=', 70) . "\n\n";

if ($cookieValue === '') {
    echo "ยังไม่ได้ใส่ --cookie จึงตรวจได้แค่ฝั่ง guest\n";
    echo "หมายเหตุ: ต้องเขียนติดกันด้วย = เช่น --cookie=\"tp_session=abc…\"\n";
    echo "          ถ้าเว้นวรรค (--cookie \"…\") PHP จะอ่านไม่เห็นค่าเลย\n\n";
} else {
    if (!str_contains($cookieValue, '=')) {
        $cookieValue = 'tp_session=' . $cookieValue;   // เผลอ copy มาแต่ค่า
    }

    /*
     * แสดงให้เห็นว่ากำลังจะส่งอะไรออกไป
     *
     * รอบก่อนผลออกมา "0 จาก 4" โดยไม่มีอะไรบอกได้เลยว่าเป็นเพราะ session ตาย
     * จริง หรือค่าที่ใส่มาถูกตัด/พิมพ์ผิด — ต้องเดาเอาทั้งสองรอบ ซึ่งเสียเวลา
     * เปล่า ตรงนี้ปิดช่องนั้น โดยไม่พิมพ์ค่าเต็มออกมา
     */
    $sid = substr($cookieValue, strpos($cookieValue, '=') + 1);
    $len = strlen($sid);
    $masked = $len <= 8 ? $sid : substr($sid, 0, 4) . str_repeat('•', max(0, $len - 8)) . substr($sid, -4);

    echo "ใช้คุกกี้ที่ใส่มา (ไม่ถูกเก็บไว้ที่ไหน)\n";
    printf("  ค่าที่จะส่ง: tp_session=%s  (%d ตัวอักษร)\n", $masked, $len);

    // session id ของ PHP ปกติเป็น hex 32 ตัว (หรือ 26 ตัวเมื่อใช้ sid ยาวอื่น)
    if (!preg_match('/^[a-zA-Z0-9,\-]{20,}$/', $sid)) {
        echo "  ⚠ ค่านี้ไม่เหมือน session id ของ PHP — น่าจะ copy มาไม่ครบ\n";
        echo "    หรือหลุดเป็นข้อความอย่างอื่นมา ลอง copy ช่อง Value ใหม่\n";
    } elseif ($len < 26) {
        echo "  ⚠ สั้นกว่าที่ควรเป็น อาจ copy มาไม่ครบ\n";
    }
    echo "\n";
}

$signedIn = 0;
$locked = [];
$rows = [];

foreach ($systems as $name => $spec) {
    $guest = fetchPage($spec['url'], '');
    $mine = $cookieValue === '' ? null : fetchPage($spec['url'], $cookieValue);

    $verdict = '—';
    $why = '';
    if ($mine !== null) {
        $isLocked = str_contains($mine['body'], 'ยืนยันตัวตนอีกครั้ง')
            || ($spec['unlock'] !== null && str_contains($mine['finalUrl'], ltrim($spec['unlock'], '/')));

        if ($isLocked) {
            $verdict = 'ล็อกอยู่ — ใส่รหัสผ่านที่ ' . $spec['unlock'];
            $locked[] = $name;
        } elseif ($mine['hasLoginForm']) {
            $verdict = 'ไม่ผ่าน — เจอหน้าล็อกอิน';
            $why = 'ไปจบที่ ' . $mine['finalUrl'];
        } elseif ($mine['isSsoStub']) {
            // The SSO guard answers 200 with a small HTML page that bounces the
            // browser, so curl never follows it and the size alone looked like
            // an unexplained short response. It means "not signed in".
            $verdict = 'ไม่ผ่าน — SSO ส่งไปหน้าล็อกอิน';
            $why = 'ได้หน้า redirect ' . $mine['bytes'] . ' bytes แทนเนื้อหาจริง';
        } elseif ($mine['bytes'] >= RENDERED_MIN_BYTES) {
            $verdict = 'ล็อกอินอยู่ ใช้งานได้';
            $signedIn++;
        } else {
            $verdict = 'ไม่แน่ใจ — หน้าเล็กผิดปกติ';
            $why = 'status ' . $mine['status'] . ' · ' . $mine['bytes'] . ' bytes · ไปจบที่ ' . $mine['finalUrl'];
        }
    }

    $rows[] = [$name, $guest['bytes'], $mine['bytes'] ?? null, $verdict, $guest['cookieMaxAge'], $why];
}

printf("  %-11s %10s %10s   %s\n", 'ระบบ', 'guest', 'ของคุณ', 'ผล');
echo '  ' . str_repeat('-', 66) . "\n";
foreach ($rows as [$name, $g, $m, $verdict, $maxAge, $why]) {
    printf("  %-11s %8d B %8s   %s\n", $name, $g, $m === null ? '-' : $m . ' B', $verdict);
    if ($why !== '') {
        printf("  %-11s %19s   %s\n", '', '', $why);
    }
}

echo "\n  อายุคุกกี้ที่แต่ละระบบออกให้ (ตอนสร้าง session ใหม่):\n";
foreach ($rows as [$name, , , , $maxAge, ]) {
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
