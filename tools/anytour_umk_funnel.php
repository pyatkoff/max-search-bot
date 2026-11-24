<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__).'/services/AnyTourUmkLateLedger.php';

/**
 * Run under the existing application operator, from its verified installed release.
 * php tools/anytour_umk_funnel.php status <installed-sha>
 * php tools/anytour_umk_funnel.php verify-goal <installed-sha>
 * php tools/anytour_umk_funnel.php activate <installed-sha>
 * php tools/anytour_umk_funnel.php send|pause <installed-sha> <observed-epoch>
 *
 * Verify both producer installations and the early issuer's actual enabled setting
 * before opening the prospective cohort. Activation starts collection. SEND verifies
 * the Metrika definition and changes only the UMK mode; the regular worker owns delivery.
 */
final class AnyTourUmkOperator
{
    private const LIMIT = 2000;
    private const STATES = ['reserved','attempted','accepted','processed','unknown','failed','not_needed'];

    /** Only own destination state; counts of all early launches belong to the tracker and Metrika. */
    public static function status(PDO $db): array
    {
        $db->beginTransaction();
        try {
            $setting = $db->query('SELECT delivery_enabled,activated_at_unix FROM anytour_umk_late_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            if (!$setting || !in_array((string)$setting['delivery_enabled'], ['0','1'], true)) throw new RuntimeException('Invalid state');
            $limit = self::LIMIT+1;
            $accounts = $db->query('SELECT early_kopeks FROM anytour_umk_late_accounts ORDER BY source_account_id DESC LIMIT '.$limit)->fetchAll(PDO::FETCH_COLUMN);
            $events = $db->query('SELECT delivery_state,delta_kopeks FROM anytour_umk_late_events ORDER BY source_event_id DESC LIMIT '.$limit)->fetchAll(PDO::FETCH_ASSOC);
            $byState = array_fill_keys(self::STATES,['count'=>0,'delta_kopeks'=>0]);
            foreach (array_slice($events,0,self::LIMIT) as $event) {
                $state = $event['delivery_state'];
                if (!isset($byState[$state])) throw new RuntimeException('Unexpected UMK state');
                $byState[$state]['count']++;
                $byState[$state]['delta_kopeks'] += (int)$event['delta_kopeks'];
            }
            $early = array_slice($accounts,0,self::LIMIT);
            $db->commit();
            return [
                'mode'=>$setting['activated_at_unix']===null?'off':((int)$setting['delivery_enabled']===1?'send':'collect'),
                'epoch'=>$setting['activated_at_unix']===null?null:(int)$setting['activated_at_unix'],
                'latest_limit_per_section'=>self::LIMIT,
                'early_reservations_on_evaluated_inquiries'=>[
                    'coverage_complete'=>count($accounts)<=self::LIMIT,'examined'=>count($early),
                    'positive_count'=>count(array_filter($early,static fn($item):bool=>(int)$item>0)),
                    'sum_kopeks'=>(int)array_sum($early)],
                'late_profiles'=>['coverage_complete'=>count($events)<=self::LIMIT,'examined'=>min(count($events),self::LIMIT),'by_state'=>$byState],
                'all_early_launch_count'=>null
            ];
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    /** Callers inject only private connection-scoped operations; this also exercises the production dispatch in fixtures. */
    public static function execute(array $arguments, PDO $db, callable $catalogReady, callable $setDelivery): array
    {
        $op = $arguments[0] ?? null;
        if (in_array($op,['status','verify-goal','activate'],true) && count($arguments)===1) {
            if ($op==='status') return ['ok'=>true,'state'=>self::status($db)];
            if ($op==='verify-goal') {
                $ready = $catalogReady() === true;
                return ['ok'=>$ready,'operation'=>$op,'catalog_ready'=>$ready];
            }
            (new AnyTourUmkLateLedger($db))->activate();
            return ['ok'=>true,'state'=>self::status($db)];
        }
        if (!in_array($op,['send','pause'],true) || count($arguments)!==2 || !is_string($arguments[1]) ||
            !preg_match('/\A[1-9][0-9]{0,11}\z/D',$arguments[1])) throw new InvalidArgumentException('Invalid UMK operation');
        $epoch = (int)$arguments[1];
        $state = $db->query('SELECT activated_at_unix FROM anytour_umk_late_settings WHERE id=1')->fetchColumn();
        if ($state===false || (string)$state!==$arguments[1]) return ['ok'=>false,'reason'=>'activation_epoch_mismatch'];
        if ($op==='send' && $catalogReady()!==true) return ['ok'=>false,'reason'=>'goal_definition_unconfirmed'];
        // The same epoch is checked again under lock by the runtime's state setter.
        $setDelivery($epoch, $op==='send');
        $after = self::status($db);
        return ['ok'=>$after['epoch']===$epoch && $after['mode']===($op==='send'?'send':'collect'),'state'=>$after];
    }

    public static function main(array $argv): int
    {
        ini_set('display_errors','0');
        $buffer = false;
        try {
            $root = dirname(__DIR__);
            $applicationSha = $argv[2] ?? null;
            if (!is_string($applicationSha) || !preg_match('/\A[a-f0-9]{40}\z/D',$applicationSha) || count($argv)>4) {
                throw new InvalidArgumentException('Expected operation, installed application SHA, and epoch only for send/pause');
            }
            require_once __DIR__.'/max2_common_value_state_v2.php';
            if (Max2CommonValueStateCliV2::installedHead($root)!==$applicationSha) throw new RuntimeException('Installed version changed');
            ob_start();
            $buffer = true;
            require_once $root.'/config.php';
            require_once $root.'/services/ProjectAccessService.php';
            require_once $root.'/services/Max2CommonValueMetrikaClientV2.php';
            if (ProjectConfig::projectId()!=='anytour' || !defined('METRIKA_COUNTER_ID') || (string)METRIKA_COUNTER_ID!==AnyTourUmkLateLedger::COUNTER) {
                throw new RuntimeException('Wrong project or counter');
            }
            ProjectAccessService::initializeReadOnly();
            $db = ConversationDb::connection();
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('Application database required');
            $bootstrap = ob_get_clean();
            $buffer = false;
            if ($bootstrap!=='' || Max2CommonValueStateCliV2::installedHead($root)!==$applicationSha) throw new RuntimeException('Bootstrap mismatch');
            $factory = static function(): Max2CommonValueMetrikaClientV2 {
                if (!defined('METRIKA_OAUTH_TOKEN') || !is_string(METRIKA_OAUTH_TOKEN) || METRIKA_OAUTH_TOKEN==='') {
                    throw new RuntimeException('Catalog unavailable');
                }
                return Max2CommonValueMetrikaClientV2::forUmk(METRIKA_OAUTH_TOKEN);
            };
            $change = static function(int $epoch,bool $enabled) use ($db,$factory): void {
                require_once dirname(__DIR__).'/services/AnyTourUmkLateDelivery.php';
                $noLookup = static function(): never { throw new LogicException('State changes never read tracker data'); };
                (new AnyTourUmkLateDelivery($db,$noLookup,$factory))->setDelivery($epoch,$enabled);
            };
            $args = [$argv[1]];
            if (array_key_exists(3,$argv)) $args[] = $argv[3];
            $result=self::execute($args,$db,static fn(): bool=>$factory()->goalReady(),$change);
            if (Max2CommonValueStateCliV2::installedHead($root)!==$applicationSha) throw new RuntimeException('Installed version changed');
            echo json_encode(['counter_id'=>AnyTourUmkLateLedger::COUNTER,'goal_id'=>AnyTourUmkLateLedger::GOAL,'target'=>AnyTourUmkLateLedger::TARGET]+$result,JSON_THROW_ON_ERROR)."\n";
            return $result['ok']?0:2;
        } catch (Throwable $error) {
            if ($buffer) ob_end_clean();
            // No connection details, customer information, server paths or credentials in output.
            echo "{\"ok\":false,\"reason\":\"operation_unconfirmed\"}\n";
            return 2;
        }
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===realpath(__FILE__)) exit(AnyTourUmkOperator::main($argv));
