<?php
if (!defined('BASE_PATH')) {http_response_code(403);exit;}
// Scope supplied by the authenticated page controller.
/** @var PDO $pdo */
/** @var array $current_user */
/**
 * Payroll POST handlers
 * Variables available: $pdo, $current_user, $action, $run_id, $user_id_setup
 * Sets: $message, $error, $action
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!function_exists('verify_csrf_token') || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
    $post_action = $_POST['action'] ?? '';
    if ($post_action === 'save_employee_payment_date') {
        $run_id = (int)($_POST['run_id'] ?? 0);
        try {
            \TpCommon\Hr\PayrollPaymentDate::save(
                getDB(), $run_id, (int)($_POST['user_id'] ?? 0),
                !empty($_POST['use_run_date']) ? null : trim((string)($_POST['payment_date'] ?? '')),
                (int)(Auth::user()['id'] ?? 0), trim((string)($_POST['payment_date_reason'] ?? '')),
                (int)($_POST['payment_date_revision'] ?? 0)
            );
            header('Location: payroll.php?run_id=' . $run_id . '&payment_date_saved=1');
            exit;
        } catch (Throwable $e) {
            tpCrmLogException($e, 'payroll individual payment date');
            $error = ($e instanceof PDOException) ? 'บันทึกวันที่จ่ายไม่สำเร็จ กรุณาติดต่อผู้ดูแลระบบ' : $e->getMessage();
        }
    } elseif ($post_action === 'create_run') {
        $month = trim($_POST['payroll_month'] ?? '');
        // pay_day is an optional one-run override; omitted means canonical month end.
        $pay_day_in = isset($_POST['pay_day']) && $_POST['pay_day'] !== '' ? (int)$_POST['pay_day'] : null;
        if ($error === '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            try {
                $result = payroll_service()->createRun($month, (int)$current_user['id'], $pay_day_in);
                $message = !empty($result['is_recalculation'])
                    ? 'คำนวณสลิปใหม่และบันทึกอัปเดตเรียบร้อย'
                    : 'สร้างรอบเงินเดือนและคำนวณสลิปเรียบร้อย';
                $action = 'runs';
                if (!function_exists('payroll_line_notify_run_event')) {
                    require_once __DIR__ . '/notifications.php';
                }
                $notifyRunId = (int)($result['run_id'] ?? $result['id'] ?? 0);
                if ($notifyRunId <= 0) {
                    $monthFirst = $month . '-01';
                    $ridStmt = $pdo->prepare("SELECT id FROM payroll_runs WHERE payroll_month = ? LIMIT 1");
                    $ridStmt->execute([$monthFirst]);
                    $notifyRunId = (int)$ridStmt->fetchColumn();
                }
                payroll_line_notify_run_event($pdo, $notifyRunId, 'payroll.run_calculated');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                tpCrmLogException($e, 'modules/payroll/actions.php create_run');
                if (($e instanceof RuntimeException || $e instanceof InvalidArgumentException) && trim($e->getMessage()) !== '') {
                    $error = $e->getMessage();
                } elseif ($e instanceof PDOException) {
                    $error = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้งหรือติดต่อผู้ดูแลระบบ';
                } else {
                    $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php create_run', false);
                }
            }
        } else {
            $error = 'กรุณาเลือกเดือน (YYYY-MM)';
        }
    } elseif ($post_action === 'approve_run') {
        $approve_run_id = (int)($_POST['run_id'] ?? $run_id);
        if ($approve_run_id > 0) {
            try {
                $override = !empty($_POST['affordability_override']) ? [
                    'actor_id' => (int)$current_user['id'],
                    'actor_role' => (string)($current_user['role_name'] ?? ''),
                    'reason' => trim((string)($_POST['affordability_override_reason'] ?? '')),
                ] : null;
                payroll_service()->approveRun($approve_run_id, (int)$current_user['id'], $override);
                if (!function_exists('payroll_line_notify_run_event')) {
                    require_once __DIR__ . '/notifications.php';
                }
                payroll_line_notify_run_event($pdo, $approve_run_id, 'payroll.run_approved');
                $message = 'อนุมัติรอบเงินเดือนแล้ว';
                $run_id = $approve_run_id;
            } catch (Exception $e) {
                $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php approve_run', false);
            }
        }
    } elseif ($post_action === 'cancel_approval') {
        $cancel_run_id = (int)($_POST['run_id'] ?? $run_id);
        if ($cancel_run_id > 0) {
            try {
                payroll_service()->cancelApproval($cancel_run_id, (int)$current_user['id']);
                try {
                    $pdo->prepare("UPDATE payroll_slip_tokens t
                        JOIN payroll_slips s ON t.slip_id = s.id
                        SET t.revoked_at = NOW()
                        WHERE s.payroll_run_id = ? AND t.revoked_at IS NULL")
                        ->execute([$cancel_run_id]);
                } catch (Throwable $e) { /* best-effort */ }
                $message = 'ยกเลิกการอนุมัติเรียบร้อย — รอบกลับสู่สถานะ "calculated" สามารถคำนวณสลิปใหม่ได้แล้ว · ลิงก์สลิปเก่าที่ส่งผ่าน LINE ถูกยกเลิกทั้งหมด';
                $run_id = $cancel_run_id;
                $action = 'run_detail';
            } catch (Exception $e) {
                $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php cancel_approval', false);
                $run_id = $cancel_run_id;
                $action = 'run_detail';
            }
        }
    } elseif ($post_action === 'cancel_paid') {
        $cancel_paid_run_id = (int)($_POST['run_id'] ?? $run_id);
        if ($cancel_paid_run_id > 0) {
            try {
                payroll_service()->cancelPaid(
                    $cancel_paid_run_id,
                    'ยกเลิกการบันทึกจ่าย payroll run #' . $cancel_paid_run_id
                );
                $message = 'ยกเลิกการบันทึกจ่ายแล้ว — รอบกลับสู่สถานะ approved · กด "ยกเลิกการอนุมัติ" เพื่อคำนวณสลิปใหม่ได้';
                $run_id = $cancel_paid_run_id;
                $action = 'run_detail';
            } catch (Exception $e) {
                tpCrmLogException($e, 'modules/payroll/actions.php cancel_paid');
                $error = ($e instanceof PDOException)
                    ? 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้งหรือติดต่อผู้ดูแลระบบ'
                    : $e->getMessage();
                $run_id = $cancel_paid_run_id;
                $action = 'run_detail';
            }
        }
    } elseif ($post_action === 'mark_paid') {
        $paid_run_id = (int)($_POST['run_id'] ?? $run_id);
        if ($paid_run_id > 0) {
            try {
                payroll_service()->markPaid(
                    $paid_run_id,
                    isset($current_user['id']) ? (int)$current_user['id'] : null
                );
                if (!function_exists('payroll_line_notify_run_event')) {
                    require_once __DIR__ . '/notifications.php';
                }
                payroll_line_notify_run_event($pdo, $paid_run_id, 'payroll.run_paid');
                $message = 'บันทึกว่าจ่ายเงินแล้ว — รายการค่าใช้จ่ายถูกบันทึกเข้าบัญชีกลางอัตโนมัติ';
                $run_id = $paid_run_id;
            } catch (Exception $e) {
                $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php mark_paid', false);
            }
        }
    } elseif ($post_action === 'send_slip_to_line') {
        // ส่งสลิปเงินเดือนให้พนักงาน 1 คนทาง LINE (token-gated download link)
        $slip_id_post = (int)($_POST['slip_id'] ?? 0);
        $run_id_post  = (int)($_POST['run_id'] ?? $run_id);
        if ($slip_id_post <= 0) {
            $error = 'ไม่ระบุสลิป';
        } else {
            try {
                require_once HR_OWNER_PATH . '/core/Services/PayslipTokenService.php';
                require_once HR_OWNER_PATH . '/core/Services/LineNotifier.php';
                require_once HR_OWNER_PATH . '/config/line.php';
                require_once HR_OWNER_PATH . '/modules/payroll/notifications.php';

                $stmt = $pdo->prepare("
                    SELECT s.*,
                           r.payroll_month, r.pay_day, r.status AS run_status,
                           CONCAT(COALESCE(u.first_name_th,''), ' ', COALESCE(u.last_name_th,'')) AS full_name,
                           u.line_user_id
                    FROM payroll_slips s
                    JOIN payroll_runs r ON s.payroll_run_id = r.id
                    JOIN users u ON s.user_id = u.id
                    WHERE s.id = ? LIMIT 1
                ");
                $stmt->execute([$slip_id_post]);
                $slipRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$slipRow) {
                    $error = 'ไม่พบสลิป';
                } elseif (!in_array($slipRow['run_status'] ?? '', ['approved', 'paid'], true)) {
                    $error = 'ต้องอนุมัติรอบเงินเดือนก่อน จึงจะส่งสลิปได้';
                } elseif (empty($slipRow['line_user_id'])) {
                    $error = 'พนักงานยังไม่ได้ผูก LINE — ไม่สามารถส่งสลิปได้';
                } else {
                    $event_reason = null;
                    if (!payroll_validate_slip_line_event($pdo, $event_reason)) {
                        $error = 'ส่งไม่สำเร็จ — ' . $event_reason;
                    } else {
                        // Throttle: prevent accidental rapid re-send
                        $throttle_sec = (int)(function_exists('getSetting') ? getSetting('payroll_slip_resend_throttle_sec', '60') : '60');
                        $recent_sent = 0;
                        if ($throttle_sec > 0) {
                            $chkT = $pdo->prepare("SELECT COUNT(*) FROM line_notification_log
                                WHERE event = 'slip_issued' AND module = 'payroll'
                                  AND target_user_id = ? AND status = 'sent'
                                  AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
                            $chkT->execute([(int)$slipRow['user_id'], $throttle_sec]);
                            $recent_sent = (int)$chkT->fetchColumn();
                        }
                        if ($recent_sent > 0 && empty($_POST['force_resend'])) {
                            $error = 'เพิ่งส่งสลิปให้พนักงานคนนี้ไปแล้ว (ภายใน ' . $throttle_sec . ' วินาที) — รอสักครู่หรือติ๊ก "ส่งซ้ำ" เพื่อยืนยัน';
                        } else {
                            $ttl = (int)(function_exists('getSetting') ? getSetting('payroll_slip_link_ttl_days', '90') : '90');
                            $token = PayslipTokenService::issue($pdo, (int)$slipRow['id'], (int)$slipRow['user_id'], (int)$current_user['id'], $ttl);
                            $url = PayslipTokenService::buildUrl($token);

                            $thai_months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
                            $pm = strtotime($slipRow['payroll_month']);
                            $month_label = $thai_months[(int)date('n', $pm)] . ' ' . ((int)date('Y', $pm) + 543);
                            $pay_date_label = payroll_payment_date_label(payroll_slip_payment_date(getDB(), $slipRow));

                            // ดึง additional_tax_withholding เพื่อแยก base_tax / extra_tax ในรายการหัก
                            $extra_tax = null;
                            try {
                                $m_first = date('Y-m-01', strtotime($slipRow['payroll_month']));
                                $st_setup = $pdo->prepare("SELECT additional_tax_withholding, tax_opt_out FROM employee_salary_setup
                                    WHERE user_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
                                    ORDER BY effective_from DESC, id DESC LIMIT 1");
                                $st_setup->execute([(int)$slipRow['user_id'], $m_first, $m_first]);
                                $r_setup = $st_setup->fetch(PDO::FETCH_ASSOC);
                                if ($r_setup) {
                                    $extra_tax = payroll_resolve_extra_tax_request($r_setup, !empty($r_setup['tax_opt_out']), $m_first);
                                }
                            } catch (Throwable $e) { /* legacy schema */ }

                            $deduction_breakdown = payroll_build_deduction_breakdown($slipRow, $extra_tax);

                            // ส่งกล่อง "เงื่อนไขการหักเงิน" เฉพาะเมื่อสลิปมีการหัก ขาด/สาย จริง
                            $has_attendance_ded = ((float)($slipRow['absence_deduction'] ?? 0) > 0)
                                                || ((float)($slipRow['lateness_deduction'] ?? 0) > 0);
                            $attendance_rules = $has_attendance_ded
                                ? payroll_get_attendance_rules_snapshot()
                                : null;

                            $ytd_year = (int)date('Y', strtotime($slipRow['payroll_month']));
                            $ytd_payload = payroll_format_ytd_payload(
                                payroll_fetch_ytd_for_user($pdo, (int)$slipRow['user_id'], $ytd_year, $slipRow['payroll_month']),
                                $ytd_year
                            );

                            $payload = payroll_flex_slip_issued(
                                trim($slipRow['full_name']) ?: 'พนักงาน',
                                $month_label,
                                (float)$slipRow['total_income'],
                                (float)$slipRow['total_deductions'],
                                (float)$slipRow['net_salary'],
                                $url,
                                $ytd_payload,
                                $pay_date_label,
                                $deduction_breakdown,
                                $attendance_rules
                            );

                            $sent = LineNotifier::sendEvent($pdo, 'payroll.slip_issued', $payload, [
                                'triggering_user_id' => (int)$slipRow['user_id'],
                            ]);
                            if ($sent > 0) {
                                $message = 'ส่งสลิปให้ ' . htmlspecialchars(trim($slipRow['full_name'])) . ' ทาง LINE สำเร็จ';
                            } else {
                                $error = 'ส่งไม่สำเร็จ — ตรวจสอบว่า event payroll.slip_issued เปิดใช้งาน และพนักงานผูก LINE แล้ว';
                            }
                        } // end throttle guard
                    } // end event guard
                }
                $run_id = $run_id_post ?: $run_id;
                $action = 'run_detail';
            } catch (Exception $e) {
                $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php');
            }
        }

    } elseif ($post_action === 'send_all_slips_to_line') {
        // ส่งสลิปทั้ง run ให้พนักงานทุกคน (เฉพาะคนที่ผูก LINE)
        $run_id_post = (int)($_POST['run_id'] ?? $run_id);
        if ($run_id_post <= 0) {
            $error = 'ไม่ระบุ run';
        } else {
            // Server-side guard: only allow bulk send for approved/paid runs
            $chk = $pdo->prepare("SELECT status FROM payroll_runs WHERE id = ? LIMIT 1");
            $chk->execute([$run_id_post]);
            $runStatus = $chk->fetchColumn();
            if (!$runStatus) {
                $error = 'ไม่พบ run';
            } elseif (!in_array($runStatus, ['approved', 'paid'], true)) {
                $error = 'ต้องอนุมัติ run ก่อน จึงจะส่งสลิปได้ (สถานะปัจจุบัน: ' . htmlspecialchars((string)$runStatus) . ')';
            } else {
            try {
                require_once HR_OWNER_PATH . '/core/Services/PayslipTokenService.php';
                require_once HR_OWNER_PATH . '/core/Services/LineNotifier.php';
                require_once HR_OWNER_PATH . '/config/line.php';
                require_once HR_OWNER_PATH . '/modules/payroll/notifications.php';

                $event_reason = null;
                if (!payroll_validate_slip_line_event($pdo, $event_reason)) {
                    throw new Exception($event_reason);
                }

                $stmt = $pdo->prepare("
                    SELECT s.*,
                           r.payroll_month, r.pay_day,
                           CONCAT(COALESCE(u.first_name_th,''), ' ', COALESCE(u.last_name_th,'')) AS full_name,
                           u.line_user_id
                    FROM payroll_slips s
                    JOIN payroll_runs r ON s.payroll_run_id = r.id
                    JOIN users u ON s.user_id = u.id
                    WHERE s.payroll_run_id = ? AND u.is_active = 1
                      AND " . payroll_employee_filter_sql('u') . "
                ");
                $stmt->execute([$run_id_post]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $thai_months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
                $ttl = (int)(function_exists('getSetting') ? getSetting('payroll_slip_link_ttl_days', '90') : '90');
                $throttle_sec = (int)(function_exists('getSetting') ? getSetting('payroll_slip_resend_throttle_sec', '60') : '60');
                $recentSentStmt = null;
                if ($throttle_sec > 0) {
                    $recentSentStmt = $pdo->prepare("SELECT COUNT(*) FROM line_notification_log
                        WHERE event = 'slip_issued' AND module = 'payroll'
                          AND target_user_id = ? AND status = 'sent'
                          AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
                }

                $sent_total = 0;
                $skipped_no_line = 0;
                $skipped_recent = 0;
                $failed = 0;
                foreach ($rows as $slipRow) {
                    if (empty($slipRow['line_user_id'])) { $skipped_no_line++; continue; }
                    try {
                        if ($recentSentStmt) {
                            $recentSentStmt->execute([(int)$slipRow['user_id'], $throttle_sec]);
                            if ((int)$recentSentStmt->fetchColumn() > 0) {
                                $skipped_recent++;
                                continue;
                            }
                        }

                        $token = PayslipTokenService::issue($pdo, (int)$slipRow['id'], (int)$slipRow['user_id'], (int)$current_user['id'], $ttl);
                        $url = PayslipTokenService::buildUrl($token);
                        $pm = strtotime($slipRow['payroll_month']);
                        $month_label = $thai_months[(int)date('n', $pm)] . ' ' . ((int)date('Y', $pm) + 543);
                        $pay_date_label = payroll_payment_date_label(payroll_slip_payment_date(getDB(), $slipRow));

                        // ดึง additional_tax_withholding เพื่อแยก base_tax / extra_tax
                        $extra_tax = null;
                        try {
                            $m_first = date('Y-m-01', strtotime($slipRow['payroll_month']));
                            $st_setup = $pdo->prepare("SELECT additional_tax_withholding, tax_opt_out FROM employee_salary_setup
                                WHERE user_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
                                ORDER BY effective_from DESC, id DESC LIMIT 1");
                            $st_setup->execute([(int)$slipRow['user_id'], $m_first, $m_first]);
                            $r_setup = $st_setup->fetch(PDO::FETCH_ASSOC);
                            if ($r_setup) {
                                $extra_tax = payroll_resolve_extra_tax_request($r_setup, !empty($r_setup['tax_opt_out']), $m_first);
                            }
                        } catch (Throwable $e) { /* legacy schema */ }

                        $deduction_breakdown = payroll_build_deduction_breakdown($slipRow, $extra_tax);

                        $has_attendance_ded = ((float)($slipRow['absence_deduction'] ?? 0) > 0)
                                            || ((float)($slipRow['lateness_deduction'] ?? 0) > 0);
                        $attendance_rules = $has_attendance_ded
                            ? payroll_get_attendance_rules_snapshot()
                            : null;

                        $ytd_year = (int)date('Y', strtotime($slipRow['payroll_month']));
                        $ytd_payload = payroll_format_ytd_payload(
                            payroll_fetch_ytd_for_user($pdo, (int)$slipRow['user_id'], $ytd_year, $slipRow['payroll_month']),
                            $ytd_year
                        );

                        $payload = payroll_flex_slip_issued(
                            trim($slipRow['full_name']) ?: 'พนักงาน',
                            $month_label,
                            (float)$slipRow['total_income'],
                            (float)$slipRow['total_deductions'],
                            (float)$slipRow['net_salary'],
                            $url,
                            $ytd_payload,
                            $pay_date_label,
                            $deduction_breakdown,
                            $attendance_rules
                        );
                        $s = LineNotifier::sendEvent($pdo, 'payroll.slip_issued', $payload, [
                            'triggering_user_id' => (int)$slipRow['user_id'],
                        ]);
                        if ($s > 0) $sent_total += $s; else $failed++;
                    } catch (Throwable $e) {
                        $failed++;
                        error_log('send_all_slips_to_line: slip ' . $slipRow['id'] . ' error: ' . $e->getMessage());
                    }
                }
                $message = sprintf(
                    'ส่งสำเร็จ %d ข้อความ · ข้ามพนักงานไม่ผูก LINE %d คน · ข้ามเพราะเพิ่งส่งภายใน %d วินาที %d คน · ล้มเหลว %d',
                    $sent_total,
                    $skipped_no_line,
                    max(0, $throttle_sec),
                    $skipped_recent,
                    $failed
                );
                $run_id = $run_id_post;
                $action = 'run_detail';
            } catch (Exception $e) {
                $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php');
            }
            } // end run-status guard else
        }

    } elseif ($post_action === 'revoke_slip_tokens') {
        // ยกเลิก token ที่ยังใช้งานอยู่ทั้งหมดของสลิป
        $slip_id_post = (int)($_POST['slip_id'] ?? 0);
        $run_id_post  = (int)($_POST['run_id'] ?? $run_id);
        if ($slip_id_post <= 0) {
            $error = 'ไม่ระบุสลิป';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE payroll_slip_tokens
                    SET revoked_at = NOW()
                    WHERE slip_id = ? AND revoked_at IS NULL");
                $stmt->execute([$slip_id_post]);
                $n = $stmt->rowCount();
                $message = $n > 0
                    ? sprintf('ยกเลิก token ที่ยังใช้งานอยู่ %d รายการสำเร็จ — ลิงก์ที่ส่งไปก่อนหน้านี้จะใช้งานไม่ได้อีก', $n)
                    : 'ไม่มี token ที่ยังใช้งานอยู่สำหรับสลิปนี้';
            } catch (Exception $e) {
                $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php');
            }
            $run_id = $run_id_post ?: $run_id;
            $action = 'run_detail';
        }

    } elseif ($post_action === 'save_payroll_benefit_settings') {
        $benefit = trim((string)($_POST['benefit'] ?? ''));
        $map = [
            'ss' => [
                'enabled_key' => 'payroll_ss_enabled',
                'from_key' => 'payroll_ss_enabled_from',
                'enabled_desc' => 'เปิดใช้การหักประกันสังคมหรือไม่ (0=ปิด, 1=เปิด)',
                'from_desc' => 'เดือนเริ่มหักประกันสังคมระดับบริษัท (YYYY-MM ว่าง=ทันทีเมื่อเปิด)',
                'saved' => 'ss_saved',
            ],
            'tax' => [
                'enabled_key' => 'payroll_tax_enabled',
                'from_key' => 'payroll_tax_enabled_from',
                'enabled_desc' => 'เปิดใช้การหักภาษี ณ ที่จ่าย (0=ปิด, 1=เปิด)',
                'from_desc' => 'เดือนเริ่มหักภาษีระดับบริษัท (YYYY-MM ว่าง=ทันทีเมื่อเปิด)',
                'saved' => 'tax_saved',
            ],
            'hi' => [
                'enabled_key' => 'payroll_health_insurance_enabled',
                'from_key' => 'payroll_health_insurance_enabled_from',
                'enabled_desc' => 'เปิดใช้การหักประกันสุขภาพพนักงาน (0=ปิด, 1=เปิด)',
                'from_desc' => 'เดือนเริ่มหักประกันสุขภาพระดับบริษัท (YYYY-MM ว่าง=ทันทีเมื่อเปิด)',
                'saved' => 'hi_saved',
            ],
            'gi' => [
                'enabled_key' => 'payroll_group_insurance_enabled',
                'from_key' => 'payroll_group_insurance_enabled_from',
                'enabled_desc' => 'เปิดใช้การหักประกันกลุ่ม (0=ปิด, 1=เปิด)',
                'from_desc' => 'เดือนเริ่มหักประกันกลุ่มระดับบริษัท (YYYY-MM ว่าง=ทันทีเมื่อเปิด)',
                'saved' => 'gi_saved',
            ],
        ];
        if (!isset($map[$benefit])) {
            $error = 'ไม่รู้จักประเภทสวัสดิการ';
        } else {
            $cfg = $map[$benefit];
            $enabled = !empty($_POST['benefit_enabled']) ? '1' : '0';
            try {
                $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, category, description)
                               VALUES (?, ?, 'HR', ?)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                    ->execute([$cfg['enabled_key'], $enabled, $cfg['enabled_desc']]);
                payroll_save_company_benefit_start(
                    $pdo,
                    $cfg['from_key'],
                    (string)($_POST['benefit_enabled_from'] ?? ''),
                    $cfg['from_desc']
                );
                header('Location: payroll.php?action=runs&' . $cfg['saved'] . '=1');
                exit;
            } catch (Exception $e) {
                $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php');
            }
        }
    } elseif ($post_action === 'toggle_tax_enabled') {
        $enabled = isset($_POST['tax_enabled']) && $_POST['tax_enabled'] === '1' ? '1' : '0';
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, category, description)
                           VALUES ('payroll_tax_enabled', ?, 'HR', 'เปิดใช้การหักภาษี ณ ที่จ่าย (0=ปิด, 1=เปิด)')
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([$enabled]);
            payroll_save_company_benefit_start(
                $pdo,
                'payroll_tax_enabled_from',
                (string)($_POST['tax_enabled_from'] ?? ''),
                'เดือนเริ่มหักภาษีระดับบริษัท (YYYY-MM ว่าง=ทันทีเมื่อเปิด)'
            );
            header('Location: payroll.php?action=runs&tax_saved=1');
            exit;
        } catch (Exception $e) {
            $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php');
        }
    } elseif ($post_action === 'toggle_health_insurance_enabled') {
        $enabled = isset($_POST['health_insurance_enabled']) && $_POST['health_insurance_enabled'] === '1' ? '1' : '0';
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, category, description)
                           VALUES ('payroll_health_insurance_enabled', ?, 'HR', 'เปิดใช้การหักประกันสุขภาพพนักงาน (0=ปิด, 1=เปิด)')
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([$enabled]);
            payroll_save_company_benefit_start(
                $pdo,
                'payroll_health_insurance_enabled_from',
                (string)($_POST['health_insurance_enabled_from'] ?? ''),
                'เดือนเริ่มหักประกันสุขภาพระดับบริษัท (YYYY-MM ว่าง=ทันทีเมื่อเปิด)'
            );
            header('Location: payroll.php?action=runs&hi_saved=1');
            exit;
        } catch (Exception $e) {
            $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php');
        }
    } elseif ($post_action === 'toggle_group_insurance_enabled') {
        $enabled = isset($_POST['group_insurance_enabled']) && $_POST['group_insurance_enabled'] === '1' ? '1' : '0';
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, category, description)
                           VALUES ('payroll_group_insurance_enabled', ?, 'HR', 'เปิดใช้การหักประกันกลุ่ม (0=ปิด, 1=เปิด)')
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([$enabled]);
            payroll_save_company_benefit_start(
                $pdo,
                'payroll_group_insurance_enabled_from',
                (string)($_POST['group_insurance_enabled_from'] ?? ''),
                'เดือนเริ่มหักประกันกลุ่มระดับบริษัท (YYYY-MM ว่าง=ทันทีเมื่อเปิด)'
            );
            header('Location: payroll.php?action=runs&gi_saved=1');
            exit;
        } catch (Exception $e) {
            $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php');
        }
    } elseif ($post_action === 'toggle_ss_enabled') {
        $enabled = isset($_POST['ss_enabled']) && $_POST['ss_enabled'] === '1' ? '1' : '0';
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, category, description)
                           VALUES ('payroll_ss_enabled', ?, 'HR', 'เปิดใช้การหักประกันสังคมหรือไม่ (0=ปิด, 1=เปิด)')
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([$enabled]);
            payroll_save_company_benefit_start(
                $pdo,
                'payroll_ss_enabled_from',
                (string)($_POST['ss_enabled_from'] ?? ''),
                'เดือนเริ่มหักประกันสังคมระดับบริษัท (YYYY-MM ว่าง=ทันทีเมื่อเปิด)'
            );
            $message = $enabled === '1' ? 'เปิดใช้ประกันสังคมแล้ว' : 'ปิดการหักประกันสังคมแล้ว';
            header('Location: payroll.php?action=runs&ss_saved=1');
            exit;
        } catch (Exception $e) {
            $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php');
        }
    } elseif ($post_action === 'save_salary_setup') {
        $post_user_id = (int)($_POST['user_id'] ?? 0);
        if ($post_user_id <= 0) {
            $error = 'ไม่ระบุพนักงาน';
            $action = 'employees';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
            $stmt->execute([$post_user_id]);
            if (!$stmt->fetch()) {
                $error = 'ไม่พบพนักงานหรือพนักงานไม่ได้เปิดใช้งาน';
                $action = 'employees';
            } else {
                $allowance_json = payroll_build_json_from_arrays($_POST['allowance_label'] ?? [], $_POST['allowance_amount'] ?? [], $_POST['allowance_custom_label'] ?? [], $_POST['allowance_ss_include'] ?? [], 'allowance');
                $income_other_json = payroll_build_json_from_arrays($_POST['income_other_label'] ?? [], $_POST['income_other_amount'] ?? [], $_POST['income_other_custom_label'] ?? [], $_POST['income_other_ss_include'] ?? [], 'income');
                $deduction_other_json = payroll_build_json_from_arrays($_POST['deduction_other_label'] ?? [], $_POST['deduction_other_amount'] ?? [], $_POST['deduction_other_custom_label'] ?? []);
                $payload = [
                    'scope_month' => trim($_POST['scope_month'] ?? ''),
                    'effective_from' => trim($_POST['effective_from'] ?? date('Y-m')),
                    'base_salary' => (float)($_POST['base_salary'] ?? 0),
                    'bonus_fixed' => max(0, (float)($_POST['bonus_fixed'] ?? 0)),
                    'provident_fund' => max(0, (float)($_POST['provident_fund'] ?? 0)),
                    'social_security' => max(0, (float)($_POST['social_security'] ?? 0)),
                    'social_security_manual' => !empty($_POST['social_security_manual']),
                    'group_insurance_total_monthly' => max(0, (float)($_POST['group_insurance_total_monthly'] ?? 0)),
                    'group_insurance_employer_pct' => (float)($_POST['group_insurance_employer_pct'] ?? 50),
                    'health_insurance_total_monthly' => max(0, (float)($_POST['health_insurance_total_monthly'] ?? 0)),
                    'health_insurance_employer_pct' => (float)($_POST['health_insurance_employer_pct'] ?? 50),
                    'ss_opt_out' => !empty($_POST['ss_opt_out']),
                    'tax_opt_out' => !empty($_POST['tax_opt_out']),
                    'hi_opt_out' => !empty($_POST['hi_opt_out']),
                    'gi_opt_out' => !empty($_POST['gi_opt_out']),
                    'additional_tax_withholding' => max(0, (float)($_POST['additional_tax_withholding'] ?? 0)),
                    'social_security_start_date' => trim($_POST['social_security_start_date'] ?? '') ?: null,
                    'tax_withholding_start_date' => trim($_POST['tax_withholding_start_date'] ?? '') ?: null,
                    'health_insurance_start_date' => trim($_POST['health_insurance_start_date'] ?? '') ?: null,
                    'group_insurance_start_date' => trim($_POST['group_insurance_start_date'] ?? '') ?: null,
                    'has_other_employer_income' => !empty($_POST['has_other_employer_income']),
                    'allowance_json' => $allowance_json,
                    'income_other_json' => $income_other_json,
                    'deduction_other_json' => $deduction_other_json,
                    'notes' => trim($_POST['notes'] ?? ''),
                    'created_by' => (int)$current_user['id'],
                ];
                try {
                    $result = payroll_service()->saveSalarySetup($post_user_id, $payload);
                    $scopeMonth = payroll_normalize_scope_month($payload['scope_month'] ?? null);
                    if ($scopeMonth !== null) {
                        $returnRunId = (int)($_POST['run_id'] ?? 0);
                        if ($returnRunId > 0) {
                            $redirect = 'payroll.php?run_id=' . $returnRunId . '&saved=1';
                        } else {
                            $redirect = 'payroll.php?action=salary_setup&user_id=' . $post_user_id . '&for_month=' . urlencode($scopeMonth) . '&saved=1';
                        }
                    } else {
                        $redirect = 'payroll.php?action=employees&saved=1';
                    }
                    if (!empty($result['warnings']) && in_array('hi_missing_start_date', $result['warnings'], true)) {
                        $redirect .= '&warn=hi_no_start_date';
                    }
                    header('Location: ' . $redirect);
                    exit;
                } catch (InvalidArgumentException $e) {
                    $error = $e->getMessage();
                } catch (RuntimeException $e) {
                    $msg = preg_replace('/\s*\([a-zA-Z_]+\)\s*$/', '', trim($e->getMessage()));
                    $error = ($msg !== '' && $msg !== 'Internal server error' && $msg !== 'HR API error')
                        ? $msg
                        : tpCrmUserErrorFromException($e, 'modules/payroll/actions.php save_salary_setup', false);
                } catch (Exception $e) {
                    $error = tpCrmUserErrorFromException($e, 'modules/payroll/actions.php save_salary_setup', false);
                }
            }
        }
    }
    }
}
