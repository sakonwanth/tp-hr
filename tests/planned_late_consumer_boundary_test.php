<?php
namespace TpCommon\Http {
    final class HrClient {
        public static array $response=[];
        public static array $calls=[];
        public static function fromEnv():self{return new self();}
        public function decideLineApproval(string $workflow,int $id,string $decision,int $actor):array{self::$calls[]=[$workflow,$id,$decision,$actor];return self::$response;}
    }
}
namespace {
    define('BASE_PATH',__DIR__); // external consumer, deliberately not HR root
    require dirname(__DIR__).'/core/Services/HrOperationsService.php';
    final class NoLocalHrWrites extends PDO {
        public int $calls=0;
        public function __construct(){parent::__construct('sqlite::memory:');}
        public function prepare(string $query,array $options=[]):PDOStatement|false{$this->calls++;throw new LogicException('Consumer touched local database');}
    }
    $db=new NoLocalHrWrites();$svc=new HrOperationsService($db);
    foreach([['success'=>true,'data'=>['id'=>1,'planned_status'=>'APPROVED']],['success'=>true,'data'=>['data'=>['id'=>1,'planned_status'=>'APPROVED']]]] as $response){
        \TpCommon\Http\HrClient::$response=$response;$r=$svc->decidePlannedLate(1,2,'APPROVED');
        if($r!==['id'=>1,'planned_status'=>'APPROVED'])throw new RuntimeException('API response normalization failed');
    }
    \TpCommon\Http\HrClient::$response=['success'=>false,'error'=>'Fixture API denial'];
    try{$svc->decidePlannedLate(1,2,'APPROVED');throw new LogicException('API denial accepted');}catch(RuntimeException $e){if($e->getMessage()!=='Fixture API denial')throw $e;}
    if($db->calls!==0||count(\TpCommon\Http\HrClient::$calls)!==3||\TpCommon\Http\HrClient::$calls[0]!==['planned_late',1,'approve',2])throw new RuntimeException('Consumer API boundary failed');
    echo "PASS external HR consumer delegates to canonical API; denied API never falls through to local writes\n";

    // Execute the real API catch boundary without bootstrap/authentication or network traffic.
    final class FixtureApiFailure extends \Exception {}
    final class ApiAuth { public static function fail(int $status,string $message):never{throw new FixtureApiFailure($message,$status);} }
    final class HrLineApprovalService {
        public static \Throwable $failure;
        public function __construct(PDO $pdo) {}
        public function decide(...$args):never{throw self::$failure;}
    }
    function tpHrLogException(...$args):void{}
    $route=file_get_contents(dirname(__DIR__).'/api/v1/line_approvals.php');$route=substr($route,strpos($route,'try {'));
    $pdo=$db;$workflow='planned_late';$id=1;$actorId=2;$decision='approve';
    foreach([[new \DomainException('clock unavailable',503),503],[new \DomainException('business conflict'),409],[new \DomainException('other business validation',422),409],[new \InvalidArgumentException('invalid input'),400],[new \RuntimeException('fixture internal failure'),500]] as [$failure,$expected]) {
        HrLineApprovalService::$failure=$failure;
        try{eval($route);throw new \LogicException('Missing API error');}catch(FixtureApiFailure $result){if($result->getCode()!==$expected)throw new \RuntimeException('Approval error status mapping failed');}
    }
    echo "PASS approval API preserves clock HTTP503 and existing business/input/internal status mappings\n";

}
