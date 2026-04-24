<?php

class SettingsService
{
    private PDO $pdo;
    private ?bool $hasSystemSettings = null;
    private array $columnsCache = [];

    private const HR_KEYS = [
        'default_work_start' => true,
        'default_work_end' => true,
        'grace_period_minutes' => true,
        'break_minutes' => true,
        'work_hours_per_day' => true,
        'work_days_per_week' => true,
        'enforce_location_checkin' => true,
        'outside_location_requires_approval' => true,
    ];

    private const HR_ALIASES = [
        'work_start_time' => 'default_work_start',
        'work_end_time' => 'default_work_end',
    ];

    private const SYSTEM_ALIASES = [
        'default_work_start' => 'work_start_time',
        'default_work_end' => 'work_end_time',
        'grace_period_minutes' => 'grace_period_minutes',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function get(string $key, $default = null)
    {
        $canonical = $this->canonicalKey($key);
        $owner = $this->ownerFor($canonical);

        if ($owner === 'system') {
            $value = $this->readSystem($key);
            if ($value !== null) return $value;
            if ($canonical !== $key) {
                $value = $this->readHr($canonical);
                if ($value !== null) return $this->castHrValue($value['value'], $value['type'] ?? 'STRING', $default);
            }
            return $default;
        }

        $value = $this->readHr($canonical);
        if ($value !== null) {
            return $this->castHrValue($value['value'], $value['type'] ?? 'STRING', $default);
        }

        $systemKey = self::SYSTEM_ALIASES[$canonical] ?? null;
        if ($systemKey !== null) {
            $systemValue = $this->readSystem($systemKey);
            if ($systemValue !== null) return $systemValue;
        }

        return $default;
    }

    public function getSystem(string $key, string $default = ''): string
    {
        $value = $this->readSystem($key);
        if ($value !== null) return (string)$value;

        $canonical = $this->canonicalKey($key);
        $hrValue = $this->readHr($canonical);
        if ($hrValue !== null) {
            return (string)$this->castHrValue($hrValue['value'], $hrValue['type'] ?? 'STRING', $default);
        }

        return $default;
    }

    public function set(string $key, $value, string $type = 'STRING', ?int $updatedBy = null, ?string $category = null, ?string $description = null): bool
    {
        $canonical = $this->canonicalKey($key);
        $owner = $this->ownerFor($canonical);
        $storedValue = $this->normalizeValue($value, $type);

        if ($owner === 'system') {
            return $this->writeSystem($key, (string)$storedValue, $type, $updatedBy, $category, $description);
        }

        $ok = $this->writeHr($canonical, (string)$storedValue, $type, $updatedBy, $category ?? 'general', $description);

        $systemKey = self::SYSTEM_ALIASES[$canonical] ?? null;
        if ($systemKey !== null) {
            $this->writeSystem($systemKey, (string)$storedValue, $type, $updatedBy, 'HR', $description ?? 'sync จาก tp-hr settings');
        }

        return $ok;
    }

    public function setSystem(string $key, $value, string $type = 'STRING', ?int $updatedBy = null, ?string $category = null, ?string $description = null): bool
    {
        return $this->writeSystem($key, (string)$this->normalizeValue($value, $type), $type, $updatedBy, $category, $description);
    }

    public function allForHrSettingsPage(): array
    {
        $settings = [];
        try {
            $stmt = $this->pdo->query("SELECT `key`, `value` FROM hr_settings");
            foreach ($stmt as $row) {
                $settings[$row['key']] = (string)$row['value'];
            }
        } catch (Throwable $e) {
            error_log('SettingsService.allForHrSettingsPage hr_settings failed: ' . $e->getMessage());
        }

        foreach ([
            'company_name' => 'TP Asset Development Co., Ltd.',
            'default_work_start' => '08:30',
            'default_work_end' => '17:30',
            'grace_period_minutes' => '15',
            'work_hours_per_day' => '8',
            'work_days_per_week' => '5',
        ] as $key => $default) {
            $settings[$key] = (string)$this->get($key, $settings[$key] ?? $default);
        }

        return $settings;
    }

    public function getSystemMany(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $this->getSystem($key, '');
            if ($value !== '') $out[$key] = $value;
        }
        return $out;
    }

    private function canonicalKey(string $key): string
    {
        return self::HR_ALIASES[$key] ?? $key;
    }

    private function ownerFor(string $key): string
    {
        if (isset(self::HR_KEYS[$key])) return 'hr';
        if (str_starts_with($key, 'payroll_')) return 'system';
        if (str_starts_with($key, 'company_')) return 'system';
        if (str_starts_with($key, 'doc_')) return 'system';
        if (str_starts_with($key, 'google_')) return 'system';
        if (str_starts_with($key, 'USE_')) return 'system';
        return 'hr';
    }

