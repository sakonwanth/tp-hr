<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/core/Services/OrganizationService.php';
$service=new OrganizationService(getDB());
if (!$service->ready()) { fwrite(STDERR,"HR organization schema incomplete\n"); exit(1); }
echo "HR organization schema ready\n";
