<?php
declare(strict_types=1);

/** INSERT-only execution metadata. This does not verify HOST or fence Library.
 * The actual cooperating worker must consume its literal token/fresh instance
 * before work. Its native run identity is independently attested only later.
 */
trait HotelWorkExecutionAttempts
{
    public function execution_attempt_preflight(int $admin,string $connection,array $args): array
    {
        [$id,$stage]=$this->executionIdentity($admin,$connection,$args);
        return $this->transaction(function() use($admin,$connection,$args,$id,$stage): array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);
            return $this->executionPreflight($row,$lane,$args,$stage);
        });
    }

    public function prepare_execution_attempt(int $admin,string $connection,array $args): array
    {
        $this->executionPrepareArgs($args,true);
        [$id,$stage]=$this->executionIdentity($admin,$connection,$args,false);
        $claim=hash('sha256',$args['claim_key']);$requestArgs=$args;unset($requestArgs['claim_key']);
        $request=$this->executionRequestHash('permit',$admin,$connection,$requestArgs,$claim);
        return $this->transaction(function() use($admin,$connection,$args,$id,$stage,$claim,$requestArgs,$request): array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);
            $record=$this->executionAttemptRecord($row,$stage);
            if ($record!==null) {
                if (!hash_equals($record['request_sha256'],$request)) throw new HotelWorkQueueConflict('execution_attempt_conflict');
                return $this->executionPermitResult($row,$record,'already_prepared');
            }
            $reason=$this->executionEligibility($row,$lane,$args,$stage);
            if ($reason!==null) throw new HotelWorkQueueConflict($reason);
            if (!hash_equals((string)$row['claim_sha256'],$claim)) throw new HotelWorkQueueConflict('claim_mismatch');
            $snapshot=$this->executionSnapshot($row,null);
            if (!hash_equals(self::hash($snapshot),$args['expected_state_sha256'])) throw new HotelWorkQueueConflict('execution_attempt_pin_mismatch');
            if ($this->one('SELECT permit_sha256 FROM hotel_work_execution_attempts WHERE attempt_token=?',[$args['attempt_token']]))
                throw new HotelWorkQueueConflict('execution_attempt_token_conflict');
            $now=$this->now();
            $audit=['namespace'=>'hotel_execution_attempt_v1','admin_id'=>$admin,'connection_id'=>$connection,
                'queue_id'=>$id,'stage_id'=>$stage,'stage_index'=>(int)$row['stage_index'],
                'plan_sha256'=>$row['plan_sha256'],'baseline_sha256'=>$row['baseline_sha256'],
                'canonical_run_id'=>$row['current_run_id'],'claim_sha256'=>$claim,
                'request_sha256'=>$request,'request'=>$requestArgs,'original_snapshot'=>$snapshot,'created_at'=>$now];
            $json=self::canonical($audit);if (strlen($json)>12288) throw new InvalidArgumentException('invalid_hotel_work_attempt');
            $permit=self::hash($audit);
            $this->execute('INSERT INTO hotel_work_execution_attempts (permit_sha256,queue_id,stage_id,stage_index,request_sha256,attempt_token,canonical_run_id,claim_sha256,audit_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$permit,$id,$stage,(int)$row['stage_index'],$request,$args['attempt_token'],$row['current_run_id'],$claim,$json,$now]);
            $record=$this->executionAttemptRecord($row,$stage);
            if ($record===null) throw new HotelWorkQueueConflict('execution_attempt_audit_invalid');
            return $this->executionPermitResult($row,$record,'execution_attempt_prepared');
        });
    }

    public function enter_execution_attempt(int $admin,string $connection,array $args): array
    {
        $this->executionEntryArgs($args);$id=$args['queue_id'];$stage=$args['stage_id'];
        self::actor($admin,$connection);
        $request=$this->executionRequestHash('entry',$admin,$connection,$args);
        return $this->transaction(function() use($admin,$connection,$args,$id,$stage,$request): array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);
            $record=$this->executionAttemptRecord($row,$stage);
            if ($record===null) throw new HotelWorkQueueConflict('execution_attempt_not_prepared');
            $this->executionMatchEntry($record,$args);
            if ($record['_entry']!==null) {
                if (!hash_equals($record['_entry']['request_sha256'],$request)) throw new HotelWorkQueueConflict('execution_attempt_instance_conflict');
                return $this->executionEntryResult($row,$record,'already_entered_do_not_replay',false);
            }
            $reason=$this->executionEligibility($row,$lane,$record['_audit']['request'],$stage);
            if ($reason!==null) throw new HotelWorkQueueConflict($reason);
            $this->executionCurrentClaim($row,$record);
            if ($this->one('SELECT entry_sha256 FROM hotel_work_execution_attempt_entries WHERE instance_id=?',[$args['instance_id']]))
                throw new HotelWorkQueueConflict('execution_attempt_instance_conflict');
            $now=$this->now();
            $audit=['namespace'=>'hotel_execution_entry_v1','permit_sha256'=>$record['permit_sha256'],
                'admin_id'=>$admin,'connection_id'=>$connection,'queue_id'=>$id,'stage_id'=>$stage,
                'request_sha256'=>$request,'request'=>$args,'created_at'=>$now];
            $json=self::canonical($audit);$entry=self::hash($audit);
            $this->execute('INSERT INTO hotel_work_execution_attempt_entries (entry_sha256,permit_sha256,queue_id,stage_id,request_sha256,instance_id,audit_json,created_at) VALUES (?,?,?,?,?,?,?,?)',
                [$entry,$record['permit_sha256'],$id,$stage,$request,$args['instance_id'],$json,$now]);
            $record=$this->executionAttemptRecord($row,$stage);
            if ($record===null) throw new HotelWorkQueueConflict('execution_attempt_audit_invalid');
            return $this->executionEntryResult($row,$record,'entered_once',true);
        });
    }

    public function bind_execution_attempt(int $admin,string $connection,array $args): array
    {
        $this->executionBindArgs($args);
        [$id,$stage]=$this->executionIdentity($admin,$connection,$args,false);
        $claim=hash('sha256',$args['claim_key']);$requestArgs=$args;unset($requestArgs['claim_key']);
        $request=$this->executionRequestHash('association',$admin,$connection,$requestArgs,$claim);
        return $this->transaction(function() use($admin,$connection,$args,$id,$stage,$claim,$requestArgs,$request): array {
            $lane=$this->lane(true);$row=$this->owned($id,$admin,$connection,true);
            $record=$this->executionAttemptRecord($row,$stage);
            if ($record===null) throw new HotelWorkQueueConflict('execution_attempt_not_prepared');
            if ($record['_association']!==null) {
                if (!hash_equals($record['_association']['request_sha256'],$request)) throw new HotelWorkQueueConflict('execution_attempt_association_conflict');
                return $this->executionAssociationResult($row,$record,'already_recorded');
            }
            $reason=$this->executionEligibility($row,$lane,$args,$stage);
            if ($reason!==null) throw new HotelWorkQueueConflict($reason);
            $this->executionCurrentClaim($row,$record);
            if (!hash_equals($record['claim_sha256'],$claim)) throw new HotelWorkQueueConflict('claim_mismatch');
            if (!hash_equals(self::hash($this->executionSnapshot($row,$record)),$args['expected_state_sha256']))
                throw new HotelWorkQueueConflict('execution_attempt_pin_mismatch');
            $this->executionMatchBinding($record,$args);
            if ($args['producer_run_id']===$record['canonical_run_id']
                ||$this->one('SELECT association_sha256 FROM hotel_work_execution_attempt_associations WHERE producer_run_id=?',[$args['producer_run_id']])
                ||$this->one('SELECT queue_id FROM hotel_work_queues WHERE current_run_id=?',[$args['producer_run_id']]))
                throw new HotelWorkQueueConflict('producer_run_conflict');
            $now=$this->now();
            $audit=['namespace'=>'hotel_execution_association_v1','permit_sha256'=>$record['permit_sha256'],
                'entry_sha256'=>$record['_entry']['entry_sha256'],'admin_id'=>$admin,'connection_id'=>$connection,
                'queue_id'=>$id,'stage_id'=>$stage,'claim_sha256'=>$claim,
                'request_sha256'=>$request,'request'=>$requestArgs,
                'original_snapshot'=>$this->executionSnapshot($row,$record),'created_at'=>$now];
            $json=self::canonical($audit);$association=self::hash($audit);
            $this->execute('INSERT INTO hotel_work_execution_attempt_associations (association_sha256,permit_sha256,entry_sha256,queue_id,stage_id,request_sha256,producer_run_id,packet_sha256,audit_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$association,$record['permit_sha256'],$record['_entry']['entry_sha256'],$id,$stage,$request,$args['producer_run_id'],$args['packet_sha256'],$json,$now]);
            $record=$this->executionAttemptRecord($row,$stage);
            if ($record===null) throw new HotelWorkQueueConflict('execution_attempt_audit_invalid');
            return $this->executionAssociationResult($row,$record,'producer_association_recorded');
        });
    }

    private function executionIdentity(int $admin,string $connection,array $args,bool $exact=true): array
    {
        $fields=['queue_id','stage_id','baseline_sha256','expected_caller_connection_id','expected_current_run_id'];
        if ($exact) self::executionFields($args,$fields);
        self::actor($admin,$connection);self::executionConnection($args['expected_caller_connection_id']??null);
        if ($connection!==$args['expected_caller_connection_id']) throw new HotelWorkQueueConflict('caller_connection_changed');
        $id=self::queueId($args['queue_id']??null);$stage=self::sha($args['stage_id']??null);
        self::sha($args['baseline_sha256']??null);self::runId($args['expected_current_run_id']??null);
        return [$id,$stage];
    }

    private static function executionFields(array $args,array $fields): void
    {
        self::keys($args,$fields);
        if (count($args)!==count($fields)||array_diff($fields,array_keys($args))) throw new InvalidArgumentException('invalid_hotel_work_fields');
    }

    private static function executionOperation(mixed $value): string
    {
        if (!is_string($value)||preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$value)!==1)
            throw new InvalidArgumentException('invalid_hotel_work_operation');
        return $value;
    }

    private static function executionConnection(mixed $value): string
    {
        if (!is_string($value)||preg_match('/\A[a-f0-9]{32}\z/D',$value)!==1)
            throw new InvalidArgumentException('invalid_hotel_work_connection');
        return $value;
    }

    private function executionPrepareArgs(array $args,bool $claim): void
    {
        $fields=['queue_id','stage_id','baseline_sha256','expected_caller_connection_id','expected_current_run_id',
            'expected_state_sha256','operation_id','attempt_token','scope_sha256','owner_decision_sha256',
            'prompt_readback_sha256','assignment_readback_sha256','fencing_readback_sha256','fencing_status','prior_attempts','dispatch_ceiling'];
        if ($claim) $fields[]='claim_key';self::executionFields($args,$fields);
        self::queueId($args['queue_id']);self::sha($args['stage_id']);self::sha($args['baseline_sha256']);
        self::executionConnection($args['expected_caller_connection_id']);self::runId($args['expected_current_run_id']);
        foreach (['expected_state_sha256','attempt_token','scope_sha256','owner_decision_sha256','prompt_readback_sha256',
            'assignment_readback_sha256','fencing_readback_sha256'] as $field) self::sha($args[$field]);
        if ($claim) self::sha($args['claim_key']);self::executionOperation($args['operation_id']);
        if ($args['dispatch_ceiling']!==3||!in_array($args['fencing_status'],['old_guards_fail_closed','writes_isolated'],true))
            throw new InvalidArgumentException('invalid_hotel_work_attempt');
        $prior=$args['prior_attempts'];
        if (!is_array($prior)||!array_is_list($prior)||count($prior)!==2) throw new InvalidArgumentException('invalid_hotel_work_attempt_history');
        foreach ($prior as $i=>$item) {
            if (!is_array($item)) throw new InvalidArgumentException('invalid_hotel_work_attempt_history');
            self::executionFields($item,['ordinal','status','run_id','receipt_sha256']);
            if ($item['ordinal']!==$i+1||$item['status']!==($i===0?'unknown':'terminal_hold_no_work'))
                throw new InvalidArgumentException('invalid_hotel_work_attempt_history');
            self::sha($item['receipt_sha256']);
            if ($item['run_id']!==null) self::runId($item['run_id']);
        }
        if ($prior[1]['run_id']!==$args['expected_current_run_id']
            ||($prior[0]['run_id']!==null&&$prior[0]['run_id']===$prior[1]['run_id']))
            throw new InvalidArgumentException('invalid_hotel_work_attempt_history');
    }

    private function executionEntryArgs(array $args): void
    {
        self::executionFields($args,['queue_id','stage_id','attempt_token','instance_id','operation_id','scope_sha256',
            'prompt_readback_sha256','assignment_readback_sha256']);
        self::queueId($args['queue_id']);self::sha($args['stage_id']);self::executionOperation($args['operation_id']);
        foreach (['attempt_token','instance_id','scope_sha256','prompt_readback_sha256','assignment_readback_sha256'] as $field) self::sha($args[$field]);
    }

    private function executionBindArgs(array $args,bool $claim=true): void
    {
        $fields=['queue_id','stage_id','baseline_sha256','expected_caller_connection_id','expected_current_run_id',
            'expected_state_sha256','attempt_token','instance_id','entry_sha256','producer_run_id','packet_sha256','native_result_readback_sha256'];
        if ($claim) $fields[]='claim_key';self::executionFields($args,$fields);
        self::queueId($args['queue_id']);self::sha($args['stage_id']);self::sha($args['baseline_sha256']);
        self::executionConnection($args['expected_caller_connection_id']);self::runId($args['expected_current_run_id']);self::runId($args['producer_run_id']);
        foreach (['expected_state_sha256','attempt_token','instance_id','entry_sha256','packet_sha256','native_result_readback_sha256'] as $field) self::sha($args[$field]);
        if ($claim) self::sha($args['claim_key']);
    }

    private function executionRequestHash(string $kind,int $admin,string $connection,array $args,?string $claim=null): string
    {
        return self::hash(['namespace'=>'hotel_execution_'.$kind.'_v1','admin_id'=>$admin,'connection_id'=>$connection,
            'claim_sha256'=>$claim,'arguments'=>$args]);
    }

    private function executionMatchEntry(array $record,array $args): void
    {
        $request=$record['_audit']['request'];
        foreach (['queue_id','stage_id','attempt_token','operation_id','scope_sha256','prompt_readback_sha256','assignment_readback_sha256'] as $field)
            if ($args[$field]!==$request[$field]) throw new HotelWorkQueueConflict('execution_attempt_assignment_mismatch');
    }

    private function executionMatchBinding(array $record,array $args): void
    {
        if ($record['_entry']===null) throw new HotelWorkQueueConflict('execution_attempt_not_entered');
        if ($args['attempt_token']!==$record['attempt_token']||$args['instance_id']!==$record['_entry']['instance_id']
            ||$args['entry_sha256']!==$record['_entry']['entry_sha256'])
            throw new HotelWorkQueueConflict('execution_attempt_association_mismatch');
    }

    private function executionCurrentClaim(array $row,array $record): void
    {
        if ($row['current_run_id']!==$record['canonical_run_id']||$row['claim_sha256']!==$record['claim_sha256'])
            throw new HotelWorkQueueConflict('execution_attempt_claim_changed');
    }

    private function executionEligibility(array $row,array $lane,array $args,string $stage): ?string
    {
        try {$this->executionValidatePlan($row);} catch (Throwable $invalid) {return 'execution_attempt_plan_invalid';}
        if ($stage!==$this->stageId($row)) return 'stale_stage';
        if ($row['baseline_sha256']!==$args['baseline_sha256']) return 'baseline_mismatch';
        if ($row['current_run_id']!==$args['expected_current_run_id']) return 'existing_run_proof_mismatch';
        if ($lane['queue_id']!==$row['queue_id']) return 'hotel_lane_not_owned';
        if ((int)$row['stage_index']%2!==0) return 'research_stage_required';
        if ($row['state']!=='claimed'||$row['claim_sha256']===null||$row['current_run_id']===null) return 'execution_attempt_claim_required';
        if ($this->one('SELECT stage_id FROM hotel_work_receipts WHERE queue_id=? AND stage_id=?',[$row['queue_id'],$stage])) return 'stage_receipt_exists';
        if ($this->one('SELECT stage_id FROM hotel_work_resolutions WHERE queue_id=? AND stage_id=?',[$row['queue_id'],$stage])) return 'stage_resolution_exists';
        if ($this->one('SELECT stage_id FROM hotel_work_research_skips WHERE queue_id=? AND stage_id=?',[$row['queue_id'],$stage])) return 'research_batch_skipped';
        if ($this->one('SELECT queue_id FROM hotel_work_queues WHERE project_key=? AND queue_id<>? AND (state IN (?,?) OR claim_sha256 IS NOT NULL OR current_run_id IS NOT NULL) LIMIT 1',
            [self::PROJECT,$row['queue_id'],'claimed','unknown'])) return 'hotel_lane_other_work';
        return null;
    }

    private function executionPreflight(array $row,array $lane,array $args,string $stage): array
    {
        $out=['ok'=>true,'queue_id'=>$row['queue_id'],'stage_id'=>$stage,'baseline_sha256'=>$args['baseline_sha256'],
            'expected_caller_connection_id'=>$row['oauth_connection_id'],'expected_current_run_id'=>$args['expected_current_run_id'],
            'eligible_to_prepare'=>false,'eligible_to_bind'=>false,'reason'=>null,'state_sha256'=>null,
            'execution_attempt'=>null,'operation_dispatches_work'=>false,'work_authorized'=>false,
            'semantics'=>'authenticated_metadata_not_host_truth_or_worker_admission'];
        try {$record=$this->executionAttemptRecord($row,$stage);}
        catch (HotelWorkQueueConflict $invalid) {
            if ($invalid->reason!=='execution_attempt_audit_invalid') throw $invalid;
            $out['reason']=$invalid->reason;return $out;
        }
        $reason=$this->executionEligibility($row,$lane,$args,$stage);
        $out['execution_attempt']=$record===null?null:$this->executionMetadata($record);
        if ($reason==='execution_attempt_plan_invalid') {$out['reason']=$reason;return $out;}
        $out['state_sha256']=self::hash($this->executionSnapshot($row,$record));
        if ($reason!==null) {$out['reason']=$reason;return $out;}
        if ($record===null) $out['eligible_to_prepare']=true;
        elseif ($record['_association']!==null) $out['reason']='execution_attempt_already_bound';
        elseif ($record['_entry']===null) $out['reason']='execution_attempt_not_entered';
        else {$out['eligible_to_bind']=true;$this->executionCurrentClaim($row,$record);}
        if ($record!==null&&$out['reason']===null&&!$out['eligible_to_bind']) $out['reason']='execution_attempt_already_prepared';
        return $out;
    }

    private function executionValidatePlan(array $row): void
    {
        self::queueId($row['queue_id']);self::actor((int)$row['admin_id'],$row['oauth_connection_id']);
        self::sha($row['baseline_sha256']);self::sha($row['plan_sha256']);
        $index=self::executionInteger($row['stage_index'],self::MAX_STAGE_INDEX);
        $initial=self::executionInteger($row['initial_phase_counter'],1);self::executionInteger($row['phase_counter'],1);
        self::executionInteger($row['accepted_batches'],intdiv(self::MAX_STAGE_INDEX,2));
        self::executionInteger($row['watchdog_epoch'],self::MAX_STAGE_INDEX);
        if ($row['project_key']!==self::PROJECT||!in_array($row['start_country'],self::COUNTRIES,true)
            ||!in_array($row['mode'],['finite','continuous'],true)) throw new RuntimeException();
        $limit=$row['batch_limit']===null?null:self::executionInteger($row['batch_limit'],10);
        if (($row['mode']==='continuous'&&$limit!==null)||($row['mode']==='finite'&&($limit===null||$limit<1))
            ||$row['plan_sha256']!==self::hash([$row['mode'],$limit,$row['start_country'],$initial,$row['baseline_sha256']]))
            throw new RuntimeException();
        if ($row['claim_sha256']!==null) self::sha($row['claim_sha256']);
        if ($row['current_run_id']!==null) self::runId($row['current_run_id']);
        if (!in_array($row['state'],['ready','claimed','unknown','complete','released'],true)||$index<0) throw new RuntimeException();
    }

    private static function executionInteger(mixed $value,int $max): int
    {
        if (!is_int($value)&&(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)!==1)) throw new RuntimeException();
        if ((int)$value<0||(int)$value>$max) throw new RuntimeException();return (int)$value;
    }

    private function executionCoreState(array $row): array
    {
        return $this->deliveryRepairState($row)+['claim_sha256'=>$row['claim_sha256'],'created_at'=>$row['created_at'],
            'delivery'=>$this->deliveryCursor($row)];
    }

    private function executionSnapshot(array $row,?array $record): array
    {
        $stage=$this->stageId($row);
        $receipt=$this->one('SELECT receipt_sha256 FROM hotel_work_receipts WHERE queue_id=? AND stage_id=?',[$row['queue_id'],$stage]);
        $resolution=$this->one('SELECT resolution_sha256 FROM hotel_work_resolutions WHERE queue_id=? AND stage_id=?',[$row['queue_id'],$stage]);
        $skip=$this->one('SELECT skip_sha256 FROM hotel_work_research_skips WHERE queue_id=? AND stage_id=?',[$row['queue_id'],$stage]);
        return ['state'=>$this->executionCoreState($row),'receipt_sha256'=>$receipt['receipt_sha256']??null,
            'resolution_sha256'=>$resolution['resolution_sha256']??null,'skip_sha256'=>$skip['skip_sha256']??null,
            'permit_sha256'=>$record['permit_sha256']??null,'entry_sha256'=>$record['_entry']['entry_sha256']??null,
            'association_sha256'=>$record['_association']['association_sha256']??null];
    }

    /** Validate the entire immutable chain before trusting any of its metadata. */
    private function executionAttemptRecord(array $row,string $stage): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM hotel_work_execution_attempts WHERE queue_id=? AND stage_id=? LIMIT 2');
        $q->execute([$row['queue_id'],$stage]);$permits=$q->fetchAll(PDO::FETCH_ASSOC);
        $permitId=count($permits)===1?($permits[0]['permit_sha256']??''):null;
        $entries=$this->executionRelatedRows('hotel_work_execution_attempt_entries',$row['queue_id'],$stage,$permitId);
        $associations=$this->executionRelatedRows('hotel_work_execution_attempt_associations',$row['queue_id'],$stage,$permitId);
        if (!$permits&&!$entries&&!$associations) return null;
        try {
            if (count($permits)!==1||count($entries)>1||count($associations)>1) throw new RuntimeException();
            $this->executionValidatePlan($row);$record=$permits[0];
            $audit=$this->executionDecodeAudit($record,12288);
            self::executionFields($audit,['namespace','admin_id','connection_id','queue_id','stage_id','stage_index',
                'plan_sha256','baseline_sha256','canonical_run_id','claim_sha256','request_sha256','request','original_snapshot','created_at']);
            if ($audit['namespace']!=='hotel_execution_attempt_v1'||!is_int($audit['admin_id'])
                ||$audit['admin_id']!==(int)$row['admin_id']||!is_int($audit['stage_index'])
                ||$audit['stage_index']<0||$audit['stage_index']>self::MAX_STAGE_INDEX||$audit['stage_index']%2!==0
                ||$audit['stage_index']>(int)$row['stage_index']
                ||$audit['plan_sha256']!==$row['plan_sha256']||$audit['baseline_sha256']!==$row['baseline_sha256'])
                throw new RuntimeException();
            self::actor($audit['admin_id'],$audit['connection_id']);
            self::sha($audit['claim_sha256']);self::runId($audit['canonical_run_id']);
            $historical=$row;$historical['stage_index']=$audit['stage_index'];
            if ($this->stageId($historical)!==$stage) throw new RuntimeException();
            foreach (['queue_id','stage_id','canonical_run_id','claim_sha256','request_sha256','created_at'] as $field)
                if ($record[$field]!==$audit[$field]) throw new RuntimeException();
            if ($audit['queue_id']!==$row['queue_id']||$audit['stage_id']!==$stage
                ||self::executionInteger($record['stage_index'],self::MAX_STAGE_INDEX)!==$audit['stage_index'])
                throw new RuntimeException();
            $args=$audit['request'];if (!is_array($args)) throw new RuntimeException();
            $this->executionPrepareArgs($args,false);
            if ($args['queue_id']!==$audit['queue_id']||$args['stage_id']!==$stage
                ||$args['baseline_sha256']!==$audit['baseline_sha256']
                ||$args['expected_caller_connection_id']!==$audit['connection_id']
                ||$args['expected_current_run_id']!==$audit['canonical_run_id']
                ||$args['attempt_token']!==$record['attempt_token']
                ||$audit['request_sha256']!==$this->executionRequestHash('permit',$audit['admin_id'],$audit['connection_id'],$args,$audit['claim_sha256'])
                ||$record['permit_sha256']!==self::hash($audit)) throw new RuntimeException();
            $this->executionValidateOriginalSnapshot($row,$audit,$audit['original_snapshot'],null,null,$record['created_at']);
            if ($args['expected_state_sha256']!==self::hash($audit['original_snapshot'])) throw new RuntimeException();
            $record['_audit']=$audit;$record['_entry']=null;$record['_association']=null;
            if ($entries) {
                $entry=$entries[0];$entryAudit=$this->executionDecodeAudit($entry,8192);
                self::executionFields($entryAudit,['namespace','permit_sha256','admin_id','connection_id','queue_id',
                    'stage_id','request_sha256','request','created_at']);
                if ($entryAudit['namespace']!=='hotel_execution_entry_v1') throw new RuntimeException();
                $this->executionValidateChild($record,$entry,$entryAudit);
                $entryArgs=$entryAudit['request'];if (!is_array($entryArgs)) throw new RuntimeException();
                $this->executionEntryArgs($entryArgs);$this->executionMatchEntry($record,$entryArgs);
                if ($entry['instance_id']!==$entryArgs['instance_id']
                    ||$entry['request_sha256']!==$this->executionRequestHash('entry',$audit['admin_id'],$audit['connection_id'],$entryArgs)
                    ||$entry['entry_sha256']!==self::hash($entryAudit)) throw new RuntimeException();
                $record['_entry']=$entry+['_audit'=>$entryAudit];
            }
            if ($associations) {
                if ($record['_entry']===null) throw new RuntimeException();
                $association=$associations[0];$associationAudit=$this->executionDecodeAudit($association,8192);
                self::executionFields($associationAudit,['namespace','permit_sha256','entry_sha256','admin_id','connection_id',
                    'queue_id','stage_id','claim_sha256','request_sha256','request','original_snapshot','created_at']);
                if ($associationAudit['namespace']!=='hotel_execution_association_v1'
                    ||$associationAudit['entry_sha256']!==$record['_entry']['entry_sha256']
                    ||$association['entry_sha256']!==$record['_entry']['entry_sha256']
                    ||$associationAudit['claim_sha256']!==$record['claim_sha256']) throw new RuntimeException();
                $this->executionValidateChild($record,$association,$associationAudit);
                $associationArgs=$associationAudit['request'];if (!is_array($associationArgs)) throw new RuntimeException();
                $this->executionBindArgs($associationArgs,false);$this->executionMatchBinding($record,$associationArgs);
                foreach (['queue_id','stage_id','baseline_sha256','expected_caller_connection_id','expected_current_run_id'] as $field)
                    if ($associationArgs[$field]!==$audit['request'][$field]) throw new RuntimeException();
                if ($associationArgs['producer_run_id']===$record['canonical_run_id']
                    ||$association['producer_run_id']!==$associationArgs['producer_run_id']
                    ||$association['packet_sha256']!==$associationArgs['packet_sha256']
                    ||$association['request_sha256']!==$this->executionRequestHash('association',$audit['admin_id'],$audit['connection_id'],$associationArgs,$record['claim_sha256'])
                    ||$association['association_sha256']!==self::hash($associationAudit)
                    ||self::deliveryAuditTime($association['created_at'])<self::deliveryAuditTime($record['_entry']['created_at']))
                    throw new RuntimeException();
                $this->executionValidateOriginalSnapshot($row,$audit,$associationAudit['original_snapshot'],
                    $record['permit_sha256'],$record['_entry']['entry_sha256'],$association['created_at']);
                if ($associationArgs['expected_state_sha256']!==self::hash($associationAudit['original_snapshot'])) throw new RuntimeException();
                $record['_association']=$association+['_audit'=>$associationAudit];
            }
            if ($this->one('SELECT stage_id FROM hotel_work_resolutions WHERE queue_id=? AND stage_id=?',[$row['queue_id'],$stage])
                ||$this->one('SELECT stage_id FROM hotel_work_research_skips WHERE queue_id=? AND stage_id=?',[$row['queue_id'],$stage]))
                throw new RuntimeException();
            if ((int)$row['stage_index']===$audit['stage_index']) {
                if ($row['state']!=='claimed'||$row['oauth_connection_id']!==$audit['connection_id']
                    ||$row['claim_sha256']!==$record['claim_sha256']||$row['current_run_id']!==$record['canonical_run_id'])
                    throw new RuntimeException();
                if ($this->one('SELECT stage_id FROM hotel_work_receipts WHERE queue_id=? AND stage_id=?',[$row['queue_id'],$stage]))
                    throw new RuntimeException();
            } else {
                if ($record['_association']===null) throw new RuntimeException();
                $receipt=$this->one('SELECT stage_index,receipt_sha256,receipt_json FROM hotel_work_receipts WHERE queue_id=? AND stage_id=?',[$row['queue_id'],$stage]);
                if (!$receipt||(int)$receipt['stage_index']!==$audit['stage_index']) throw new RuntimeException();
                $body=self::receipt(json_decode($receipt['receipt_json'],true,16,JSON_THROW_ON_ERROR));
                if (self::canonical($body)!==$receipt['receipt_json']||self::hash($body)!==$receipt['receipt_sha256']
                    ||$body['outcome']!=='accepted_readback'||$body['criteria']!==null
                    ||$body['run_id']!==$record['_association']['producer_run_id']
                    ||$body['packet_sha256']!==$record['_association']['packet_sha256']) throw new RuntimeException();
            }
            return $record;
        } catch (PDOException $storage) {throw $storage;}
        catch (Throwable $invalid) {throw new HotelWorkQueueConflict('execution_attempt_audit_invalid');}
    }

    private function executionRelatedRows(string $table,string $queue,string $stage,?string $permit): array
    {
        $sql='SELECT * FROM '.$table.' WHERE (queue_id=? AND stage_id=?)';$args=[$queue,$stage];
        if ($permit!==null) {$sql.=' OR permit_sha256=?';$args[]=$permit;}
        $q=$this->pdo->prepare($sql.' LIMIT 2');$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function executionDecodeAudit(array $record,int $max): array
    {
        if (!is_string($record['audit_json']??null)||strlen($record['audit_json'])>$max) throw new RuntimeException();
        $audit=json_decode($record['audit_json'],true,32,JSON_THROW_ON_ERROR);
        if (!is_array($audit)||array_is_list($audit)||self::canonical($audit)!==$record['audit_json']) throw new RuntimeException();
        self::deliveryAuditTime($record['created_at']??null);
        if (($audit['created_at']??null)!==$record['created_at']) throw new RuntimeException();
        return $audit;
    }

    private function executionValidateChild(array $record,array $child,array $audit): void
    {
        foreach (['queue_id','stage_id','request_sha256','created_at'] as $field)
            if ($child[$field]!==$audit[$field]) throw new RuntimeException();
        if ($child['queue_id']!==$record['queue_id']||$child['stage_id']!==$record['stage_id']
            ||$child['permit_sha256']!==$record['permit_sha256']||$audit['permit_sha256']!==$record['permit_sha256']
            ||$audit['admin_id']!==$record['_audit']['admin_id']
            ||$audit['connection_id']!==$record['_audit']['connection_id']
            ||self::deliveryAuditTime($child['created_at'])<self::deliveryAuditTime($record['created_at'])) throw new RuntimeException();
    }

    private function executionValidateOriginalSnapshot(array $row,array $audit,mixed $snapshot,?string $permit,?string $entry,string $createdAt): void
    {
        if (!is_array($snapshot)) throw new RuntimeException();
        self::executionFields($snapshot,['state','receipt_sha256','resolution_sha256','skip_sha256','permit_sha256','entry_sha256','association_sha256']);
        if ($snapshot['receipt_sha256']!==null||$snapshot['resolution_sha256']!==null||$snapshot['skip_sha256']!==null
            ||$snapshot['permit_sha256']!==$permit||$snapshot['entry_sha256']!==$entry||$snapshot['association_sha256']!==null)
            throw new RuntimeException();
        $state=$snapshot['state'];if (!is_array($state)) throw new RuntimeException();
        self::executionFields($state,array_keys($this->executionCoreState($row)));
        $historical=$row;
        foreach ($state as $field=>$value) if (array_key_exists($field,$historical)) $historical[$field]=$value;
        if (!is_array($state['delivery'])) throw new RuntimeException();
        self::executionFields($state['delivery'],array_keys($this->deliveryCursor($row)));
        foreach ($state['delivery'] as $field=>$value) $historical[$field]=$value;
        $this->executionValidatePlan($historical);
        foreach (['project_key','admin_id','mode','batch_limit','start_country','initial_phase_counter','baseline_sha256','plan_sha256','queue_id'] as $field)
            if ($state[$field]!==$this->executionCoreState($row)[$field]) throw new RuntimeException();
        foreach (['stage_index','accepted_batches','phase_counter','initial_phase_counter','watchdog_epoch'] as $field)
            if (!is_int($state[$field])) throw new RuntimeException();
        if ($state['state']!=='claimed'||$state['claim_present']!==true
            ||$state['stage_index']!==$audit['stage_index']||$state['oauth_connection_id']!==$audit['connection_id']
            ||$state['claim_sha256']!==$audit['claim_sha256']||$state['current_run_id']!==$audit['canonical_run_id']
            ||$state['accepted_batches']>intdiv($audit['stage_index'],2)
            ||self::canonical($this->executionCoreState($historical))!==self::canonical($state)) throw new RuntimeException();
        $queueCreated=self::deliveryAuditTime($state['created_at']);
        $updated=self::deliveryAuditTime($state['delivery']['updated_at']);
        if ($updated<$queueCreated||self::deliveryAuditTime($createdAt)<$updated) throw new RuntimeException();
        foreach (['delivered_at','claim_deadline','attention_at','updated_at'] as $field)
            if ($state['delivery'][$field]!==null) self::deliveryAuditTime($state['delivery'][$field]);
        foreach (['event_ack','attention_ack'] as $field) if ($state['delivery'][$field]!==null
            &&(!is_string($state['delivery'][$field])||preg_match('/\Ahotel_(?:evt|attention)_[a-f0-9]{64}\z/D',$state['delivery'][$field])!==1))
            throw new RuntimeException();
        if ($state['watchdog_due_at']!==null) self::deliveryAuditTime($state['watchdog_due_at']);
        if ($state['last_watch_ack']!==null&&(!is_string($state['last_watch_ack'])
            ||preg_match('/\Ahotel_attention_[a-f0-9]{64}\z/D',$state['last_watch_ack'])!==1)) throw new RuntimeException();
    }

    private function executionMetadata(array $record): array
    {
        $a=$record['_audit'];$association=$record['_association'];
        return ['permit_sha256'=>$record['permit_sha256'],'operation_id'=>$a['request']['operation_id'],
            'stage_id'=>$record['stage_id'],'ordinal'=>3,'dispatch_ceiling'=>3,
            'canonical_run_id'=>$record['canonical_run_id'],'entry_sha256'=>$record['_entry']['entry_sha256']??null,
            'producer_association_sha256'=>$association['association_sha256']??null,
            'producer_run_id'=>$association['producer_run_id']??null,'packet_sha256'=>$association['packet_sha256']??null,
            'created_at'=>$record['created_at'],'semantics'=>'authenticated_attestations_request_origin_unknown_no_host_fence_proof'];
    }

    private function executionPermitReceipt(array $record): array
    {
        $audit=$record['_audit'];$args=$audit['request'];
        return ['permit_sha256'=>$record['permit_sha256'],'request_sha256'=>$record['request_sha256'],
            'queue_id'=>$record['queue_id'],'stage_id'=>$record['stage_id'],'stage_index'=>$audit['stage_index'],
            'operation_id'=>$args['operation_id'],'canonical_run_id'=>$record['canonical_run_id'],'ordinal'=>3,
            'dispatch_ceiling'=>3,'baseline_sha256'=>$audit['baseline_sha256'],
            'scope_sha256'=>$args['scope_sha256'],'owner_decision_sha256'=>$args['owner_decision_sha256'],
            'prompt_readback_sha256'=>$args['prompt_readback_sha256'],'assignment_readback_sha256'=>$args['assignment_readback_sha256'],
            'fencing_readback_sha256'=>$args['fencing_readback_sha256'],'fencing_status'=>$args['fencing_status'],
            'prior_attempts'=>$args['prior_attempts'],'created_at'=>$record['created_at'],
            'semantics'=>'authenticated_owner_attestation_not_host_truth'];
    }

    private function executionPermitResult(array $row,array $record,string $status): array
    {
        return $this->project($row)+['attempt_status'=>$status,'permit_sha256'=>$record['permit_sha256'],
            'request_sha256'=>$record['request_sha256'],'receipt'=>$this->executionPermitReceipt($record),
            'operation_dispatches_work'=>false,'work_authorized'=>false];
    }

    private function executionEntryResult(array $row,array $record,string $status,bool $first): array
    {
        $entry=$record['_entry'];if ($entry===null) throw new HotelWorkQueueConflict('execution_attempt_audit_invalid');
        $receipt=['entry_sha256'=>$entry['entry_sha256'],'permit_sha256'=>$record['permit_sha256'],
            'request_sha256'=>$entry['request_sha256'],'queue_id'=>$record['queue_id'],'stage_id'=>$record['stage_id'],
            'instance_id'=>$entry['instance_id'],'operation_id'=>$record['_audit']['request']['operation_id'],'created_at'=>$entry['created_at']];
        return $this->project($row)+['entry_status'=>$status,'entry_sha256'=>$entry['entry_sha256'],'receipt'=>$receipt,
            'operation_dispatches_work'=>false,'work_authorized'=>$first];
    }

    private function executionAssociationResult(array $row,array $record,string $status): array
    {
        $association=$record['_association'];if ($association===null) throw new HotelWorkQueueConflict('execution_attempt_audit_invalid');
        $receipt=['producer_association_sha256'=>$association['association_sha256'],'permit_sha256'=>$record['permit_sha256'],
            'entry_sha256'=>$association['entry_sha256'],'request_sha256'=>$association['request_sha256'],
            'queue_id'=>$record['queue_id'],'stage_id'=>$record['stage_id'],'canonical_run_id'=>$record['canonical_run_id'],
            'producer_run_id'=>$association['producer_run_id'],'packet_sha256'=>$association['packet_sha256'],
            'native_result_readback_sha256'=>$association['_audit']['request']['native_result_readback_sha256'],
            'created_at'=>$association['created_at'],'semantics'=>'authenticated_native_result_correlation_not_request_origin_proof'];
        return $this->project($row)+['association_status'=>$status,'producer_association_sha256'=>$association['association_sha256'],
            'receipt'=>$receipt,'operation_dispatches_work'=>false,'work_authorized'=>false];
    }

    private function executionBlockMutation(array $row,string $stage): void
    {
        if ($this->executionAttemptRecord($row,$stage)!==null) throw new HotelWorkQueueConflict('execution_attempt_required');
    }

    private function executionCompletionAssociation(array $row,string $stage,?string $association,array $receipt): ?array
    {
        $record=$this->executionAttemptRecord($row,$stage);
        if ($association===null) {
            if ($record!==null) throw new HotelWorkQueueConflict('execution_attempt_required');
            return null;
        }
        if ($record===null||$record['_association']===null
            ||$record['_association']['association_sha256']!==$association)
            throw new HotelWorkQueueConflict('execution_attempt_association_mismatch');
        if ($receipt['outcome']!=='accepted_readback') throw new HotelWorkQueueConflict('execution_attempt_accepted_readback_required');
        if ($receipt['run_id']!==$record['_association']['producer_run_id']
            ||$receipt['packet_sha256']!==$record['_association']['packet_sha256'])
            throw new HotelWorkQueueConflict('execution_attempt_association_mismatch');
        $this->executionCurrentClaim($row,$record);return $record;
    }
}
