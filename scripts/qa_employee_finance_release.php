<?php
declare(strict_types=1);
$app=$argv[1]??'';if(!in_array($app,['crm','hr'],true))exit(1);
$script=__DIR__.'/deploy_employee_finance_readonly.sh';
$tmp=sys_get_temp_dir().'/employee-release-'.bin2hex(random_bytes(6));mkdir($tmp.'/bin',0700,true);
file_put_contents($tmp.'/bin/curl',"#!/bin/sh\nexit \"\${MOCK_HTTP_EXIT:-0}\"\n");chmod($tmp.'/bin/curl',0700);
file_put_contents($tmp.'/bin/flock',"#!/bin/sh\nexit 0\n");chmod($tmp.'/bin/flock',0700);
$env=getenv();$env['PATH']=$tmp.'/bin:'.dirname(PHP_BINARY).':'.$env['PATH'];$env['PHP_BIN']=PHP_BINARY;
$runtime=$app==='crm'?'expense_request_form.php':'core/Services/EmployeeFinanceManagementService.php';$repo='';
$run=static function(array $command)use(&$repo,&$env):array{$p=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$repo,$env);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);return [proc_close($p),$out.$err];};
$git=static function(string ...$args)use($run):string{[$status,$out]=$run(['git',...$args]);if($status!==0)throw new RuntimeException($out);return trim($out);};
$count=0;
try {
    foreach(['success','forbidden','dirty','unrelated_dirty','http_failure','dependency_not_ready','fingerprint_diff'] as $scenario){
        $repo=$tmp.'/'.$scenario.'/app';mkdir($repo.'/vendor',0700,true);mkdir($repo.'/scripts',0700);if(!is_dir(dirname($repo.'/'.$runtime)))mkdir(dirname($repo.'/'.$runtime),0700,true);
        $git('init','-q');$git('config','user.name','Synthetic release test');$git('config','user.email','qa@example.invalid');
        file_put_contents($repo.'/'.$runtime,"<?php /* synthetic baseline */\n");file_put_contents($repo.'/runtime.css','baseline');
        $autoload='<?php namespace TpCommon\\Hr; final class EmployeeFinancePolicy {public const MAX_TERM_MONTHS=12;public const MIN_MONTHLY_INSTALLMENT=2000.0;public static function rebuildExistingSchedule(){}}';
        file_put_contents($repo.'/vendor/autoload.php',$autoload);
        $audit='<?php echo hash("sha256",getenv("MOCK_STATE_DIFF") && str_contains(file_get_contents('.var_export($runtime,true).'),"release") ? "after" : "before").PHP_EOL;';
        file_put_contents($repo.'/scripts/audit_employee_finance_release.php',$audit);
        $git('add','.');$git('-c','commit.gpgsign=false','commit','-qm','baseline');$previous=$git('rev-parse','HEAD');
        file_put_contents($repo.'/'.$runtime,"<?php /* synthetic release */\n");
        if($scenario==='forbidden'){mkdir($repo.'/database/migrations',0700,true);file_put_contents($repo.'/database/migrations/no.sql','SELECT 1;');}
        $git('add','.');$git('-c','commit.gpgsign=false','commit','-qm','target');$target=$git('rev-parse','HEAD');$git('checkout','-q','--detach',$previous);
        file_put_contents($repo.'/.env','preserved environment sentinel');file_put_contents($repo.'/db-sentinel','preserved financial sentinel');
        if($scenario==='dirty')file_put_contents($repo.'/'.$runtime,'<?php /* user change */');
        if($scenario==='unrelated_dirty')file_put_contents($repo.'/runtime.css','preserved runtime change');
        if($scenario==='dependency_not_ready')file_put_contents($repo.'/vendor/autoload.php',str_replace('MAX_TERM_MONTHS=12','MAX_TERM_MONTHS=6',$autoload));
        $env['MOCK_HTTP_EXIT']=$scenario==='http_failure'?'22':'0';$env['MOCK_STATE_DIFF']=$scenario==='fingerprint_diff'?'1':'0';
        [$status,$out]=$run(['bash',$script,$repo,$target,$app]);$success=in_array($scenario,['success','unrelated_dirty'],true);
        if(($success?($status!==0||$git('rev-parse','HEAD')!==$target):($status===0||$git('rev-parse','HEAD')!==$previous)))throw new RuntimeException($scenario.': '.$out);
        if(file_get_contents($repo.'/.env')!=='preserved environment sentinel'||file_get_contents($repo.'/db-sentinel')!=='preserved financial sentinel')throw new RuntimeException('Sentinel changed');
        if($scenario==='dirty'&&!str_contains(file_get_contents($repo.'/'.$runtime),'user change'))throw new RuntimeException('User change lost');
        if($scenario==='unrelated_dirty'&&file_get_contents($repo.'/runtime.css')!=='preserved runtime change')throw new RuntimeException('Runtime change lost');
        $count++;echo "PASS $app isolated release guard $scenario\n";
    }
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($tmp);
}
echo "$count disposable deployment-control checks passed; transport/lock/protected-state reader mocked, no application data.\n";
