<?php

declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerConversationService.php';
require_once __DIR__.'/ManagerQueueProjectionService.php';
require_once __DIR__.'/ManagerLeadInboxService.php';

/** Account-owned presentation preferences. Never writes messages or business state. */
final class ManagerPersonalWorkspaceService
{
    private const MAX_TABS=12;
    private const MAX_LABELS=30;
    private const MAX_MEMBERSHIPS=2000;
    private const MAX_PINS=200;
    private const MAX_BYTES=65536;
    private const ACTIONS=['tab_save','tab_delete','tab_reorder','label_save','label_delete','label_membership','group_create','group_rename','group_delete','group_membership','pin','settings'];

    public static function snapshot(int $actor): array
    {
        $pdo=ConversationDb::connection();
        if (!self::active($pdo,$actor)) return self::error('unauthorized');
        return self::view(self::read($pdo,$actor));
    }

    /** A committed receipt is immutable, but recovery always returns current state. */
    public static function operation(int $actor, string $operationId): array
    {
        $pdo=ConversationDb::connection();
        if (!self::active($pdo,$actor)) return self::error('unauthorized');
        if (!self::validOperationId($operationId)) return self::error('invalid_operation_id');
        // Read receipt before state: state may advance, never regress behind receipt.
        $receipt=self::receipt($pdo,$actor,$operationId);
        $current=self::view(self::read($pdo,$actor));
        $result=$receipt===null?null:array_merge($receipt['result'],['version'=>$current['version'],'state'=>$current['state']]);
        return $current+['found'=>$receipt!==null,'result'=>$result];
    }

