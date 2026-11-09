<?php
require dirname(__DIR__).'/core/Services/AttendanceCapturePolicy.php';
require __DIR__.'/support/attendance_photo_fixtures.php';
date_default_timezone_set('Asia/Bangkok');$checks=0;
function captureCheck(bool $pass,string $label):void{global $checks;if(!$pass)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function captureReject(callable $fn,string $label):void{try{$fn();}catch(DomainException $e){captureCheck(true,$label);return;}throw new RuntimeException('Accepted: '.$label);}
$now=1794200000000;$input=['latitude'=>0,'longitude'=>0,'gps_captured_at'=>$now,'accuracy'=>12.345];
$gps=AttendanceCapturePolicy::validateGps($input,$now);
captureCheck($gps['latitude']===0.0 && $gps['longitude']===0.0 && $gps['accuracy']===12.35,'zero coordinates and accuracy preserved');
foreach([
 ['latitude'=>null],['longitude'=>181],['latitude'=>91],['latitude'=>'NaN'],['latitude'=>[]],
 ['gps_captured_at'=>null],['gps_captured_at'=>$now-120001],['gps_captured_at'=>$now+30001],
 ['accuracy'=>-1],['accuracy'=>'INF'],['accuracy'=>[]]
] as $i=>$bad)captureReject(fn()=>AttendanceCapturePolicy::validateGps(array_replace($input,$bad),$now),'invalid or stale GPS '.$i);
captureCheck(AttendanceCapturePolicy::normalCaptureTime([],1794200000)===date('Y-m-d H:i:s',1794200000),'capture uses server time');
captureReject(fn()=>AttendanceCapturePolicy::normalCaptureTime(['time'=>'2000-01-01 08:00:00']),'caller cannot backdate normal capture');
captureReject(fn()=>AttendanceCapturePolicy::normalCaptureTime(['type'=>'MANUAL']),'manual capture uses separate HR workflow');
$d=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$d->exec('CREATE TABLE hr_checkin_locations(id INTEGER PRIMARY KEY,is_active INTEGER,latitude REAL,longitude REAL,radius_meters INTEGER)');
$d->exec('INSERT INTO hr_checkin_locations VALUES(1,1,0,0,100),(2,0,0,0,100)');
$d->beginTransaction();AttendanceCapturePolicy::assertLocation($d,$gps,1,['work_mode'=>'OFFICE'],true);$d->rollBack();captureCheck(true,'active point at coordinate zero accepted');
captureReject(fn()=>AttendanceCapturePolicy::assertLocation($d,$gps,2,[],true),'inactive point rejected');
captureReject(fn()=>AttendanceCapturePolicy::assertLocation($d,$gps,null,[],true),'missing point rejected');
captureReject(fn()=>AttendanceCapturePolicy::assertLocation($d,array_replace($gps,['latitude'=>0.002]),1,[],true),'out of radius requires outside approval');
$d->exec('UPDATE hr_checkin_locations SET latitude=0.005 WHERE id=1');
captureReject(fn()=>AttendanceCapturePolicy::assertLocation($d,$gps,1,[],true),'geometry revision cannot accept stale cached point');
AttendanceCapturePolicy::assertLocation($d,$gps,null,['work_mode'=>'WFH'],true);captureCheck(true,'existing WFH geofence exception retained');
$d->exec('CREATE TABLE hr_attendances(id INTEGER PRIMARY KEY,check_in_gps_captured_at TEXT,check_in_gps_accuracy REAL,check_in_server_received_at TEXT,check_in_photo TEXT,check_in_device_info TEXT)');$d->exec('INSERT INTO hr_attendances(id) VALUES(7)');
$d->beginTransaction();AttendanceCapturePolicy::persistMetadata($d,7,'in',$gps,'photos/qa.jpg','test-device','2026-11-09 11:00:00');$d->rollBack();captureCheck($d->query('SELECT check_in_photo FROM hr_attendances')->fetchColumn()===null,'metadata rolls back with attendance transaction');
$d->beginTransaction();AttendanceCapturePolicy::persistMetadata($d,7,'in',$gps,'photos/qa.jpg','test-device','2026-11-09 11:00:00');$d->commit();captureCheck($d->query('SELECT check_in_photo FROM hr_attendances')->fetchColumn()==='photos/qa.jpg','metadata commits only with attendance transaction');
$root=sys_get_temp_dir().'/hr-photo-capture-'.bin2hex(random_bytes(6));$rel='photos/checkin/2026/11/checkin_7_20261109110000_abcdef.jpg';mkdir(dirname($root.'/'.$rel),0700,true);$file=$root.'/'.$rel;
try{
 file_put_contents($file,photoFixture(320,240,'detail','jpeg'));
 captureCheck(AttendanceCapturePolicy::validatePhotoPath($rel,7,'in',$root)===$rel,'current owned detailed photo accepted');
 captureReject(fn()=>AttendanceCapturePolicy::validatePhotoPath($rel,8,'in',$root),'another user photo rejected');
 captureReject(fn()=>AttendanceCapturePolicy::validatePhotoPath($rel,7,'out',$root),'entry photo cannot be reused for exit');
 captureReject(fn()=>AttendanceCapturePolicy::validatePhotoPath('../'.$rel,7,'in',$root),'photo path traversal rejected');
 touch($file,time()-121);clearstatcache(true,$file);captureReject(fn()=>AttendanceCapturePolicy::validatePhotoPath($rel,7,'in',$root),'expired uploaded photo rejected');
 touch($file,time()+31);clearstatcache(true,$file);captureReject(fn()=>AttendanceCapturePolicy::validatePhotoPath($rel,7,'in',$root),'future uploaded photo rejected');
 file_put_contents($file,photoFixture(320,240,'black','jpeg'));touch($file);clearstatcache(true,$file);captureReject(fn()=>AttendanceCapturePolicy::validatePhotoPath($rel,7,'in',$root),'privileged API cannot use saved black photo');
 unlink($file);$outside=$root.'/outside.jpg';file_put_contents($outside,photoFixture(320,240));symlink($outside,$file);captureReject(fn()=>AttendanceCapturePolicy::validatePhotoPath($rel,7,'in',$root),'symlink escape rejected');unlink($file);unlink($outside);
}finally{if(is_link($file)||is_file($file))unlink($file);$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p)$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());rmdir($root);}
echo "Attendance capture policy: $checks passed\n";
