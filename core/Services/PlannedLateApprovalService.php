<?php

final class PlannedLateApprovalService
{
    public function __construct(private PDO $pdo) {}

    public static function effectiveStart(?array $attendance): ?string
    {
        if (!$attendance || ($attendance['planned_status'] ?? null) !== 'APPROVED') {
            return null;
        }
        $value = trim((string)($attendance['planned_start_time'] ?? ''));
        return $value !== '' ? $value : null;
    }

    public function decide(int $attendanceId, int $actorId, string $decision, string $note = ''): array
    {
        $decision = strtoupper(trim($decision));
        if (!in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            throw new InvalidArgumentException('Invalid planned-late decision');
        }
        if ($attendanceId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('Invalid approval context');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, user_id, attendance_date, check_in_time, planned_start_time, planned_status
                 FROM hr_attendances WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$attendanceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['planned_start_time'])) {
                throw new RuntimeException('ไม่พบคำขอแจ้งเข้างานสาย');
            }
            if ((string)$row['planned_status'] !== 'PENDING') {
                throw new DomainException('คำขอนี้ได้รับการดำเนินการแล้ว');
            }
            if ((int)$row['user_id'] === $actorId) {
                throw new DomainException('ผู้ยื่นคำขอไม่สามารถอนุมัติคำขอของตนเอง');
            }
            if (!empty($row['check_in_time'])) {
                throw new DomainException('พนักงานลงเวลาแล้ว ไม่สามารถเปลี่ยนผลคำขอได้');
            }

            $update = $this->pdo->prepare(
                "UPDATE hr_attendances
                 SET planned_status = ?, planned_reviewed_by = ?, planned_reviewed_at = NOW(),
                     planned_review_note = ?, updated_at = NOW()
                 WHERE id = ? AND planned_status = 'PENDING'"
            );
            $update->execute([$decision, $actorId, mb_substr(trim($note), 0, 500), $attendanceId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('คำขอนี้ได้รับการดำเนินการแล้ว');
            }
            $this->pdo->commit();
            $row['planned_status'] = $decision;
            return $row;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