    public static function mutate(int $actor, string $action, array $input): array
    {
        $pdo=ConversationDb::connection();
        if (!self::active($pdo,$actor)) return self::error('unauthorized');
        if (!in_array($action,self::ACTIONS,true)) return self::error('unknown_action');
        $operationId=$input['operation_id']??null;
        if (!is_string($operationId)||!self::validOperationId($operationId)) return self::error('invalid_operation_id');
        if (!isset($input['expected_version'])||!is_int($input['expected_version'])||$input['expected_version']<0) return self::error('invalid_version');
        $fields=['expected_version','id','tab','ids','name','conversation_id','label_id','enabled','settings'];
        $command=['action'=>$action];
        foreach ($fields as $field) if (array_key_exists($field,$input)) $command[$field]=$input[$field];
        $hash=hash('sha256',self::json(self::canonical($command)));
        if ($pdo->inTransaction()) throw new LogicException('Personal workspace requires its own transaction');
        for ($attempt=0;$attempt<3;$attempt++) {
            $old=self::receipt($pdo,$actor,$operationId);
            if ($old!==null) return self::replay($pdo,$actor,$old,$hash);
            $pdo->beginTransaction();
            try {
                if (!self::active($pdo,$actor,true)) {$pdo->rollBack();return self::error('unauthorized');}
                $q=$pdo->prepare('INSERT INTO manager_personal_workspace_operations (manager_id,operation_id,request_hash,created_at) VALUES (?,?,?,?)');
                $q->execute([$actor,$operationId,$hash,gmdate('Y-m-d H:i:s')]);
                $record=self::read($pdo,$actor,true);
                if ($record['version']!==$input['expected_version']) $result=self::error('conflict');
                else {
                    $state=$record['state'];
                    $result=self::apply($actor,$action,$input,$state);
                    if (!empty($result['ok'])) {
                        $encoded=self::json($state);
                        if (strlen($encoded)>self::MAX_BYTES) $result=self::error('state_limit_exceeded');
                        elseif ($state!==$record['state']) {
                            $q=$pdo->prepare('UPDATE manager_personal_workspace_states SET state_json=?,revision=revision+1,updated_at=? WHERE manager_id=? AND revision=?');
                            $q->execute([$encoded,gmdate('Y-m-d H:i:s'),$actor,$record['version']]);
                            if ($q->rowCount()!==1) throw new RuntimeException('personal_workspace_revision_failure');
                            $record=['version'=>$record['version']+1,'state'=>$state];
                        }
                    }
                }
                // Store only outcome/allocated ID, not obsolete snapshots in receipts.
                $q=$pdo->prepare('UPDATE manager_personal_workspace_operations SET result_json=? WHERE manager_id=? AND operation_id=?');
                $q->execute([self::json($result),$actor,$operationId]);
                $response=array_merge($result,['version'=>$record['version'],'state'=>self::publicState($record['state'])]);
                $pdo->commit();
                return $response;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e instanceof PDOException&&in_array((string)$e->getCode(),['23000','23505'],true)) {
                    $old=self::receipt($pdo,$actor,$operationId);
                    if ($old!==null) return self::replay($pdo,$actor,$old,$hash);
                }
                if ($attempt<2&&$e instanceof PDOException&&((string)$e->getCode()==='40001'||in_array((int)($e->errorInfo[1]??0),[5,6,1213],true))) {usleep(10000*($attempt+1));continue;}
                throw $e;
            }
        }
        throw new LogicException('Unreachable personal workspace retry');
    }

    /** Load just the selected collection through the canonical bounded/access path. */
    public static function list(int $actor, array $input): array
    {
        $snapshot=self::snapshot($actor);
        if (empty($snapshot['ok'])) return $snapshot;
        $state=$snapshot['state'];$selection=$input['selection']??'';
        if (!is_string($selection)) return self::error('invalid_selection');
        $queue='all';$queues=['all'];$filter=[];
        if ($selection==='pinned') $filter['ids']=$state['pins'];
        elseif (preg_match('/^tab:([1-9][0-9]*)$/D',$selection,$m)) {
            $tab=self::find($state['tabs'],(int)$m[1]);
            if ($tab===null) return self::error('not_found')+['version'=>$snapshot['version'],'state'=>$state];
            $conditions=self::tabConditions($tab);
            if ($conditions===null) return self::error('invalid_tab');
            $queues=$conditions['queues'];$queue=count($queues)===1?$queues[0]:'all';
            $filter=['queues'=>$queues,'channels'=>$conditions['channels'],'source_ids'=>$conditions['source_ids'],'reply_needed'=>$tab['reply_needed']];
            if ($conditions['label_ids']) {
                $ids=[];
                foreach ($conditions['label_ids'] as $labelId) array_push($ids,...(((array)$state['members'])[(string)$labelId]??[]));
                // A removed label contributes an empty branch, never "any".
                $filter['ids']=array_values(array_unique($ids,SORT_REGULAR));
            }
            if ($tab['pinned_only']) $filter['ids']=isset($filter['ids'])?array_values(array_intersect($filter['ids'],$state['pins'])):$state['pins'];
        } else return self::error('invalid_selection');
        foreach (['project_key','manager_filter','channel','search'] as $field) if (isset($input[$field])&&!is_string($input[$field])) return self::error('invalid_'.$field);
        if (isset($input['source_id'])&&(!is_int($input['source_id'])||$input['source_id']< -1)) return self::error('invalid_source_id');
        $search=trim($input['search']??'');if (self::length($search)>120) return self::error('invalid_search');
        // External filters intersect the stored tab, never silently replace it.
        $channel=$input['channel']??'';
        if (!in_array($channel,['','max','telegram','website'],true)) return self::error('invalid_channel');
        if ($channel!=='') {
            $filter['channel']=$channel;
        }
        $source=$input['source_id']??0;
        if ($source!==0) {
            $filter['source_id']=$source;
        }
        $page=[];
        $rows=ManagerConversationService::list($actor,$queue,200,$input['project_key']??'*',$input['manager_filter']??'','',0,$filter,$page);
        if (count($queues)===1) $rows=ManagerQueueProjectionService::actionableRows($queue,$rows);
        elseif (in_array('waiting',$queues,true)&&!in_array('all',$queues,true)) {
            // A suspended row excluded from Waiting may still belong to Mine
            // or Closed. Project the union without issuing another list read.
            $rows=array_values(array_filter($rows,static fn(array $row):bool=>
                ($row['delivery_failure_category']??'')!=='suspended'
                ||(in_array('mine',$queues,true)&&$row['status']==='manager'&&(int)$row['manager_id']===$actor)
                ||(in_array('closed',$queues,true)&&$row['status']==='closed')));
        }
        $rows=ManagerLeadInboxService::decorate($rows);
        $rows=ManagerLeadInboxService::filter($rows,'',$search,'');
        if ($queue==='mine') $rows=ManagerLeadInboxService::sortMine($rows);
        $manager=ManagerAuthService::byId($actor);
        if ($manager===null) return self::error('unauthorized');
        foreach ($rows as &$row) $row['can_edit_pipeline']=ManagerConversationAccessPolicy::canEditVisibleConversation($row,$manager);
        unset($row);
        return $snapshot+['conversations'=>$rows,'bounded_count'=>count($rows),'has_more'=>!empty($page['limit_reached']),'limited'=>!empty($page['limit_reached']),'count_scope'=>'loaded_visible','limit'=>200];
    }

    private static function apply(int $actor, string $action, array $input, array &$state): array
    {
        if ($action==='settings') {
            $settings=$input['settings']??null;
            if (!is_array($settings)||array_diff(array_keys($settings),['start_tab','density','font','ai_ready_notice'])) return self::error('invalid_settings');
            foreach ($settings as $key=>$value) if (!is_string($value)) return self::error('invalid_settings');
            $settings=array_merge($state['settings'],$settings);
            if (!in_array($settings['density'],['comfortable','compact'],true)||!in_array($settings['font'],['normal','large'],true)||!in_array($settings['ai_ready_notice'],['show','hide'],true)||!self::validStart($settings['start_tab'],$state)) return self::error('invalid_settings');
            $state['settings']=$settings;return ['ok'=>true];
        }
        if ($action==='tab_reorder') {
            $ids=$input['ids']??null;
            if (!self::idList($ids,self::MAX_TABS)) return self::error('invalid_ids');
            $current=array_column($state['tabs'],'id');$a=$ids;$b=$current;sort($a);sort($b);
            if ($a!==$b) return self::error('conflict');
            $tabs=[];foreach ($ids as $id) $tabs[]=self::find($state['tabs'],$id);
            $state['tabs']=$tabs;return ['ok'=>true];
        }
        if (in_array($action,['group_create','group_rename','group_delete'],true)) return self::applyGroup($action,$input,$state);
        if (in_array($action,['pin','label_membership','group_membership'],true)) {
            $conversationId=$input['conversation_id']??null;$enabled=$input['enabled']??null;
            if (!is_int($conversationId)||$conversationId<=0||!is_bool($enabled)) return self::error('invalid_membership');
            // Fresh singleton permission on every write, including removal/no-op.
            if (ManagerConversationService::visibleConversation($conversationId,$actor)===null) return self::error('forbidden');
            if ($action==='pin') {
                $ids=$state['pins'];
                if ($enabled&&!in_array($conversationId,$ids,true)) {
                    if (count($ids)>=self::MAX_PINS) return self::error('pin_limit_exceeded');
                    $ids[]=$conversationId;
                } elseif (!$enabled) $ids=array_values(array_filter($ids,static fn(int $id):bool=>$id!==$conversationId));
                $state['pins']=$ids;return ['ok'=>true];
            }
            $label=$input['label_id']??null;
            if ($action==='group_membership') {
                $id=$input['id']??null;
                if (!is_int($id)||$id<=0) return self::error('invalid_id');
                $group=self::find($state['tabs'],$id);
                if ($group===null||($group['kind']??'')!=='group') return self::error('not_found');
                $label=$group['group_label_id']??null;
            }
            if (!is_int($label)||self::find($state['labels'],$label)===null) return self::error('not_found');
            $ids=$state['members'][(string)$label]??[];
            if ($enabled&&!in_array($conversationId,$ids,true)) {
                if (array_sum(array_map('count',$state['members']))>=self::MAX_MEMBERSHIPS) return self::error('membership_limit_exceeded');
                $ids[]=$conversationId;
            } elseif (!$enabled) $ids=array_values(array_filter($ids,static fn(int $id):bool=>$id!==$conversationId));
            if ($ids) $state['members'][(string)$label]=$ids;else unset($state['members'][(string)$label]);
            return ['ok'=>true];
        }
        $id=$input['id']??0;
        if (!is_int($id)||$id<0) return self::error('invalid_id');
        $tabs=str_starts_with($action,'tab_');$key=$tabs?'tabs':'labels';$row=$id>0?self::find($state[$key],$id):null;
        if ($id>0&&$row===null) return self::error('not_found');
        // Bound group rows may only change together through their atomic owner.
        if ($row!==null&&($tabs?($row['kind']??'')==='group':self::groupForLabel($state,$id)!==null)) return self::error('group_action_required');
        if (str_ends_with($action,'_delete')) {
            if ($id===0) return self::error('not_found');
            $state[$key]=array_values(array_filter($state[$key],static fn(array $item):bool=>$item['id']!==$id));
            if ($tabs&&$state['settings']['start_tab']==='tab:'.$id) $state['settings']['start_tab']='waiting';
            if (!$tabs) {
                unset($state['members'][(string)$id]);
                // Retain a deleted filter ID as an empty selection. Clearing it
                // would silently broaden a saved collection to all customers.
            }
            return ['ok'=>true,'deleted_id'=>$id];
        }
        if ($tabs&&!is_array($input['tab']??null)) return self::error('invalid_tab');
        $name=$tabs?($input['tab']['name']??null):($input['name']??null);
        if (!is_string($name)||trim($name)===''||self::length(trim($name))>64||preg_match('/[\x00-\x1f\x7f]/u',$name)) return self::error('invalid_name');
        $name=trim($name);
        foreach ($state[$key] as $existing) if ($existing['id']!==$id&&$existing['name']===$name) return self::error('duplicate_name');
        if ($tabs) {
            $tab=$input['tab']??null;
            if (!is_array($tab)||array_diff(array_keys($tab),['name','queue','channel','source_id','reply_needed','label_id','pinned_only','queues','channels','source_ids','label_ids'])) return self::error('invalid_tab');
            $tab=array_merge(['queue'=>'all','channel'=>'','source_id'=>0,'reply_needed'=>false,'label_id'=>0,'pinned_only'=>false],$tab);
            if (!in_array($tab['queue'],['waiting','mine','all','closed'],true)||!in_array($tab['channel'],['','max','telegram','website'],true)
                ||!is_int($tab['source_id'])||$tab['source_id']<0||!is_int($tab['label_id'])||$tab['label_id']<0||!is_bool($tab['reply_needed'])||!is_bool($tab['pinned_only'])) return self::error('invalid_tab');
            $conditions=self::tabConditions($tab);
            if ($conditions===null) return self::error('invalid_tab');
            foreach ($conditions['label_ids'] as $labelId) if (self::find($state['labels'],$labelId)===null) return self::error('not_found');
            if (!self::visibleSources($actor,$conditions['source_ids'])) return self::error('forbidden');
            $value=['name'=>$name,'queue'=>$tab['queue'],'channel'=>$tab['channel'],'source_id'=>$tab['source_id'],'reply_needed'=>$tab['reply_needed'],'label_id'=>$tab['label_id'],'pinned_only'=>$tab['pinned_only']];
            foreach (['queues'=>'queue','channels'=>'channel','source_ids'=>'source_id','label_ids'=>'label_id'] as $plural=>$single) {
                if (!array_key_exists($plural,$tab)) continue;
                $value[$plural]=$conditions[$plural];
                // Keep singular readers useful for a single condition. The
                // plural is authoritative whenever present, including [].
                $value[$single]=count($conditions[$plural])===1?$conditions[$plural][0]:($single==='queue'?'all':($single==='channel'?'':0));
            }
        } else $value=['name'=>$name];
        if ($id===0) {
            if (count($state[$key])>=($tabs?self::MAX_TABS:self::MAX_LABELS)) return self::error($tabs?'tab_limit_exceeded':'label_limit_exceeded');
            $counter=$tabs?'next_tab_id':'next_label_id';$id=$state[$counter]++;
            $state[$key][]=array_merge(['id'=>$id],$value);
        } else foreach ($state[$key] as &$item) if ($item['id']===$id) $item=array_merge(['id'=>$id],$value);
        unset($item);
        return ['ok'=>true,'id'=>$id];
    }

    /** One command/receipt owns both the visible group and its private marker. */
    private static function applyGroup(string $action, array $input, array &$state): array
    {
        $id=$input['id']??0;
        if (!is_int($id)||$id<0) return self::error('invalid_id');
        $group=$id>0?self::find($state['tabs'],$id):null;
        if ($action!=='group_create'&&($group===null||($group['kind']??'')!=='group')) return self::error('not_found');
        if ($action==='group_create'&&$id!==0) return self::error('invalid_id');
        $labelId=$group['group_label_id']??0;
        if ($action==='group_delete') {
            $state['tabs']=array_values(array_filter($state['tabs'],static fn(array $item):bool=>$item['id']!==$id));
            $state['labels']=array_values(array_filter($state['labels'],static fn(array $item):bool=>$item['id']!==$labelId));
            unset($state['members'][(string)$labelId]);
            if ($state['settings']['start_tab']==='tab:'.$id) $state['settings']['start_tab']='waiting';
            // Other saved selections retain the deleted label as an empty
            // branch, exactly like ordinary label tombstones.
            return ['ok'=>true,'deleted_id'=>$id,'label_id'=>$labelId];
        }
        $name=$input['name']??null;
        if (!is_string($name)||trim($name)===''||self::length(trim($name))>64||preg_match('/[\x00-\x1f\x7f]/u',$name)) return self::error('invalid_name');
        $name=trim($name);
        foreach ($state['tabs'] as $tab) if ($tab['id']!==$id&&$tab['name']===$name) return self::error('duplicate_name');
        foreach ($state['labels'] as $label) if ($label['id']!==$labelId&&$label['name']===$name) return self::error('duplicate_name');
        if ($action==='group_create') {
            if (count($state['tabs'])>=self::MAX_TABS) return self::error('tab_limit_exceeded');
            if (count($state['labels'])>=self::MAX_LABELS) return self::error('label_limit_exceeded');
            $id=$state['next_tab_id']++;$labelId=$state['next_label_id']++;
            $state['tabs'][]=['id'=>$id,'kind'=>'group','group_label_id'=>$labelId,'name'=>$name,'queue'=>'all','queues'=>['all','closed'],'channel'=>'','source_id'=>0,'reply_needed'=>false,'label_id'=>$labelId,'label_ids'=>[$labelId],'pinned_only'=>false];
            $state['labels'][]=['id'=>$labelId,'name'=>$name];
        } else {
            if (!is_int($labelId)||self::find($state['labels'],$labelId)===null) return self::error('not_found');
            foreach ($state['tabs'] as &$tab) if ($tab['id']===$id) $tab['name']=$name;
            unset($tab);
            foreach ($state['labels'] as &$label) if ($label['id']===$labelId) $label['name']=$name;
            unset($label);
        }
        return ['ok'=>true,'id'=>$id,'label_id'=>$labelId];
    }

    private static function groupForLabel(array $state, int $labelId): ?array
    {
        foreach ($state['tabs'] as $tab) if (($tab['kind']??'')==='group'&&($tab['group_label_id']??0)===$labelId) return $tab;
        return null;
    }

    private static function defaults(): array
    {
        return ['tabs'=>[],'labels'=>[],'members'=>[],'pins'=>[],'settings'=>['start_tab'=>'waiting','density'=>'comfortable','font'=>'normal','ai_ready_notice'=>'show'],'next_tab_id'=>1,'next_label_id'=>1];
    }

    private static function read(PDO $pdo, int $actor, bool $lock=false): array
    {
        if ($lock) {
            $insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
            $q=$pdo->prepare($insert.' INTO manager_personal_workspace_states (manager_id,revision,state_json,updated_at) VALUES (?,0,?,?)');
            $q->execute([$actor,self::json(self::defaults()),gmdate('Y-m-d H:i:s')]);
        }
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT revision,state_json FROM manager_personal_workspace_states WHERE manager_id=?'.$suffix);$q->execute([$actor]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['version'=>0,'state'=>self::defaults()];
        $state=json_decode((string)$row['state_json'],true,32,JSON_THROW_ON_ERROR);
        if (!is_array($state)||!isset($state['tabs'],$state['labels'],$state['members'],$state['pins'],$state['settings'],$state['next_tab_id'],$state['next_label_id'])) throw new RuntimeException('invalid_personal_workspace_storage');
        // Older account rows gain the presentation default without a write or migration.
        $state['settings']+=['ai_ready_notice'=>'show'];
        return ['version'=>(int)$row['revision'],'state'=>$state];
    }

    private static function active(PDO $pdo, int $actor, bool $lock=false): bool
    {
        if ($actor<=0) return false;
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT is_active FROM managers WHERE id=?'.$suffix);$q->execute([$actor]);
        return (int)$q->fetchColumn()===1;
    }

    private static function visibleSources(int $actor, array $sources): bool
    {
        if (!$sources) return true;
        $q=ConversationDb::connection()->prepare('SELECT DISTINCT s.id FROM conversation_sources s JOIN projects p ON p.id=s.project_id JOIN manager_projects mp ON mp.project_id=p.id WHERE mp.manager_id=? AND s.id IN ('.implode(',',array_fill(0,count($sources),'?')).') AND s.is_active=1 AND p.is_active=1');
        $q->execute(array_merge([$actor],$sources));
        return count($q->fetchAll(PDO::FETCH_COLUMN))===count($sources);
    }

    /** New arrays override legacy scalar selectors; old snapshots stay intact. */
    private static function tabConditions(array $tab): ?array
    {
        $out=[];
        foreach (['queues'=>['queue','all'],'channels'=>['channel',''],'source_ids'=>['source_id',0],'label_ids'=>['label_id',0]] as $plural=>[$single,$default]) {
            $legacy=$tab[$single]??$default;
            $values=array_key_exists($plural,$tab)?$tab[$plural]:($legacy===$default&&$plural!=='queues'?[]:[$legacy]);
            $max=['queues'=>4,'channels'=>3,'source_ids'=>100,'label_ids'=>self::MAX_LABELS][$plural];
            if (!is_array($values)||!array_is_list($values)||count($values)>$max||count(array_unique($values,SORT_REGULAR))!==count($values)||($plural==='queues'&&!$values)) return null;
            foreach ($values as $value) {
                if ($plural==='queues'&&(!is_string($value)||!in_array($value,['waiting','mine','all','closed'],true))) return null;
                if ($plural==='channels'&&(!is_string($value)||!in_array($value,['max','telegram','website'],true))) return null;
                if (in_array($plural,['source_ids','label_ids'],true)&&(!is_int($value)||$value<=0)) return null;
            }
            $out[$plural]=$values;
        }
        return $out;
    }

    private static function receipt(PDO $pdo, int $actor, string $id): ?array
    {
        $q=$pdo->prepare('SELECT request_hash,result_json FROM manager_personal_workspace_operations WHERE manager_id=? AND operation_id=?');$q->execute([$actor,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        return !$row||$row['result_json']===null?null:['hash'=>$row['request_hash'],'result'=>json_decode((string)$row['result_json'],true,32,JSON_THROW_ON_ERROR)];
    }

    private static function replay(PDO $pdo, int $actor, array $receipt, string $hash): array
    {
        $current=self::view(self::read($pdo,$actor));
        return array_merge(hash_equals($receipt['hash'],$hash)?$receipt['result']:self::error('operation_mismatch'),['version'=>$current['version'],'state'=>$current['state']]);
    }

    private static function publicState(array $state): array {return ['tabs'=>$state['tabs'],'labels'=>$state['labels'],'members'=>(object)$state['members'],'pins'=>$state['pins'],'settings'=>$state['settings']];}
    private static function view(array $record): array {return ['ok'=>true,'version'=>$record['version'],'state'=>self::publicState($record['state'])];}
    private static function error(string $error): array {return ['ok'=>false,'error'=>$error];}
    private static function find(array $items, int $id): ?array {foreach ($items as $item) if ($item['id']===$id) return $item;return null;}
    private static function validStart(string $value, array $state): bool {return in_array($value,['waiting','mine','all','closed','pinned'],true)||(preg_match('/^tab:([1-9][0-9]*)$/D',$value,$m)&&self::find($state['tabs'],(int)$m[1])!==null);}
    private static function validOperationId(string $id): bool {return preg_match('/^[A-Za-z0-9_-]{16,96}$/D',$id)===1;}
    private static function length(string $value): int {return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):preg_match_all('/./us',$value);}
    private static function idList($ids, int $max): bool {if (!is_array($ids)||!array_is_list($ids)||count($ids)>$max||count(array_unique($ids,SORT_REGULAR))!==count($ids)) return false;foreach ($ids as $id) if (!is_int($id)||$id<=0) return false;return true;}
    private static function json($value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function canonical($value) {if (!is_array($value)) return $value;if (!array_is_list($value)) ksort($value,SORT_STRING);foreach ($value as &$item) $item=self::canonical($item);unset($item);return $value;}
}
