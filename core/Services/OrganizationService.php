<?php
/** HR-owned organization catalog. All mutations are transactional, audited and serialized. */
final class OrganizationService
{
    private PDO $db;
    private const TABLES = ['positions' => 'hr_positions', 'workplaces' => 'hr_workplaces', 'locations' => 'hr_checkin_locations'];
    public function __construct(PDO $db) { $this->db = $db; }
    public static function permits(string $role, bool $active): bool
    {
        return $active && in_array($role, ['HR', 'Admin', 'CEO', 'Chairman'], true);
    }
    public function canManage(int $actor): bool
    {
        $q = $this->db->prepare('SELECT u.is_active, r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?');
        $q->execute([$actor]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r && self::permits((string)$r['name'], (bool)$r['is_active']);
    }
    private function lockSuffix(): string { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    public function ready(): bool
    {
        try {
            foreach (['hr_positions','hr_checkin_locations','hr_workplaces','hr_location_workplaces','hr_org_assignments','hr_org_history','hr_org_mutex'] as $t) $this->db->query("SELECT * FROM $t WHERE 1=0");
            return (bool)$this->db->query('SELECT id FROM hr_org_mutex WHERE id=1')->fetchColumn();
        } catch (PDOException $e) { return false; }
    }
    public static function employeePositionUpdateSql(bool $ready): string
    {
        return $ready
            ? 'CASE WHEN EXISTS (SELECT 1 FROM hr_org_assignments oa WHERE oa.user_id=users.id AND oa.position_id IS NOT NULL) THEN position ELSE ? END'
            : '?';
    }
    public function rows(string $kind): array
    {
        $table = self::TABLES[$kind] ?? throw new InvalidArgumentException('หมวดข้อมูลไม่ถูกต้อง');
        return $this->db->query("SELECT * FROM $table ORDER BY is_active DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    }
    public function get(string $kind, int $id): ?array
    {
        $table = self::TABLES[$kind] ?? throw new InvalidArgumentException('หมวดข้อมูลไม่ถูกต้อง');
        $q = $this->db->prepare("SELECT * FROM $table WHERE id=?" . $this->lockSuffix());
        $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if ($kind === 'locations') {
            $q = $this->db->prepare('SELECT workplace_id FROM hr_location_workplaces WHERE location_id=?');
            $q->execute([$id]); $r['workplace_id'] = (int)$q->fetchColumn();
        }
        return $r;
    }
    public static function token(?array $row): string { return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
    private function audit(int $actor, string $kind, int $id, ?array $old, array $new): void
    {
        $q = $this->db->prepare('INSERT INTO hr_org_history (actor_id,entity_type,entity_id,old_values,new_values) VALUES (?,?,?,?,?)');
        $q->execute([$actor,$kind,$id,$old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
    private function transaction(int $actor, callable $fn)
    {
        $this->db->beginTransaction();
        try {
            // One stable row prevents concurrent deactivation / assignment races.
            if (!$this->db->query('SELECT id FROM hr_org_mutex WHERE id=1' . $this->lockSuffix())->fetchColumn()) throw new RuntimeException('ยังไม่ได้เตรียมโครงสร้างข้อมูล');
            if (!$this->canManage($actor)) throw new DomainException('คุณไม่มีสิทธิ์จัดการข้อมูลนี้');
            $result = $fn(); $this->db->commit(); return $result;
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    private static function stringValue(array $input, string $key, int $max, bool $required = false): string
    {
        if (isset($input[$key]) && !is_scalar($input[$key])) throw new DomainException('ข้อมูลไม่ถูกต้อง');
        $v = trim((string)($input[$key] ?? ''));
        if (($required && $v === '') || mb_strlen($v) > $max) throw new DomainException('กรุณากรอกข้อมูลให้ครบและไม่เกินความยาวที่กำหนด');
        return $v;
    }
    public static function validate(string $kind, array $input): array
    {
        $code = self::stringValue($input, 'code', 20, true);
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $code)) throw new DomainException('รหัสใช้ตัวอักษรอังกฤษ ตัวเลข - หรือ _ เท่านั้น');
        $v = ['code'=>$code, 'is_active'=>empty($input['is_active']) ? 0 : 1];
        if ($kind === 'positions') {
            $v['title'] = self::stringValue($input, 'title', 100, true);
            $v['job_description'] = self::stringValue($input, 'job_description', 5000);
        } elseif (in_array($kind, ['workplaces','locations'], true)) {
            $v['name'] = self::stringValue($input, 'name', 100, true);
            $v['address'] = self::stringValue($input, 'address', 5000);
            $v['description'] = self::stringValue($input, 'description', 5000);
            if ($kind === 'locations') {
                foreach (['latitude'=>90, 'longitude'=>180] as $key=>$limit) {
                    if (!isset($input[$key]) || !is_scalar($input[$key]) || !is_numeric($input[$key]) || !is_finite((float)$input[$key]) || abs((float)$input[$key]) > $limit) throw new DomainException('พิกัดละติจูดหรือลองจิจูดไม่ถูกต้อง');
                    $v[$key] = (float)$input[$key];
                }
                $radius = filter_var($input['radius_meters'] ?? null, FILTER_VALIDATE_INT);
                if ($radius === false || $radius < 1 || $radius > 10000) throw new DomainException('รัศมีต้องอยู่ระหว่าง 1–10,000 เมตร');
                $v['radius_meters'] = $radius;
            }
        } else throw new DomainException('หมวดข้อมูลไม่ถูกต้อง');
        return $v;
    }
    private function activeReference(string $kind, int $id): array
    {
        $r = $this->get($kind, $id);
        if (!$r || !(int)$r['is_active']) throw new DomainException('รายการที่เลือกถูกปิดใช้งานหรือไม่พบ กรุณาเลือกใหม่');
        return $r;
    }
    private function insert(string $table, array $values): int
    {
        $sql = 'INSERT INTO '.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0,count($values),'?')).')';
        $this->db->prepare($sql)->execute(array_values($values));
        return (int)$this->db->lastInsertId();
    }
    public function save(int $actor, string $kind, int $id, string $version, array $input): int
    {
        return $this->transaction($actor, function () use ($actor,$kind,$id,$version,$input) {
            $old = $id ? $this->get($kind,$id) : null;
            if ($id && (!$old || !hash_equals(self::token($old),$version))) throw new DomainException('ข้อมูลมีการเปลี่ยนแปลง กรุณาโหลดหน้าใหม่ก่อนบันทึก');
            $table = self::TABLES[$kind];
            if (in_array($kind, ['workplaces','locations'], true)) {
                // Generate under the shared catalog mutex; keep existing codes immutable.
                $code = $old['code'] ?? '';
                if ($code === '') {
                    $prefix = $kind === 'workplaces' ? 'WP-' : 'LOC-';
                    $sequence = (int)$this->db->query("SELECT COALESCE(MAX(id),0) FROM $table")->fetchColumn() + 1;
                    $exists = $this->db->prepare("SELECT 1 FROM $table WHERE code=?");
                    do {
                        $code = $prefix . str_pad((string)$sequence++, 6, '0', STR_PAD_LEFT);
                        $exists->execute([$code]);
                    } while ($exists->fetchColumn());
                }
                $input['code'] = $code;
            }
            $v = self::validate($kind, $input);
            $q = $this->db->prepare("SELECT id FROM $table WHERE code=? AND id<>?");
            $q->execute([$v['code'],$id]);
            if ($q->fetchColumn()) throw new DomainException('รหัสนี้ถูกใช้งานแล้ว');
            $workplace = 0;
            if ($kind === 'locations') {
                $workplace = (int)($input['workplace_id'] ?? 0);
                if ($workplace && ($workplace !== (int)($old['workplace_id'] ?? 0) || $v['is_active'])) $this->activeReference('workplaces',$workplace);
                if ($old && !(int)$old['is_active'] && $v['is_active']) {
                    $q = $this->db->prepare("SELECT 1 FROM hr_org_history WHERE entity_type='location_revision' AND entity_id=?");
                    $q->execute([$id]);
                    if ($q->fetchColumn()) throw new DomainException('จุดนี้เป็นรุ่นประวัติ กรุณาแก้ไขรุ่นปัจจุบันหรือเพิ่มจุดใหม่');
                }
                if ($old && (int)$old['is_active'] && !$v['is_active']) {
                    $remaining = $this->db->prepare('SELECT COUNT(*) FROM hr_checkin_locations WHERE id<>? AND is_active=1 AND latitude IS NOT NULL AND longitude IS NOT NULL');
                    $remaining->execute([$id]);
                    if ((int)$remaining->fetchColumn() === 0) throw new DomainException('ไม่สามารถปิดจุดลงเวลาสุดท้ายได้ กรุณาเพิ่มจุดทดแทนก่อน');
                }
            }
            if ($kind === 'workplaces' && $old && !$v['is_active']) {
                $q = $this->db->prepare('SELECT COUNT(*) FROM hr_location_workplaces m JOIN hr_checkin_locations l ON l.id=m.location_id WHERE m.workplace_id=? AND l.is_active=1');
                $q->execute([$id]);
                if ($q->fetchColumn()) throw new DomainException('กรุณาย้ายหรือปิดจุดลงเวลาที่ใช้งานของสถานที่นี้ก่อน');
            }
            // Every geometry/name change gets a new ID, even if no attendance exists yet:
            // a concurrent in-flight check-in may still hold the previous ID.
            $revision = false;
            if ($kind === 'locations' && $old) {
                foreach (array_keys($v) as $key) {
                    if ($key === 'is_active') continue;
                    $same = in_array($key, ['latitude','longitude','radius_meters'], true)
                        ? (float)($old[$key] ?? 0) === (float)$v[$key]
                        : (string)($old[$key] ?? '') === (string)$v[$key];
                    if (!$same) $revision = true;
                }
                if ($workplace !== (int)$old['workplace_id']) $revision = true;
            }
            if ($revision) {
                $this->db->prepare('UPDATE hr_checkin_locations SET is_active=0, code=NULL WHERE id=?')->execute([$id]);
                $newValues = $old;
                unset($newValues['id'],$newValues['workplace_id'],$newValues['created_at'],$newValues['updated_at']);
                $newId = $this->insert($table,array_replace($newValues,$v));
                $this->audit($actor,'location_revision',$id,$old,['replacement_id'=>$newId]);
                $id = $newId;
            } elseif ($id) {
                $sets = implode(',',array_map(fn($k)=>"$k=?",array_keys($v)));
                $this->db->prepare("UPDATE $table SET $sets WHERE id=?")->execute([...array_values($v),$id]);
            } else $id = $this->insert($table,$v);
            if ($kind === 'locations') {
                $this->db->prepare('DELETE FROM hr_location_workplaces WHERE location_id=?')->execute([$id]);
                if ($workplace) $this->db->prepare('INSERT INTO hr_location_workplaces (location_id,workplace_id) VALUES (?,?)')->execute([$id,$workplace]);
            }
            $this->audit($actor,$kind,$id,$old,$this->get($kind,$id));
            return $id;
        });
    }
    public function assignment(int $userId): array
    {
        $q = $this->db->prepare('SELECT u.id AS user_id, u.position AS position_text, a.position_id, a.workplace_id, p.title AS position_catalog_title, w.name AS workplace_name FROM users u LEFT JOIN hr_org_assignments a ON a.user_id=u.id LEFT JOIN hr_positions p ON p.id=a.position_id LEFT JOIN hr_workplaces w ON w.id=a.workplace_id WHERE u.id=?' . $this->lockSuffix());
        $q->execute([$userId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('ไม่พบพนักงาน');
    }
    public function assign(int $actor, int $userId, int $position, int $workplace, string $version, bool $refreshTitle = false): void
    {
        $this->transaction($actor,function () use ($actor,$userId,$position,$workplace,$version,$refreshTitle) {
            $old = $this->assignment($userId);
            if (!hash_equals(self::token($old),$version)) throw new DomainException('ข้อมูลพนักงานเปลี่ยนแล้ว กรุณาโหลดหน้าใหม่');
            $q=$this->db->prepare('SELECT is_active, is_employee FROM users WHERE id=?'); $q->execute([$userId]); $user=$q->fetch(PDO::FETCH_ASSOC);
            if (!$user || !(int)$user['is_active'] || !(int)$user['is_employee']) throw new DomainException('เลือกได้เฉพาะพนักงานที่ใช้งานอยู่');
            if ($position && ($position !== (int)$old['position_id'] || $refreshTitle)) {
                $p=$this->activeReference('positions',$position);
                // Keep users.position as the compatibility value read by CRM/ERP/documents.
                $this->db->prepare('UPDATE users SET position=? WHERE id=?')->execute([$p['title'],$userId]);
            }
            if ($workplace && $workplace !== (int)$old['workplace_id']) $this->activeReference('workplaces',$workplace);
            $this->db->prepare('DELETE FROM hr_org_assignments WHERE user_id=?')->execute([$userId]);
            $this->db->prepare('INSERT INTO hr_org_assignments (user_id,position_id,workplace_id) VALUES (?,?,?)')->execute([$userId,$position ?: null,$workplace ?: null]);
            $this->audit($actor,'assignments',$userId,$old,$this->assignment($userId));
        });
    }
    public function history(string $kind, int $id): array
    {
        $q=$this->db->prepare("SELECT * FROM hr_org_history WHERE (entity_type=? OR (?='locations' AND entity_type='location_revision')) AND entity_id=? ORDER BY id DESC LIMIT 50");
        $q->execute([$kind,$kind,$id]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