    private function readHr(string $key): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT `value`, `type` FROM hr_settings WHERE `key` = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function readSystem(string $key): ?string
    {
        if (!$this->systemSettingsExists()) return null;

        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return ($value !== false && $value !== null) ? (string)$value : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function writeHr(string $key, string $value, string $type, ?int $updatedBy, ?string $category, ?string $description): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO hr_settings (`key`, `value`, `type`, category, description, updated_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    `value` = VALUES(`value`),
                    `type` = VALUES(`type`),
                    category = COALESCE(VALUES(category), category),
                    description = COALESCE(VALUES(description), description),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ");
            return $stmt->execute([$key, $value, strtoupper($type), $category, $description, $updatedBy]);
        } catch (Throwable $e) {
            error_log('SettingsService.writeHr failed: ' . $e->getMessage());
            return false;
        }
    }

    private function writeSystem(string $key, string $value, string $type, ?int $updatedBy, ?string $category, ?string $description): bool
    {
        if (!$this->systemSettingsExists()) return true;

        try {
            $cols = $this->tableColumns('system_settings');
            $insert = ['setting_key' => $key, 'setting_value' => $value];

            if (isset($cols['setting_type'])) $insert['setting_type'] = $this->systemType($type);
            if (isset($cols['category'])) $insert['category'] = $this->systemCategory($category);
            if (isset($cols['description'])) $insert['description'] = $description ?? '';
            if (isset($cols['display_name'])) $insert['display_name'] = $description ?: $key;
            if (isset($cols['updated_by'])) $insert['updated_by'] = $updatedBy;
            if (isset($cols['updated_at'])) $insert['updated_at'] = date('Y-m-d H:i:s');

            $names = array_keys($insert);
            $placeholders = implode(', ', array_fill(0, count($names), '?'));
            $updates = ['setting_value = VALUES(setting_value)'];
            foreach (['setting_type', 'category', 'description', 'display_name', 'updated_by', 'updated_at'] as $col) {
                if (isset($insert[$col])) $updates[] = "{$col} = VALUES({$col})";
            }

            $sql = "INSERT INTO system_settings (" . implode(', ', $names) . ")
                    VALUES ({$placeholders})
                    ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute(array_values($insert));
        } catch (Throwable $e) {
            error_log('SettingsService.writeSystem failed: ' . $e->getMessage());
            return false;
        }
    }

    private function systemSettingsExists(): bool
    {
        if ($this->hasSystemSettings !== null) return $this->hasSystemSettings;

        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
                $stmt->execute(['system_settings']);
                $this->hasSystemSettings = (bool)$stmt->fetchColumn();
            } else {
                $stmt = $this->pdo->query("SHOW TABLES LIKE 'system_settings'");
                $this->hasSystemSettings = (bool)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            $this->hasSystemSettings = false;
        }

        return $this->hasSystemSettings;
    }

    private function tableColumns(string $table): array
    {
        if (isset($this->columnsCache[$table])) return $this->columnsCache[$table];

        $columns = [];
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $this->pdo->query("PRAGMA table_info({$table})");
                foreach ($stmt as $row) {
                    $columns[$row['name']] = true;
                }
            } else {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM {$table}");
                foreach ($stmt as $row) {
                    $columns[$row['Field']] = true;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $this->columnsCache[$table] = $columns;
    }

    private function normalizeValue($value, string $type): string
    {
        $type = strtoupper($type);
        if ($type === 'JSON' && is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        if ($type === 'BOOLEAN') {
            return $value ? 'true' : 'false';
        }
        return (string)$value;
    }

    private function castHrValue($value, string $type, $default)
    {
        switch (strtoupper($type)) {
            case 'NUMBER':
                return is_numeric($value) ? (strpos((string)$value, '.') !== false ? (float)$value : (int)$value) : $default;
            case 'BOOLEAN':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'JSON':
                return json_decode((string)$value, true) ?? $default;
            default:
                return $value;
        }
    }

    private function systemType(string $type): string
    {
        return match (strtoupper($type)) {
            'NUMBER' => 'decimal',
            'BOOLEAN' => 'boolean',
            'JSON' => 'json',
            default => 'string',
        };
    }

    private function systemCategory(?string $category): string
    {
        return match ($category) {
            'การเงิน', 'Payroll' => 'การเงิน',
            'แจ้งเตือน', 'Notification' => 'แจ้งเตือน',
            'ความปลอดภัย', 'Security' => 'ความปลอดภัย',
            'อีเมล', 'Email' => 'อีเมล',
            'Line' => 'Line',
            'HR' => 'HR',
            'อื่นๆ', 'Other' => 'อื่นๆ',
            default => 'ทั่วไป',
        };
    }
}
