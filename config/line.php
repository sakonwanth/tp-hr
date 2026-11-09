<?php
require_once dirname(__DIR__).'/core/LegacyHrUi.php';
require_once dirname(__DIR__).'/core/CrmLineNotifierBridge.php';
crm_line_bridge_ingest_crm_env(hrTransportCrmRoot());
if (!function_exists('env_value')) {function env_value($key,$default=null){return crm_line_bridge_env_value($key,$default);}}
require_once hrTransportCrmRoot().'/config/line.php';
