<?php
require_once dirname(__DIR__).'/core/Services/HrOperationsService.php';
        $service=(new ReflectionClass(HrOperationsService::class))->newInstanceWithoutConstructor();
        $api=new class { public int $calls=0; public function crmCheckin($body){$this->calls++;return ['success'=>false];} public function crmCheckout($body){$this->calls++;return ['success'=>false];} };
        foreach (['hrClient'=>$api,'useApi'=>true] as $key=>$value) { $p=new ReflectionProperty(HrOperationsService::class,$key);$p->setValue($service,$value); }
        // PDO intentionally uninitialized: any fallback write fails this test.
        foreach ([true,false] as $mode) {
            (new ReflectionProperty(HrOperationsService::class,'useApi'))->setValue($service,$mode);
            foreach ([fn()=>$service->webCheckIn(1,'2026-11-09','2026-11-09 07:33:03',13,100,null,'',0,'PRESENT'),fn()=>$service->webCheckOut(1,'2026-11-09 17:00:00',null,null,0)] as $call) {
                try { $call(); throw new RuntimeException('Retired path accepted'); } catch(DomainException $e) { if (!str_contains($e->getMessage(),'checkin.tp-asset.com')) throw $e; }
            }
        }
        if ($api->calls!==0) throw new RuntimeException('API called');
echo "PASS retired capture on both API modes without database writes\n";
