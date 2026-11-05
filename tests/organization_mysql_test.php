<?php
// Only an explicitly supplied isolated, socket-only QA server is accepted.
$socket = $argv[1] ?? '';
if (!preg_match('#^/tmp/tp-hr-org-qa\.[A-Za-z0-9]+/mysql\.sock$#D', $socket)) {
    fwrite(STDERR,"Usage: php tests/organization_mysql_test.php /tmp/tp-hr-org-qa.XXXXXX/mysql.sock\n"); exit(1);
}
if (($argv[2] ?? '') === '--deactivate') {
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    if (!preg_match('/^tp_hr_org_qa_[0-9]+$/D',$input['database']??'')) exit(2);
    require dirname(__DIR__).'/core/Services/OrganizationService.php';
    $workerDb=new PDO('mysql:unix_socket='.$socket.';dbname='.$input['database'],'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $worker=new OrganizationService($workerDb);
    try { $worker->save(1,'locations',$input['id'],$input['version'],$input['values']); echo 'closed'; }
    catch(DomainException $e) { echo 'blocked'; }
    exit;
}
$admin = new PDO('mysql:unix_socket='.$socket,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$dbName='tp_hr_org_qa_'.getmypid();
$admin->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4');
try {
    $db = new PDO('mysql:unix_socket='.$socket.';dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec("CREATE TABLE roles(id INT PRIMARY KEY,name VARCHAR(50)); INSERT INTO roles VALUES(1,'HR'),(2,'Staff'),(3,'Admin'),(4,'CEO'),(5,'Chairman');
    CREATE TABLE users(id INT PRIMARY KEY,role_id INT,is_active INT,is_employee INT,position VARCHAR(100)); INSERT INTO users VALUES(1,1,1,1,'HR legacy'),(2,2,1,1,'Legacy developer'),(3,1,0,1,'Inactive'),(4,2,1,0,'System');");
    $schema=file_get_contents(dirname(__DIR__).'/database/schema.sql');
    foreach(['hr_departments','hr_positions','hr_checkin_locations'] as $table) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS '.$table.' \(.*?;\s/s',$schema,$m)) throw new RuntimeException('Missing table '.$table);
        $db->exec($m[0]);
    }
    $db->exec('CREATE TABLE hr_attendances(id INT PRIMARY KEY,check_in_location_id INT,FOREIGN KEY(check_in_location_id) REFERENCES hr_checkin_locations(id))');
    $migration=file_get_contents(dirname(__DIR__).'/database/migrations/2026_11_05_hr_organization.sql');
    $db->exec($migration); $db->exec($migration);
    echo "[OK] actual MySQL migration applies twice\n";
    require __DIR__.'/organization_service_test.php';
    $db->exec($migration); // lifecycle intentionally removed audit table to test rollback
    $extra=$s->save(1,'locations',0,'',['code'=>'SECOND','name'=>'Second gate','latitude'=>15,'longitude'=>101,'radius_meters'=>100,'is_active'=>1]);
    $db->beginTransaction();
    $db->query('SELECT id FROM hr_org_mutex WHERE id=1 FOR UPDATE')->fetchColumn();
    $jobs=[];
    foreach ([$newId,$extra] as $candidate) {
        $value=$s->get('locations',$candidate); $token=OrganizationService::token($value); $value['is_active']=0;
        $process=proc_open([PHP_BINARY,__FILE__,$socket,'--deactivate'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fwrite($pipes[0],json_encode(['database'=>$dbName,'id'=>$candidate,'version'=>$token,'values'=>$value])); fclose($pipes[0]);
        $jobs[]=[$process,$pipes];
    }
    usleep(150000); // Both requests queue behind the same database lock.
    $db->commit();
    $results=[];
    foreach($jobs as [$process,$pipes]) {
        $results[]=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process)!==0) throw new RuntimeException('worker failed: '.$errors);
    }
    sort($results);
    ok($results===['blocked','closed'],'concurrent deactivation preserves one active point');
    ok((int)$db->query('SELECT COUNT(*) FROM hr_checkin_locations WHERE is_active=1')->fetchColumn()===1,'concurrent final location invariant');
} finally { $admin->exec('DROP DATABASE '.$dbName); }
