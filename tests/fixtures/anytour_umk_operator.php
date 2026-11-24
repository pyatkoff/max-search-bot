<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__,2).'/tools/anytour_umk_funnel.php';

function checkAnyTourUmkOperator(PDO $work,AnyTourUmkLateDelivery $worker,int $epoch): void
{
    $catalogReads = 0;
    $catalogOk = true;
    $sets = 0;
    $readGoal = static function() use (&$catalogReads,&$catalogOk):bool { $catalogReads++; return $catalogOk; };
    $changeMode = static function(int $at,bool $on) use ($worker,&$sets):void { $sets++; $worker->setDelivery($at,$on); };
    $inspect = AnyTourUmkOperator::execute(['status'],$work,$readGoal,$changeMode)['state'];
    checkUmk($inspect['mode']==='collect' && $inspect['epoch']===$epoch && $inspect['all_early_launch_count']===null
        && $inspect['late_profiles']['by_state']['processed']===['count'=>1,'delta_kopeks'=>83300]
        && $inspect['late_profiles']['by_state']['unknown']===['count'=>1,'delta_kopeks'=>200]
        && $inspect['early_reservations_on_evaluated_inquiries']['positive_count']===0
        && $catalogReads===0 && $sets===0,'status reads only aggregated own values without contacts');
    checkUmk(AnyTourUmkOperator::execute(['verify-goal'],$work,$readGoal,$changeMode)['catalog_ready']===true
        && $catalogReads===1 && $sets===0,'catalog-only probe');
    $wrong = AnyTourUmkOperator::execute(['send',(string)($epoch+1)],$work,$readGoal,$changeMode);
    checkUmk(!$wrong['ok'] && $catalogReads===1 && $sets===0,'exact epoch required before network');
    $catalogOk = false;
    checkUmk(!AnyTourUmkOperator::execute(['send',(string)$epoch],$work,$readGoal,$changeMode)['ok']
        && $sets===0 && $catalogReads===2,'catalog failure never enables send');
    $catalogOk = true;
    checkUmk(AnyTourUmkOperator::execute(['send',(string)$epoch],$work,$readGoal,$changeMode)['ok']
        && $sets===1 && $catalogReads===3,'verified goal and epoch allow send; no dummy conversion');
    $catalogOk = false;
    checkUmk(AnyTourUmkOperator::execute(['pause',(string)$epoch],$work,$readGoal,$changeMode)['state']['mode']==='collect'
        && $sets===2 && $catalogReads===3,'pause needs no network');
    $existing = AnyTourUmkOperator::execute(['activate'],$work,$readGoal,$changeMode);
    checkUmk($existing['state']['epoch']===$epoch && $sets===2 && $catalogReads===3,'operator preserves cohort on repeated activate');
}
