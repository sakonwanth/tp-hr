<?php
require dirname(__DIR__) . '/core/Services/ClockHealth.php';
$checks = 0;
function clockCheck(bool $pass, string $label):void { global $checks;if(!$pass)throw new RuntimeException($label);$checks++;echo "PASS $label\n"; }
$now = 1800000000;
$good = ['status'=>'healthy','checked_at'=>$now-30,'checked_boot_seconds'=>970.0,'ntp_active'=>true,'synchronized'=>true,'offset_ms'=>0.4,'critical_threshold_ms'=>2000,'reason_codes'=>[]];
clockCheck(ClockHealth::evaluate($good,$now,1000)['status']==='healthy','fresh synchronized status accepted');
clockCheck(ClockHealth::evaluate(array_replace($good,['checked_at'=>$now-181]),$now)['status']==='critical','stale status rejected');
clockCheck(ClockHealth::evaluate(array_replace($good,['checked_at'=>$now+6]),$now)['status']==='critical','future status detects backwards wall-clock jump');
clockCheck(ClockHealth::evaluate($good,$now,1100)['status']==='critical','wall versus boot elapsed mismatch detects time jump');
clockCheck(ClockHealth::evaluate($good,$now,100)['status']==='critical','previous-boot status rejected');
clockCheck(ClockHealth::evaluate(array_replace($good,['ntp_active'=>false]),$now)['status']==='critical','inactive NTP rejected');
clockCheck(ClockHealth::evaluate(array_replace($good,['synchronized'=>false]),$now)['status']==='critical','unsynchronized NTP rejected');
clockCheck(ClockHealth::evaluate(array_replace($good,['offset_ms'=>2001]),$now)['status']==='critical','positive offset over limit rejected');
clockCheck(ClockHealth::evaluate(array_replace($good,['offset_ms'=>-2001]),$now)['status']==='critical','negative offset over limit rejected');
clockCheck(ClockHealth::evaluate(array_replace($good,['offset_ms'=>null]),$now)['status']==='critical','unknown offset rejected');
clockCheck(ClockHealth::evaluate(array_replace($good,['offset_ms'=>NAN]),$now)['status']==='critical','nonfinite offset rejected');
clockCheck(ClockHealth::evaluate(array_replace($good,['checked_monotonic_seconds'=>1000]),$now,null,1030)['status']==='healthy','shared monotonic clock accepted without proc');
clockCheck(ClockHealth::evaluate(array_replace($good,['checked_monotonic_seconds'=>1000]),$now,null,1040)['status']==='critical','monotonic detects clock step when proc is restricted');
clockCheck(ClockHealth::evaluate([], $now)['status']==='critical','malformed status rejected');
clockCheck(ClockHealth::evaluate(array_replace($good,['status'=>'warning','reason_codes'=>['independent_unavailable']]),$now)['status']==='warning','independent probe warning retained');
// Path metadata may change before open; admit only the opened file metadata.
final class ClockRaceStream {
 public $context; public static string $body=''; private int $position=0;
 public function url_stat($path,$flags):array{return ['mode'=>0100600,'uid'=>0,'nlink'=>1,'size'=>strlen(self::$body)];}
 public function stream_open($path,$mode,$options,&$opened):bool{return true;}
 public function stream_stat():array{return ['mode'=>0100666,'uid'=>0,'nlink'=>1,'size'=>strlen(self::$body)];}
 public function stream_read($count):string{$s=substr(self::$body,$this->position,$count);$this->position+=strlen($s);return $s;}
 public function stream_eof():bool{return $this->position>=strlen(self::$body);}
 public function stream_tell():int{return $this->position;}
 public function stream_set_option($option,$arg1,$arg2):bool{return false;}
}
ClockRaceStream::$body=json_encode(array_diff_key($good,['checked_boot_seconds'=>true]));
stream_wrapper_register('clockrace',ClockRaceStream::class);
try {clockCheck(ClockHealth::read('clockrace://status',$now,true)['status']==='critical','opened file permissions checked after path metadata race');}
finally {stream_wrapper_unregister('clockrace');}
$fileGood=$good;unset($fileGood['checked_boot_seconds']);
$dir=sys_get_temp_dir().'/clock-health-qa-'.bin2hex(random_bytes(5));mkdir($dir,0700);$path=$dir.'/status.json';
try {
 clockCheck(ClockHealth::read($path,$now,false)['status']==='disabled','nonproduction missing monitor does not block local development');
 clockCheck(ClockHealth::read($path,$now,true)['status']==='critical','production missing monitor rejected');
 file_put_contents($path,'not json');chmod($path,0600);clockCheck(ClockHealth::read($path,$now,true)['status']==='critical','invalid JSON rejected');
 file_put_contents($path,json_encode($fileGood));chmod($path,0666);clockCheck(ClockHealth::read($path,$now,true)['status']==='critical','writable monitor status untrusted');
 chmod($path,0600);clockCheck(ClockHealth::read($path,$now,true)['status']==='healthy','protected fresh fixture accepted');
 // Simulate Plesk open_basedir plus its exception-throwing error handler.
 $script=$dir.'/basedir-test.php';
 file_put_contents($script,'<?php require '.var_export(dirname(__DIR__).'/core/Services/ClockHealth.php',true).'; set_error_handler(function($severity,$message){throw new ErrorException($message,0,$severity);}); echo json_encode(ClockHealth::read('.var_export($path,true).','.$now.',true));');
 $command=escapeshellarg(PHP_BINARY).' -d '.escapeshellarg('open_basedir='.dirname(__DIR__).PATH_SEPARATOR.$dir).' '.escapeshellarg($script);
 $output=[];$code=0;exec($command,$output,$code);$response=json_decode(implode('\n',$output),true);
 clockCheck($code===0&&($response['status']??null)==='healthy','unavailable proc uptime does not invalidate valid protected status');
 unlink($script);
 ClockHealth::assertSafe($path,$now,true);
 $warn=array_replace($fileGood,['status'=>'warning']);file_put_contents($path,json_encode($warn));ClockHealth::assertSafe($path,$now,true);clockCheck(true,'warning does not block capture');
 $bad=array_replace($fileGood,['ntp_active'=>false]);file_put_contents($path,json_encode($bad));
 try{ClockHealth::assertSafe($path,$now,true);throw new RuntimeException('Unsafe time accepted');}catch(DomainException $e){clockCheck(str_contains($e->getMessage(),'เวลาของระบบ')&&$e->getCode()===503,'critical status stops capture with503 user message');}
 unlink($path);symlink($dir.'/missing',$path);clockCheck(ClockHealth::read($path,$now,true)['status']==='critical','invalid status symlink rejected');unlink($path);
} finally {if(is_file($path)||is_link($path))unlink($path);rmdir($dir);}
echo "Clock health: $checks passed\n";
