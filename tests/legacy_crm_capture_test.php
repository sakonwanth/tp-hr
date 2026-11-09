<?php
final class ApiAuth {
    public static function requireMethod($methods){return 'POST';}
    public static function require($scopes):void{}
    public static function fail($status,$message):void{throw new DomainException($message,$status);}
}
function getDB(){return new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
foreach(['crm-checkin','crm-checkout','plan-late','cancel-plan-late'] as $actionName){
    $segments=['attendance',$actionName];
    try {require dirname(__DIR__).'/api/v1/attendance.php';throw new RuntimeException('Legacy capture accepted');}
    catch(DomainException $e){if($e->getCode()!==410)throw $e;echo "OK $actionName rejected before parsing or database writes\n";}
}
