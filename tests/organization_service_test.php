<?php
require_once dirname(__DIR__).'/core/Services/OrganizationService.php';
$checkinHelpers=dirname(__DIR__,2).'/tp-checkin/core/Helpers.php';
$hasCheckin=is_file($checkinHelpers);
if ($hasCheckin) require_once $checkinHelpers;
else echo "[SKIP] Check-in integration: sibling tp-checkin checkout required\n";
function ok($v,$label) { if (!$v) throw new RuntimeException($label); echo "[OK] $label\n"; }
function rejects(callable $f,$label) { try { $f(); } catch (DomainException $e) { ok(true,$label); return; } throw new RuntimeException("Not rejected: $label"); }
if (!isset($db)) {
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE roles(id INTEGER PRIMARY KEY,name TEXT); INSERT INTO roles VALUES(1,'HR'),(2,'Staff'),(3,'Admin'),(4,'CEO'),(5,'Chairman');
CREATE TABLE users(id INTEGER PRIMARY KEY, role_id INT,is_active INT,is_employee INT,position TEXT); INSERT INTO users VALUES(1,1,1,1,'HR legacy'),(2,2,1,1,'Legacy developer'),(3,1,0,1,'Inactive'),(4,2,1,0,'System');
CREATE TABLE hr_positions(id INTEGER PRIMARY KEY AUTOINCREMENT,code TEXT UNIQUE,title TEXT,job_description TEXT,is_active INT DEFAULT 1);
CREATE TABLE hr_workplaces(id INTEGER PRIMARY KEY AUTOINCREMENT,code TEXT UNIQUE,name TEXT,address TEXT,description TEXT,is_active INT);
CREATE TABLE hr_checkin_locations(id INTEGER PRIMARY KEY AUTOINCREMENT,code TEXT UNIQUE,name TEXT,address TEXT,description TEXT,latitude NUMERIC,longitude NUMERIC,radius_meters INT,is_active INT);
CREATE TABLE hr_location_workplaces(location_id INT PRIMARY KEY,workplace_id INT);
CREATE TABLE hr_org_assignments(user_id INT PRIMARY KEY,position_id INT,workplace_id INT);
CREATE TABLE hr_org_history(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,entity_type TEXT,entity_id INT,old_values TEXT,new_values TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE hr_org_mutex(id INT PRIMARY KEY); INSERT INTO hr_org_mutex VALUES(1);
CREATE TABLE hr_attendances(id INTEGER PRIMARY KEY,check_in_location_id INT);");
}
$s=new OrganizationService($db);
ok($s->ready(),'schema readiness');
if ($hasCheckin) ok(!verifyCheckinLocation($db,13,100)['allowed'],'empty location catalog fails closed');
foreach(['HR','Admin','CEO','Chairman'] as $r) ok(OrganizationService::permits($r,true),'authorized role '.$r);
foreach(['Staff','Manager','Sales',''] as $r) ok(!OrganizationService::permits($r,true),'deny role '.$r);
ok(!$s->canManage(3),'inactive HR denied');
$p=['code'=>'DEV','title'=>'Developer','is_active'=>1];
rejects(fn()=>$s->save(2,'positions',0,'',$p),'employee cannot write');
rejects(fn()=>$s->save(3,'positions',0,'',$p),'inactive HR cannot write');
$pid=$s->save(1,'positions',0,'',$p);
rejects(fn()=>$s->save(1,'positions',0,'',$p),'duplicate code rejected');
$wid=$s->save(1,'workplaces',0,'',['code'=>'HQ','name'=>'Head office','is_active'=>1]);
$loc=['code'=>'GATE','name'=>'Original gate','latitude'=>13.75,'longitude'=>100.5,'radius_meters'=>100,'is_active'=>1,'workplace_id'=>$wid];
$lid=$s->save(1,'locations',0,'',$loc);
ok($s->save(1,'locations',$lid,OrganizationService::token($s->get('locations',$lid)),$loc)===$lid,'unchanged location retains identity');
if ($hasCheckin) ok(verifyCheckinLocation($db,13.75,100.5)['allowed'],'Check-in reads new HR location');
if ($hasCheckin) ok(!verifyCheckinLocation($db,14,101)['allowed'],'outside GPS rejected');
rejects(fn()=>$s->save(1,'locations',$lid,OrganizationService::token($s->get('locations',$lid)),array_replace($loc,['is_active'=>0])),'cannot deactivate final location');
rejects(fn()=>$s->save(1,'workplaces',$wid,OrganizationService::token($s->get('workplaces',$wid)),['code'=>'HQ','name'=>'HQ','is_active'=>0]),'workplace cannot hide active locations');
$db->exec('INSERT INTO hr_attendances VALUES(1,'.$lid.')');
$oldToken=OrganizationService::token($s->get('locations',$lid));
$newId=$s->save(1,'locations',$lid,$oldToken,array_replace($loc,['name'=>'New gate','latitude'=>14]));
ok($newId!==$lid,'location edit creates revision');
ok($s->get('locations',$lid)['name']==='Original gate','old name preserved');
ok((float)$s->get('locations',$lid)['latitude']===13.75,'old coordinates preserved');
ok((int)$db->query('SELECT check_in_location_id FROM hr_attendances')->fetchColumn()===$lid,'attendance FK unchanged');
if ($hasCheckin) ok(!verifyCheckinLocation($db,13.75,100.5)['allowed'],'retired coordinates no longer accepted');
if ($hasCheckin) ok(verifyCheckinLocation($db,14,100.5)['location']['id']==$newId,'new coordinates used by Check-in');
rejects(fn()=>$s->save(1,'locations',$lid,$oldToken,$loc),'stale edit rejected');
rejects(fn()=>$s->save(1,'locations',$lid,OrganizationService::token($s->get('locations',$lid)),$loc),'historical revision cannot be reactivated');
foreach ([['latitude'=>91],['longitude'=>181],['radius_meters'=>0],['radius_meters'=>10001],['latitude'=>'bad']] as $invalid) rejects(fn()=>$s->save(1,'locations',0,'',array_replace($loc,$invalid)),'invalid geometry rejected');
$before=$s->assignment(2);
$s->assign(1,2,$pid,$wid,OrganizationService::token($before));
ok($s->assignment(2)['position_text']==='Developer','compatibility position updated');
$guardedUpdate=$db->prepare('UPDATE users SET position='.OrganizationService::employeePositionUpdateSql(true).' WHERE id=?');
$guardedUpdate->execute(['Stale employee form',2]);
ok($s->assignment(2)['position_text']==='Developer','legacy employee editor cannot overwrite linked position');
ok($s->assignment(2)['workplace_name']==='Head office','workplace name snapshotted in assignment history');

ok((int)$db->query('SELECT role_id FROM users WHERE id=2')->fetchColumn()===2,'assignment never changes role');
rejects(fn()=>$s->assign(1,2,0,0,OrganizationService::token($before)),'stale employee assignment rejected');
rejects(fn()=>$s->assign(1,4,$pid,$wid,OrganizationService::token($s->assignment(4))),'non-employee assignment denied');
$s->save(1,'positions',$pid,OrganizationService::token($s->get('positions',$pid)),array_replace($p,['is_active'=>0]));
rejects(fn()=>$s->assign(1,1,$pid,$wid,OrganizationService::token($s->assignment(1))),'inactive position cannot be newly assigned');
$s->assign(1,2,$pid,$wid,OrganizationService::token($s->assignment(2)));
ok($s->assignment(2)['position_text']==='Developer','existing inactive assignment retained');
$s->assign(1,2,0,0,OrganizationService::token($s->assignment(2)));
ok($s->assignment(2)['position_text']==='Developer','unlink preserves legacy text');
$db->exec('DROP TABLE hr_org_history');
try { $s->save(1,'positions',0,'',['code'=>'FAIL','title'=>'Must roll back','is_active'=>1]); throw new RuntimeException('audit failure did not fail'); } catch(PDOException $e) {}
ok(!(bool)$db->query("SELECT 1 FROM hr_positions WHERE code='FAIL'")->fetchColumn(),'audit failure rolls back mutation');
echo "Organization lifecycle and Check-in integration fixtures passed.\n";
