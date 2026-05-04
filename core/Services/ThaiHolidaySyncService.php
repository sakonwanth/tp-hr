<?php

class ThaiHolidaySyncService
{
    private const DEFAULT_ENDPOINT = 'https://thailandformats.com/api/v1/holidays/%d';
    private const CALENDARIFIC_ENDPOINT = 'https://calendarific.com/api/v2/holidays?api_key=%s&country=TH&year=%d&type=national';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function syncYear(int $year): int
    {
        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException('Invalid holiday year');
        }

        $sourceName = $this->sourceName();
        $payload = $this->fetchYear($year);
        $holidays = $payload['holidays'] ?? null;
        if (!is_array($holidays)) {
            throw new RuntimeException('Holiday API response is invalid');
        }

        $this->pdo->prepare("UPDATE hr_thai_holiday_sources SET is_active = 0 WHERE source = ? AND year = ?")
            ->execute([$sourceName, $year]);

        $count = 0;
        foreach ($holidays as $holiday) {
            if (!is_array($holiday)) continue;
            foreach ($this->expandHoliday($year, $holiday, $sourceName) as $row) {
                $this->upsertSourceHoliday($row);
                $count++;
            }
        }

        return $count;
    }

    public function syncRange(int $fromYear, int $toYear): array
    {
        if ($fromYear > $toYear) {
            [$fromYear, $toYear] = [$toYear, $fromYear];
        }

        $out = [];
        for ($year = $fromYear; $year <= $toYear; $year++) {
            $out[$year] = $this->syncYear($year);
        }
        return $out;
    }

    public function importedForYear(int $year): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*,
                   CASE WHEN h.id IS NULL THEN 0 ELSE 1 END AS is_selected
            FROM hr_thai_holiday_sources s
            LEFT JOIN hr_holidays h ON h.date = s.date AND h.is_active = 1
            WHERE s.year = ? AND s.is_active = 1
            ORDER BY s.date ASC, s.id ASC
        ");
        $stmt->execute([$year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addSourceToCompanyHoliday(int $sourceHolidayId, ?int $userId = null): bool
    {
        $stmt = $this->pdo->prepare("SELECT * FROM hr_thai_holiday_sources WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$sourceHolidayId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$source) return false;

        $type = (int)($source['is_substitute'] ?? 0) === 1 ? 'SUBSTITUTE' : 'PUBLIC';
        $description = 'Imported from Thailand holiday API: ' . ($source['source'] ?? $this->sourceName());

        $insert = $this->pdo->prepare("
            INSERT INTO hr_holidays (date, name, name_en, type, description, created_by, source_holiday_id, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                name_en = VALUES(name_en),
                type = VALUES(type),
                description = VALUES(description),
                is_active = 1,
                source_holiday_id = VALUES(source_holiday_id),
                source = VALUES(source),
                updated_at = NOW()
        ");

        return $insert->execute([
            $source['date'],
            $source['name'],
            $source['name_en'],
            $type,
            $description,
            $userId,
            $source['id'],
            $source['source'],
        ]);
    }

    public function addAllForYear(int $year, ?int $userId = null): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM hr_thai_holiday_sources
            WHERE year = ? AND is_active = 1
            ORDER BY date ASC, id ASC
        ");
        $stmt->execute([$year]);

        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($this->addSourceToCompanyHoliday((int)$id, $userId)) {
                $count++;
            }
        }
        return $count;
    }

    private function fetchYear(int $year): array
    {
        if ($this->sourceName() === 'calendarific') {
            $key = (string)($_ENV['CALENDARIFIC_API_KEY'] ?? getenv('CALENDARIFIC_API_KEY') ?: '');
            $url = sprintf(self::CALENDARIFIC_ENDPOINT, rawurlencode($key), $year);
        } else {
            $endpoint = $_ENV['THAI_HOLIDAY_API_URL'] ?? getenv('THAI_HOLIDAY_API_URL') ?: self::DEFAULT_ENDPOINT;
            $url = sprintf($endpoint, $year);
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'header' => "Accept: application/json\r\nUser-Agent: TP-HR/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || trim($body) === '') {
            throw new RuntimeException('Unable to fetch Thailand holidays from API');
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Unable to parse Thailand holiday API response');
        }

        return $this->normalizePayload($json);
    }

    private function expandHoliday(int $year, array $holiday, string $sourceName): array
    {
        $start = $holiday['start_date'] ?? null;
        $end = $holiday['end_date'] ?? $start;
        if (!$this->isDate($start) || !$this->isDate($end)) return [];

        $startDate = new DateTimeImmutable((string)$start);
        $endDate = new DateTimeImmutable((string)$end);
        if ($endDate < $startDate) return [];

        $rows = [];
        $cursor = $startDate;
        while ($cursor <= $endDate) {
            if ((int)$cursor->format('Y') === $year) {
                $date = $cursor->format('Y-m-d');
                $slug = (string)($holiday['slug'] ?? $this->slugify((string)($holiday['title'] ?? $date)));
                $rows[] = [
                    'source' => $sourceName,
                    'external_id' => $slug . ':' . $date,
                    'slug' => $slug,
                    'year' => $year,
                    'date' => $date,
                    'name' => $this->thaiName($slug, (string)($holiday['title'] ?? 'วันหยุด')),
                    'name_en' => (string)($holiday['title'] ?? ''),
                    'holiday_type' => (string)($holiday['type'] ?? 'holiday'),
                    'is_substitute' => str_contains($slug, 'substitution') || str_contains(strtolower((string)($holiday['title'] ?? '')), 'substitution') ? 1 : 0,
                    'alcohol_ban' => !empty($holiday['alcohol_ban']) ? 1 : 0,
                    'raw_json' => json_encode($holiday, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
            $cursor = $cursor->modify('+1 day');
        }
        return $rows;
    }

    private function upsertSourceHoliday(array $row): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO hr_thai_holiday_sources
                (source, external_id, slug, year, date, name, name_en, holiday_type, is_substitute, alcohol_ban, raw_json, is_active, synced_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                slug = VALUES(slug),
                year = VALUES(year),
                name = VALUES(name),
                name_en = VALUES(name_en),
                holiday_type = VALUES(holiday_type),
                is_substitute = VALUES(is_substitute),
                alcohol_ban = VALUES(alcohol_ban),
                raw_json = VALUES(raw_json),
                is_active = 1,
                synced_at = NOW()
        ");
        $stmt->execute([
            $row['source'],
            $row['external_id'],
            $row['slug'],
            $row['year'],
            $row['date'],
            $row['name'],
            $row['name_en'],
            $row['holiday_type'],
            $row['is_substitute'],
            $row['alcohol_ban'],
            $row['raw_json'],
        ]);
    }

    private function sourceName(): string
    {
        $provider = strtolower((string)($_ENV['THAI_HOLIDAY_PROVIDER'] ?? getenv('THAI_HOLIDAY_PROVIDER') ?: ''));
        $calendarificKey = (string)($_ENV['CALENDARIFIC_API_KEY'] ?? getenv('CALENDARIFIC_API_KEY') ?: '');
        if ($provider === 'calendarific' || ($provider === '' && $calendarificKey !== '')) {
            return 'calendarific';
        }
        return 'thailandformats';
    }

    private function normalizePayload(array $json): array
    {
        if ($this->sourceName() !== 'calendarific') {
            return $json;
        }

        $items = $json['response']['holidays'] ?? [];
        $holidays = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $date = $item['date']['iso'] ?? null;
                if (!$this->isDate($date)) continue;
                $name = (string)($item['name'] ?? $item['description'] ?? 'Holiday');
                $types = $item['type'] ?? [];
                if (!is_array($types)) $types = [$types];
                $holidays[] = [
                    'title' => $name,
                    'start_date' => $date,
                    'end_date' => $date,
                    'type' => implode(',', array_map('strval', $types)),
                    'alcohol_ban' => false,
                    'details' => (string)($item['description'] ?? ''),
                    'slug' => $this->slugify($name),
                ];
            }
        }

        return ['holidays' => $holidays];
    }

    private function thaiName(string $slug, string $fallback): string
    {
        $map = [
            'new-years-day' => 'วันขึ้นปีใหม่',
            'special-public-holiday' => 'วันหยุดราชการเพิ่มเป็นกรณีพิเศษ',
            'makha-bucha-day' => 'วันมาฆบูชา',
            'chakri-memorial-day' => 'วันจักรี',
            'songkran-festival' => 'วันสงกรานต์',
            'national-labour-day' => 'วันแรงงานแห่งชาติ',
            'coronation-day' => 'วันฉัตรมงคล',
            'visakha-bucha-day' => 'วันวิสาขบูชา',
            'substitution-for-visakha-bucha-day' => 'ชดเชยวันวิสาขบูชา',
            'hm-queen-suthidas-birthday' => 'วันเฉลิมพระชนมพรรษาสมเด็จพระนางเจ้าฯ',
            'substitution-for-buddhist-lent-day' => 'ชดเชยวันเข้าพรรษา',
            'hm-king-maha-vajiralongkorns-birthday' => 'วันเฉลิมพระชนมพรรษา ร.10',
            'asanha-bucha-day' => 'วันอาสาฬหบูชา',
            'buddhist-lent-day' => 'วันเข้าพรรษา',
            'hm-queen-sirikit-the-queen-mothers-birthday-mothers-day' => 'วันแม่แห่งชาติ',
            'king-bhumibol-adulyadej-memorial-day' => 'วันนวมินทรมหาราช',
            'chulalongkorn-day' => 'วันปิยมหาราช',
            'king-bhumibols-birthday-fathers-day-national-day' => 'วันพ่อแห่งชาติ',
            'substitution-for-king-bhumibols-birthday-fathers-day-national-day' => 'ชดเชยวันพ่อแห่งชาติ',
            'constitution-day' => 'วันรัฐธรรมนูญ',
            'new-years-eve' => 'วันสิ้นปี',
        ];

        return $map[$slug] ?? $fallback;
    }

    private function isDate($value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'holiday';
        return trim($value, '-');
    }
}
