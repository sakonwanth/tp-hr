<?php
/**
 * TP-HR shim → canonical CRM LINE bridge in tp-common (Phase 2.3).
 *
 * The bridge functions now live in tp-common/src/Hr/crm_line_bridge.php (shared with
 * tp-checkin). HR opts into its historical behavior: bypass_schedule on workflow events
 * + ingesting tp-crm/.env for LINE keys. All crm_line_* call sites are unchanged.
 */

$__crmLineBridge = dirname(__DIR__) . '/vendor/tpasset/tp-common/src/Hr/crm_line_bridge.php';
if (!is_file($__crmLineBridge)) {
    $__crmLineBridge = dirname(dirname(__DIR__)) . '/tp-common/src/Hr/crm_line_bridge.php';
}
require_once $__crmLineBridge;
unset($__crmLineBridge);

crm_line_bridge_set_bypass_schedule(true);
crm_line_bridge_set_ingest_crm_env(true);
