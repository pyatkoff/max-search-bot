<?php
declare(strict_types=1);
require_once __DIR__.'/InternalOwnerDialogueScope.php';

/** A bounded, text-free read of first OBSERVED substantive inquiries, never lifetime first contact. */
final class ModeledConversionAcquisitionReadFailure extends RuntimeException {}
final class ModeledConversionAcquisitionStatsReader
{
    public const RULE_VERSION='saved_substantive_interest_v1';
    public const MAX_RECORDS=50000;
    public const MAX_BYTES=8388608;
    public const MAX_SECONDS=15;
    private bool $mysql;
    private bool $jsonTable=false;
    private $authorize;
    private float $deadline;
    private int $records=0,$bytes=0,$queries=0;
    private array $sources=[];
    private string $admittedScopeSql='';
    public function __construct(private PDO $pdo,callable $authorize)
    {
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if(!in_array($driver,['mysql','sqlite'],true))throw new InvalidArgumentException('acquisition_driver');
        $this->mysql=$driver==='mysql';$this->authorize=$authorize;
        if($this->mysql){
            try{
                $version=(string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
                $this->jsonTable=stripos($version,'mariadb')===false
                    &&preg_match('/\A([0-9]+\.[0-9]+\.[0-9]+)/',$version,$parts)===1
                    &&version_compare($parts[1],'8.0.21','>=');
            }catch(Throwable $ignored){$this->jsonTable=false;}
        }
    }
    public function read(array $input,int $now):array
    {
        $this->auth();$filters=$this->filters($input,$now);
        $this->deadline=microtime(true)+self::MAX_SECONDS;$this->records=$this->bytes=$this->queries=0;$this->admittedScopeSql='';
        $this->sources=['profile_fence'=>'0','profiles_examined'=>0,'projected_records'=>0,
            'first_profile_at'=>null,'last_profile_at'=>null,'missing_clock'=>0,'revised_sources'=>0,
            'unusable_semantic_candidates'=>0,'profiles_without_usable_packet'=>0,'limited_profile_windows'=>0,
            'coverage'=>'prospective_saved_profiles_not_full_archive','history_complete'=>false,
            'inquiry_time_basis'=>'canonical_recording_clock_not_provider_occurrence',
            'membership_time_basis'=>'saved_max_membership_history_clock','join_coverage'=>'saved_receipts_not_full_channel'];
        $result=['schema_version'=>1,'available'=>false,'reason'=>null,'as_of'=>self::iso($now),
            'meaning'=>'first_observed_substantive_inquiry_not_first_ever','rule_version'=>self::RULE_VERSION,
            'filters'=>$filters,'window'=>$this->window($filters,$now),'platforms'=>null,'sources'=>$this->sources,
            'limits'=>['max_records'=>self::MAX_RECORDS,'max_bytes'=>self::MAX_BYTES,'max_seconds'=>self::MAX_SECONDS],
            'query_count'=>0];
        if($this->pdo->inTransaction()){$result['reason']='outer_transaction';$this->auth();return$result;}
        $own=false;
        try{
            $this->auth();
            try{
                if($this->mysql){$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->exec('SET TRANSACTION READ ONLY');}
                if(!$this->pdo->beginTransaction())throw new RuntimeException();
                $own=true;
            }catch(Throwable $e){throw new ModeledConversionAcquisitionReadFailure('storage_unavailable');}
            $this->auth();
            $f=$this->rows("SELECT (SELECT COALESCE(MAX(id),0) FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND HEX(project_key)=HEX('anytour') AND observed_at_unix<=?) AS pf,(SELECT COALESCE(MAX(id),0) FROM conversation_events) AS ef",[$now])[0];
            $fence=(string)$f['pf'];$eventFence=(string)$f['ef'];$this->sources['profile_fence']=$fence;
            // Bound total input work before expensive native ownership/latest-version admission.
            // This is a conservative input cap, never a cohort denominator.
            $potential="project_key='anytour' AND HEX(project_key)=HEX('anytour') AND platform IN ('max','telegram') AND HEX(platform) IN (HEX('max'),HEX('telegram'))"
                .($filters['platform']==='all'?'':" AND platform='".$filters['platform']."' AND HEX(platform)=HEX('".$filters['platform']."')");
            $inputCount=$this->rows('SELECT COUNT(*) AS n FROM modeled_conversion_profile_versions WHERE '.$potential.' AND id<=? AND observed_at_unix<=?',[$fence,$now])[0];
            if((int)$inputCount['n']>self::MAX_RECORDS)throw new ModeledConversionAcquisitionReadFailure('record_limit');
            $this->admittedScopeSql=$this->admittedScopes($filters['platform'],$fence,$now);
            // Admit latest exact scopes once in this fixed read-only snapshot.
            // Only these bounded identity coordinates are retained, never profile bodies.
            $admitWhere=$this->profileScope($filters['platform']);
            $scopes=$this->rows('SELECT p.project_key,p.platform,p.customer_key,p.conversation_id '.$this->profileFrom()
                .' WHERE '.$admitWhere);
            $seenScopes=[];
            foreach($scopes as$scope){
                if($scope['project_key']!=='anytour'||!in_array($scope['platform'],['max','telegram'],true)
                    ||!self::positive((string)$scope['customer_key'])||!self::positive((string)$scope['conversation_id']))
                    throw new ModeledConversionAcquisitionReadFailure('source_changed');
                $key=$scope['platform'].':'.$scope['customer_key'].':'.$scope['conversation_id'];
                if(isset($seenScopes[$key]))throw new ModeledConversionAcquisitionReadFailure('source_changed');
                $seenScopes[$key]=true;
            }
            usort($scopes,static function(array $a,array $b):int{
                return strcmp($a['platform'],$b['platform'])?:self::decimalCompare((string)$a['customer_key'],(string)$b['customer_key'])
                    ?:self::decimalCompare((string)$a['conversation_id'],(string)$b['conversation_id']);
            });
            $batches=array_chunk($scopes,64);unset($scopes,$seenScopes);
            // Count every disjoint admitted batch before deriving any cohort result.
            $count=0;$first=null;$last=null;
            foreach($batches as$batch){
                [$where,$scopeArgs]=$this->historicalScope($filters['platform'],$batch,$fence,$now);
                $meta=$this->rows('SELECT COUNT(*) AS n,MIN(p.observed_at_unix) AS lo,MAX(p.observed_at_unix) AS hi '.$this->profileFrom(false).' WHERE '.$where,$scopeArgs)[0];
                $count+=(int)$meta['n'];
                if($count>self::MAX_RECORDS)throw new ModeledConversionAcquisitionReadFailure('record_limit');
                if($meta['lo']!==null)$first=$first===null?(int)$meta['lo']:min($first,(int)$meta['lo']);
                if($meta['hi']!==null)$last=$last===null?(int)$meta['hi']:max($last,(int)$meta['hi']);
            }
            $this->sources['profiles_examined']=$count;
            $this->sources['first_profile_at']=$first===null?null:self::iso($first);
            $this->sources['last_profile_at']=$last===null?null:self::iso($last);
            $people=[];
            foreach($batches as$batch){
                [$where,$scopeArgs]=$this->historicalScope($filters['platform'],$batch,$fence,$now);
                $after='0';
                do{
                    $doc=$this->safe('p.profile_json');
                    $native=['p.id','p.platform','p.customer_key','p.conversation_id','p.observed_at_unix'];
                    $paths=['access'=>'$.access_status','project'=>'$.subject.project_key','platform_json'=>'$.subject.platform',
                        'customer_json'=>'$.subject.customer_key','conversation_json'=>'$.subject.conversation_id',
                        'input_project'=>'$.profile.observation_input.subject.project_key','input_platform'=>'$.profile.observation_input.subject.platform',
                        'input_customer'=>'$.profile.observation_input.subject.customer_key','input_conversation'=>'$.profile.observation_input.subject.conversation_id',
                        'context_version'=>'$.contextual_signals.version','extractor_version'=>'$.contextual_signals.extractor_version',
                        'cutoff'=>'$.contextual_signals.input_cutoff_at','input_prediction'=>'$.profile.observation_input.prediction_at',
                        'context_prediction'=>'$.contextual_signals.prediction_at','fence'=>'$.source_fence_journal_id'];
                    // Select cheap IDs first; JSON work cannot move before this bounded page.
                    $selected='SELECT p.id '.$this->profileFrom(false,true).' WHERE '.$where.' AND p.id>? ORDER BY p.id LIMIT 128';
                    $pageFrom=' FROM ('.$selected.') selected_page JOIN modeled_conversion_profile_versions p ON p.id=selected_page.id';
                    if($this->mysql){
                        // Parse the large historical document once for these fixed, mandatory paths.
                        // A second LIMIT prevents derived merging from repeating the parse per field.
                        if($this->jsonTable){
                            // JSON-typed columns keep strings/numbers/arrays/objects,
                            // rather than coercing metadata through VARCHAR/INTEGER.
                            // LEFT JOIN at root '$' preserves one row even for a null/invalid packet.
                            $columns=[];$raw=[];$index=0;
                            foreach($paths as$alias=>$path){
                                $column='j'.$index++;$raw[]='jt.'.$column.' AS '.$alias.'_raw';
                                $columns[]=$column.' JSON PATH '.$this->lit($path).' NULL ON EMPTY NULL ON ERROR';
                            }
                            $columns[]='limited_value JSON PATH '.$this->lit('$.coverage.window_limited').' NULL ON EMPTY NULL ON ERROR';
                            $columns[]='events_value JSON PATH '.$this->lit('$.contextual_signals.events').' NULL ON EMPTY NULL ON ERROR';
                            $events="JSON_OBJECT('contextual_signals',JSON_OBJECT('events',jt.events_value))";
                            $packed=[...$native,...$raw,
                                $this->boolean('jt.limited_value',$this->lit('$')).' AS limited',
                                $this->interestPaths($events).' AS interest_paths'];
                            $bounded='SELECT '.implode(',',$packed).$pageFrom
                                .' LEFT JOIN JSON_TABLE('.$doc.','.$this->lit('$').' COLUMNS ('.implode(',',$columns).')) jt ON TRUE'
                                .' ORDER BY p.id LIMIT 128';
                        }else{
                            $packed=[...$native,'JSON_EXTRACT('.$doc.','.implode(',',array_map(fn(string $path):string=>$this->lit($path),array_values($paths))).') AS metadata_values',
                                $this->boolean($doc,$this->lit('$.coverage.window_limited')).' AS limited',
                                $this->interestPaths($doc).' AS interest_paths'];
                            $bounded='SELECT '.implode(',',$packed).$pageFrom.' ORDER BY p.id LIMIT 128';
                        }
                        // Multi-path extraction omits absent paths. Every one is already mandatory
                        // in validPacket: reject the entire short array rather than shift identities.
                        $fields=$native;
                        if($this->jsonTable){
                            foreach($paths as$alias=>$unused){
                                $raw='p.'.$alias.'_raw';$unquoted='JSON_UNQUOTE('.$raw.')';
                                $fields[]="CASE WHEN JSON_TYPE($raw)='STRING' AND LENGTH($unquoted)<=128 THEN $unquoted ELSE NULL END AS $alias";
                            }
                        }else{
                            $small="CASE WHEN JSON_TYPE(p.metadata_values)='ARRAY' AND JSON_LENGTH(p.metadata_values)=15 THEN p.metadata_values ELSE '{}' END";
                            $index=0;
                            foreach($paths as$alias=>$unused)$fields[]=$this->str($small,$this->lit('$['.$index++.']')).' AS '.$alias;
                        }
                        $fields[]='p.limited';$fields[]='p.interest_paths';
                        $page=$this->rows('SELECT '.implode(',',$fields).' FROM ('.$bounded.') p ORDER BY p.id',[...$scopeArgs,$after]);
                    }else{
                        $fields=$native;
                        foreach($paths as$alias=>$path)$fields[]=$this->str($doc,$this->lit($path)).' AS '.$alias;
                        $fields[]=$this->boolean($doc,$this->lit('$.coverage.window_limited')).' AS limited';
                        $fields[]=$this->interestPaths($doc).' AS interest_paths';
                        $page=$this->rows('SELECT '.implode(',',$fields).$pageFrom.' ORDER BY p.id',[...$scopeArgs,$after]);
                    }
                    $targets=[];$packets=[];
                    foreach($page as$p){
                        $after=(string)$p['id'];
                        if(!$this->validPacket($p,$now)){$this->sources['profiles_without_usable_packet']++;continue;}
                        if((string)$p['limited']==='1')$this->sources['limited_profile_windows']++;
                        $paths=json_decode((string)($p['interest_paths']??'null'),true);
                        if($paths===null)continue;
                        if(is_string($paths))$paths=[$paths];
                        if(!is_array($paths)||!array_is_list($paths)||count($paths)>50)throw new ModeledConversionAcquisitionReadFailure('source_shape_limit');
                        $packets[(string)$p['id']]=$p;
                        foreach($paths as$path){
                            if(!is_string($path)||preg_match('/\A\$\.contextual_signals\.events\[(?:0|[1-9][0-9]{0,3})\]\.goal\z/D',$path)!==1)
                                throw new ModeledConversionAcquisitionReadFailure('source_shape_limit');
                            $targets[]=[(string)$p['id'],substr($path,0,-5)];
                        }
                    }
                    foreach(array_chunk($targets,128)as$part){
                        foreach($this->candidateRows($part)as$r){
                            $p=$packets[(string)$r['pid']]??null;
                            if(!$p)throw new ModeledConversionAcquisitionReadFailure('source_changed');
                            $candidate=$this->candidate($p,$r,$now);
                            if($candidate===null)continue;
                            $key=$p['platform'].':'.$p['customer_key'];
                            if(!isset($people[$key])||$candidate['at']<$people[$key]['at']
                                ||($candidate['at']===$people[$key]['at']&&self::decimalCompare($candidate['journal'],$people[$key]['journal'])<0))
                                $people[$key]=['platform'=>$p['platform'],'customer'=>(string)$p['customer_key'],...$candidate];
                        }
                    }
                }while(count($page)===128);
            }

            $cohort=[];$from=strtotime($result['window']['from']);$exclusive=$this->endExclusive($filters['date']);
            foreach($people as$key=>$person)if($person['at']>=$from&&$person['at']<$exclusive&&$person['at']<=$now)$cohort[$key]=$person;
            $platforms=['max'=>$this->emptyPlatform('saved_max_events_only'),'telegram'=>$this->emptyPlatform('unavailable_pending_bitrix')];
            $max=[];
            foreach($cohort as$key=>$person){
                $platforms[$person['platform']]['inquiries']++;
                if($person['platform']==='telegram')$platforms['telegram']['excluded']['membership_unavailable']++;
                else$max[$key]=$person;
            }
            $bindings=[];$joins=[];
            foreach(array_chunk(array_values($max),100)as$group){
                $ids=array_column($group,'customer');$marks=implode(',',array_fill(0,count($ids),'?'));
                $bindSql=" FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND HEX(cc.project_key)=HEX(c.project_key) AND HEX(cc.channel)=HEX(c.channel)"
                    ." WHERE c.project_key='anytour' AND c.channel='max' AND HEX(c.project_key)=HEX('anytour') AND HEX(c.channel)=HEX('max') AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')
                    ." AND c.customer_id IN ($marks)";
                foreach($this->rows('SELECT c.id,c.customer_id,c.external_chat_id,cc.external_user_id,cc.external_chat_id AS bound_chat'.$bindSql,$ids)as$b)$bindings['max:'.$b['customer_id']][]=$b;
                $doc=$this->safe('e.payload_json');$fields=['e.id','c.customer_id','e.actor_type','e.actor_id'];
                foreach(['version'=>'$.version','join_at'=>'$.occurred_at_unix','clock_version'=>'$._recorded_clock.version','recorded'=>'$._recorded_clock.unix']as$alias=>$path)
                    $fields[]=$this->integer($doc,$this->lit($path)).' AS '.$alias;
                foreach(['origin'=>'$.origin','source'=>'$.source','identity_scope'=>'$.identity_scope','state'=>'$.state','clock_basis'=>'$._recorded_clock.basis']as$alias=>$path)
                    $fields[]=$this->str($doc,$this->lit($path)).' AS '.$alias;
                $membershipFrom=str_replace(' FROM conversations c',' FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id',$bindSql);
                foreach($this->rows('SELECT '.implode(',',$fields).$membershipFrom." AND e.event_type='channel_subscription_observed' AND HEX(e.event_type)=HEX('channel_subscription_observed') AND e.id<=? ORDER BY e.id",[...$ids,$eventFence])as$j){
                    $key='max:'.$j['customer_id'];
                    $trusted=(string)$j['version']==='1'&&$j['actor_type']==='system'&&$j['origin']==='existing_membership_producer'
                        &&$j['source']==='max_membership_history'&&$j['identity_scope']==='same_chat_and_source'&&$j['state']==='subscribed';
                    if(!$trusted)continue;
                    $valid=(string)$j['clock_version']==='1'&&$j['clock_basis']==='server_recording_unix'
                        &&self::unix($j['join_at'])!==null&&self::unix($j['recorded'])!==null
                        &&(int)$j['join_at']<=(int)$j['recorded']&&(int)$j['recorded']<=$now;
                    if(!$valid){$this->sources['missing_clock']++;$joins[$key]['conflict']=true;continue;}
                    if(str_starts_with((string)$j['actor_id'],'max-membership-conflict:')){$joins[$key]['conflict']=true;continue;}
                    if(preg_match('/\Amax-membership:([1-9][0-9]{0,19})\z/D',(string)$j['actor_id'],$match)!==1){$joins[$key]['conflict']=true;continue;}
                    $id=$match[1];$at=(int)$j['join_at'];
                    if(isset($joins[$key]['periods'][$id])&&$joins[$key]['periods'][$id]!==$at)$joins[$key]['conflict']=true;
                    $joins[$key]['periods'][$id]=$at;
                }
            }
            $delays=[];
            foreach($max as$key=>$person){
                $bound=$bindings[$key]??[];$join=$joins[$key]??[];$periods=$join['periods']??[];
                $identity=count($bound)===1&&self::positive($bound[0]['external_user_id']??null)
                    &&(string)$bound[0]['external_chat_id']==='-'.$bound[0]['external_user_id']
                    &&(string)$bound[0]['bound_chat']===(string)$bound[0]['external_chat_id'];
                if(!$identity)$reason='identity_ambiguous';
                elseif(($join['conflict']??false)||count($periods)>1)$reason='join_ambiguous';
                elseif(count($periods)===0)$reason='join_missing';
                elseif(reset($periods)>$person['at'])$reason='join_after_inquiry';
                else{$delays[]=$person['at']-reset($periods);$reason=null;}
                if($reason!==null)$platforms['max']['excluded'][$reason]++;
            }
            $platforms['max']=$this->complete($platforms['max'],$delays);
            $platforms['telegram']=$this->complete($platforms['telegram'],[]);
            $this->guard();
            $result['available']=true;$result['platforms']=$platforms;
        }catch(ModeledConversionAcquisitionReadFailure $e){$result['reason']=$e->getMessage();}
        finally{
            if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();
        }
        $this->sources['projected_records']=$this->records;
        $result['sources']=$this->sources;$result['query_count']=$this->queries;
        $this->auth();
        return$result;
    }
    private function candidateRows(array $targets):array
    {
        if(!$targets)return[];
        $targetCount=count($targets);
        if($targetCount>128)throw new ModeledConversionAcquisitionReadFailure('source_shape_limit');
        $materialize=$this->mysql?' LIMIT '.$targetCount:'';
        $parts=[];$args=[];
        foreach($targets as[$id,$path]){$parts[]='SELECT ? AS pid,? AS ep';$args[]=$id;$args[]=$path;}
        $doc=$this->safe('p.profile_json');
        if($this->mysql){
            // Parse each selected profile once, before expanding its semantic targets.
            // LIMIT keeps both private capsule layers materialized; only the three
            // original subtrees cross the existing target/ref/search boundaries.
            $ids=array_values(array_unique(array_column($targets,0),SORT_STRING));
            $uniqueCount=count($ids);$marks=implode(',',array_fill(0,$uniqueCount,'?'));
            $profileParts='SELECT p.id AS pid,JSON_EXTRACT('.$doc.','.$this->lit('$.contextual_signals.events').','
                .$this->lit('$.profile.observation_input.evidence').','.$this->lit('$.source_references').') AS parts'
                .' FROM modeled_conversion_profile_versions p WHERE p.id IN ('.$marks.') LIMIT '.$uniqueCount;
            // Multi-path JSON_EXTRACT omits absent paths. Never shift a missing
            // subtree into another position; all three are mandatory for a candidate.
            // Explicit JSON null/scalar/object values retain their original type.
            $rebuilt="JSON_OBJECT('contextual_signals',JSON_OBJECT('events',JSON_EXTRACT(c.parts,'$[0]')),"
                ."'profile',JSON_OBJECT('observation_input',JSON_OBJECT('evidence',JSON_EXTRACT(c.parts,'$[1]'))),"
                ."'source_references',JSON_EXTRACT(c.parts,'$[2]'))";
            $compact='SELECT c.pid,CAST(CASE WHEN JSON_TYPE(c.parts)='.$this->lit('ARRAY')
                .' AND JSON_LENGTH(c.parts)=3 THEN '.$rebuilt.' ELSE JSON_OBJECT() END AS JSON) AS doc'
                .' FROM ('.$profileParts.') c LIMIT '.$uniqueCount;
            $base='SELECT c.pid,t.ep,c.doc FROM ('.implode(' UNION ALL ',$parts).') t JOIN ('.$compact.') c ON c.pid=t.pid'.$materialize;
            // The target UNION appears before the compact profile IN placeholders.
            array_push($args,...$ids);
        }else{
            $base='SELECT p.id AS pid,t.ep,'.$doc.' AS doc FROM ('.implode(' UNION ALL ',$parts).') t JOIN modeled_conversion_profile_versions p ON p.id=t.pid'.$materialize;
        }
        $a='SELECT z.*,'.$this->str('z.doc',$this->cat('z.ep',$this->lit('.source_ref'))).' AS ref,'
            .$this->str('z.doc',$this->cat('z.ep',$this->lit('.goal'))).' AS goal FROM ('.$base.') z'.$materialize;
        $b='SELECT a.*,'.$this->findPath('a.doc','a.ref',false).' AS epath,'.$this->findPath('a.doc','a.ref',true).' AS spath FROM ('.$a.') a'.$materialize;
        if($this->mysql){
            // Extract each evidence/source value once from the compact private
            // document. The final helpers inspect typed scalar columns directly.
            $evidencePaths=['kind'=>'.kind','eid'=>'.id','eat'=>'.at','eavailable'=>'.available_at',
                'eproject'=>'.subject.project_key','eplatform'=>'.subject.platform','ecustomer'=>'.subject.customer_key','econversation'=>'.subject.conversation_id',
                'rsource'=>'.references[0].source','rid'=>'.references[0].id','rat'=>'.references[0].at','ravailable'=>'.references[0].available_at'];
            $sourcePaths=['journal_id'=>'.journal_id','reference_source_id'=>'.source_id','source_kind'=>'.source_kind',
                'source_status'=>'.source_status','source_observed'=>'.observed_at'];
            $values=[];
            foreach($evidencePaths as$alias=>$suffix){
                $values[]='JSON_EXTRACT(b.doc,'.$this->cat('b.epath',$this->lit($suffix)).') AS '.$alias.'_raw';
            }
            $values[]='JSON_EXTRACT(b.doc,'.$this->cat('b.epath',$this->lit('.references')).') AS references_raw';
            foreach($sourcePaths as$alias=>$suffix){
                $values[]='JSON_EXTRACT(b.doc,'.$this->cat('b.spath',$this->lit($suffix)).') AS '.$alias.'_raw';
            }
            $values[]='JSON_EXTRACT(b.doc,'.$this->cat('b.spath',$this->lit('.source_version_no')).') AS source_version_raw';
            $savedPaths=['saved_snapshot_kind'=>'$.source_kind','saved_snapshot_id'=>'$.source_id','saved_status'=>'$.source_status',
                'saved_basis'=>'$.source_timestamp_basis','saved_project'=>'$.project_key','saved_platform'=>'$.platform',
                'saved_customer'=>'$.customer_key','saved_conversation'=>'$.conversation_id',
                'saved_direction'=>'$.payload.direction','saved_sender'=>'$.payload.sender_type','saved_at'=>'$.source_created_at_unix'];
            $joined=['b.pid','b.ref','b.goal','b.epath','b.spath',...$values,
                'sj.id AS saved_journal_id','sj.version_no AS saved_version','sj.observed_at_unix AS saved_available',
                'sj.source_kind AS saved_kind','sj.source_id AS saved_source_id',
                'm.id AS native_id','m.conversation_id AS native_conversation','m.channel AS native_channel',
                'm.direction AS native_direction','m.sender_type AS native_sender',
                'JSON_EXTRACT('.$this->safe('sj.snapshot_json').','.implode(',',array_map(fn(string $path):string=>$this->lit($path),array_values($savedPaths))).') AS saved_values'];
            $packed='SELECT '.implode(',',$joined).' FROM ('.$b.') b LEFT JOIN modeled_conversion_stream_versions sj ON sj.id=CAST(SUBSTR(b.ref,9) AS UNSIGNED)'
                ." AND HEX(sj.project_key)=HEX('anytour') LEFT JOIN messages m ON m.id=sj.source_id".$materialize;
            $rawString=static function(string $raw):string{
                $unquoted='JSON_UNQUOTE('.$raw.')';
                return"CASE WHEN JSON_TYPE($raw)='STRING' AND LENGTH($unquoted)<=128 THEN $unquoted ELSE NULL END";
            };
            $fields=['v.pid','v.ref','v.goal','v.epath','v.spath'];
            foreach($evidencePaths as$alias=>$unused)$fields[]=$rawString('v.'.$alias.'_raw').' AS '.$alias;
            $fields[]="CASE WHEN JSON_TYPE(v.references_raw)='ARRAY' THEN JSON_LENGTH(v.references_raw) ELSE NULL END AS reference_count";
            foreach($sourcePaths as$alias=>$unused)$fields[]=$rawString('v.'.$alias.'_raw').' AS '.$alias;
            $fields[]="CASE WHEN JSON_TYPE(v.source_version_raw)='INTEGER' THEN v.source_version_raw ELSE NULL END AS source_version";
            foreach(['saved_journal_id','saved_version','saved_available','saved_kind','saved_source_id',
                'native_id','native_conversation','native_channel','native_direction','native_sender']as$alias)$fields[]='v.'.$alias;
            // Every saved path is required by candidate(). Missing paths reject
            // the packet; the length guard prevents omitted-path slot shifting.
            $saved="CASE WHEN JSON_TYPE(v.saved_values)='ARRAY' AND JSON_LENGTH(v.saved_values)=11 THEN v.saved_values ELSE '{}' END";
            $index=0;
            foreach($savedPaths as$alias=>$unused){
                $path=$this->lit('$['.$index++.']');
                $fields[]=($alias==='saved_at'?$this->integer($saved,$path)
                    :(in_array($alias,['saved_customer','saved_conversation'],true)?$this->identity($saved,$path):$this->str($saved,$path))).' AS '.$alias;
            }
            return$this->rows('SELECT '.implode(',',$fields).' FROM ('.$packed.') v',$args);
        }
        $fields=['b.pid','b.ref','b.goal','b.epath','b.spath'];
        foreach(['kind'=>'.kind','eid'=>'.id','eat'=>'.at','eavailable'=>'.available_at',
            'eproject'=>'.subject.project_key','eplatform'=>'.subject.platform','ecustomer'=>'.subject.customer_key','econversation'=>'.subject.conversation_id',
            'rsource'=>'.references[0].source','rid'=>'.references[0].id','rat'=>'.references[0].at','ravailable'=>'.references[0].available_at']as$alias=>$suffix)
            $fields[]=$this->str('b.doc',$this->cat('b.epath',$this->lit($suffix))).' AS '.$alias;
        $fields[]=$this->arrayLength('b.doc',$this->cat('b.epath',$this->lit('.references'))).' AS reference_count';
        foreach(['journal_id'=>'.journal_id','reference_source_id'=>'.source_id','source_kind'=>'.source_kind','source_status'=>'.source_status','source_observed'=>'.observed_at']as$alias=>$suffix)
            $fields[]=$this->str('b.doc',$this->cat('b.spath',$this->lit($suffix))).' AS '.$alias;
        $fields[]=$this->integer('b.doc',$this->cat('b.spath',$this->lit('.source_version_no'))).' AS source_version';
        $fields[]='sj.id AS saved_journal_id';$fields[]='sj.version_no AS saved_version';$fields[]='sj.observed_at_unix AS saved_available';
        $fields[]='sj.source_kind AS saved_kind';$fields[]='sj.source_id AS saved_source_id';
        $fields[]='m.id AS native_id';$fields[]='m.conversation_id AS native_conversation';$fields[]='m.channel AS native_channel';$fields[]='m.direction AS native_direction';$fields[]='m.sender_type AS native_sender';
        $snap=$this->safe('sj.snapshot_json');
        foreach(['saved_snapshot_kind'=>'$.source_kind','saved_snapshot_id'=>'$.source_id','saved_status'=>'$.source_status','saved_basis'=>'$.source_timestamp_basis','saved_project'=>'$.project_key',
            'saved_platform'=>'$.platform','saved_customer'=>'$.customer_key','saved_conversation'=>'$.conversation_id',
            'saved_direction'=>'$.payload.direction','saved_sender'=>'$.payload.sender_type']as$alias=>$path)
            $fields[]=(in_array($alias,['saved_customer','saved_conversation'],true)?$this->identity($snap,$this->lit($path)):$this->str($snap,$this->lit($path))).' AS '.$alias;
        $fields[]=$this->integer($snap,$this->lit('$.source_created_at_unix')).' AS saved_at';
        $cast=$this->mysql?'UNSIGNED':'INTEGER';
        return$this->rows('SELECT '.implode(',',$fields).' FROM ('.$b.') b LEFT JOIN modeled_conversion_stream_versions sj ON sj.id=CAST(SUBSTR(b.ref,9) AS '.$cast.") AND HEX(sj.project_key)=HEX('anytour') LEFT JOIN messages m ON m.id=sj.source_id",$args);
    }
    private function validPacket(array $p,int $now):bool
    {
        return self::positive((string)$p['customer_key'])&&self::positive((string)$p['conversation_id'])&&$p['access']==='allowed'&&$p['project']==='anytour'&&$p['platform_json']===$p['platform']
            &&$p['customer_json']===(string)$p['customer_key']&&$p['conversation_json']===(string)$p['conversation_id']
            &&$p['input_project']==='anytour'&&$p['input_platform']===$p['platform']
            &&$p['input_customer']===(string)$p['customer_key']&&$p['input_conversation']===(string)$p['conversation_id']
            &&$p['context_version']==='live_contextual_ordinal_v1'&&$p['extractor_version']==='ru_archive_contextual_v2'
            &&self::positive((string)$p['fence'])&&self::moment($p['cutoff'])!==null
            &&self::moment($p['cutoff'])<=$now&&self::moment($p['cutoff'])<=(int)$p['observed_at_unix']
            &&self::moment($p['input_prediction'])!==null&&self::moment($p['cutoff'])<=self::moment($p['input_prediction'])
            &&self::moment($p['input_prediction'])<=(int)$p['observed_at_unix']&&$p['input_prediction']===$p['context_prediction'];
    }
    private function candidate(array $p,array $r,int $now):?array
    {
        if($r['goal']!=='substantive_interest'||!is_string($r['ref'])||preg_match('/\Ajournal:([1-9][0-9]{0,19})\z/D',$r['ref'],$match)!==1
            ||!is_string($r['epath'])||preg_match('/\A\$\.profile\.observation_input\.evidence\[(?:0|[1-9][0-9]{0,3})\]\z/D',$r['epath'])!==1
            ||!is_string($r['spath'])||preg_match('/\A\$\.source_references\[(?:0|[1-9][0-9]{0,2})\]\z/D',$r['spath'])!==1){
            $this->sources['unusable_semantic_candidates']++;return null;
        }
        if((int)($r['source_version']??0)>1||(int)($r['saved_version']??0)>1){$this->sources['revised_sources']++;return null;}
        $valid=(string)$r['source_version']==='1'&&(string)$r['saved_version']==='1'&&$r['kind']==='customer_message'&&$r['eid']==='message:'.$r['ref']&&(string)$r['reference_count']==='1'
            &&$r['rsource']==='message'&&$r['rid']===$r['ref']&&$r['eat']===$r['rat']&&$r['eavailable']===$r['ravailable']
            &&$r['eproject']==='anytour'&&$r['eplatform']===$p['platform']&&$r['ecustomer']===(string)$p['customer_key']&&$r['econversation']===(string)$p['conversation_id']
            &&$r['journal_id']===$match[1]&&$r['source_kind']==='message'&&$r['source_status']==='present'
            &&$r['source_observed']===$r['eavailable']&&self::decimalCompare($match[1],(string)$p['fence'])<=0
            &&(string)$r['saved_journal_id']===$match[1]&&$r['saved_kind']==='message'&&$r['saved_status']==='present'
            &&in_array($r['saved_basis'],['canonical_recording_unix_v1','database_session_timezone_to_unix'],true)
            &&$r['saved_project']==='anytour'&&$r['saved_platform']===$p['platform']&&$r['saved_customer']===(string)$p['customer_key']
            &&$r['saved_conversation']===(string)$p['conversation_id']&&$r['saved_direction']==='inbound'&&$r['saved_sender']==='customer'
            &&$r['saved_snapshot_kind']==='message'&&$r['saved_snapshot_id']===(string)$r['saved_source_id']
            &&$r['reference_source_id']===(string)$r['saved_source_id']&&(string)$r['native_id']===(string)$r['saved_source_id']
            &&(string)$r['native_conversation']===(string)$p['conversation_id']&&$r['native_channel']===$p['platform']
            &&$r['native_direction']==='inbound'&&$r['native_sender']==='customer';
        $at=self::moment($r['eat']);$available=self::moment($r['eavailable']);$cutoff=self::moment($p['cutoff']);
        if(!$valid||$at===null||$available===null||$at>$available||$available>$cutoff||$available>(int)$p['observed_at_unix']
            ||$available>$now||self::unix($r['saved_at'])!==$at||self::unix($r['saved_available'])!==$available){
            $this->sources['missing_clock']++;return null;
        }
        return ['at'=>$at,'journal'=>$match[1]];
    }
    private function profileFrom(bool $admitted=true,bool $scopeIndex=false):string
    {
        $from=$admitted?' FROM ('.$this->admittedScopeSql.') p':' FROM modeled_conversion_profile_versions p';
        // A bounded scope page must use its scope index rather than walk unrelated profile IDs.
        if(!$admitted&&$this->mysql&&$scopeIndex)$from.=' FORCE INDEX (ix_profile_scope_time)';
        return $from
            .' JOIN conversations c ON c.id=CAST(p.conversation_id AS '.($this->mysql?'UNSIGNED':'INTEGER').') AND c.customer_id=CAST(p.customer_key AS '.($this->mysql?'UNSIGNED':'INTEGER').') AND HEX(CAST(c.id AS '.($this->mysql?'CHAR':'TEXT').'))=HEX(p.conversation_id) AND HEX(CAST(c.customer_id AS '.($this->mysql?'CHAR':'TEXT').'))=HEX(p.customer_key) AND c.project_key=p.project_key AND c.channel=p.platform AND HEX(c.project_key)=HEX(p.project_key) AND HEX(c.channel)=HEX(p.platform)'
            .' JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND HEX(cc.project_key)=HEX(c.project_key) AND HEX(cc.channel)=HEX(c.channel)';
    }
    private function historicalScope(string $platform,array $scopes,string $fence,int $now):array
    {
        if(!$scopes||count($scopes)>64)throw new ModeledConversionAcquisitionReadFailure('source_shape_limit');
        $terms=[];$args=[];
        foreach($scopes as$scope){
            $terms[]='(p.project_key=? AND p.platform=? AND p.customer_key=? AND p.conversation_id=?'
                .' AND HEX(p.project_key)=HEX(?) AND HEX(p.platform)=HEX(?) AND HEX(p.customer_key)=HEX(?) AND HEX(p.conversation_id)=HEX(?))';
            $values=[$scope['project_key'],$scope['platform'],(string)$scope['customer_key'],(string)$scope['conversation_id']];
            array_push($args,...$values,...$values);
        }
        return [$this->profileScope($platform).' AND ('.implode(' OR ',$terms).') AND p.id<=? AND p.observed_at_unix<=?',
            [...$args,$fence,$now]];
    }
    private function profileScope(string $platform):string
    {
        return "p.project_key='anytour' AND HEX(p.project_key)=HEX('anytour') AND p.platform IN ('max','telegram') AND HEX(p.platform) IN (HEX('max'),HEX('telegram')) AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')
            .($platform==='all'?'':" AND HEX(p.platform)=HEX('".$platform."')");
    }
    private function admittedScopes(string $platform,string $fence,int $now):string
    {
        if(!preg_match('/\A[0-9]{1,20}\z/D',$fence))throw new ModeledConversionAcquisitionReadFailure('source_changed');
        $keys=['project_key','platform','customer_key','conversation_id'];
        $inner='SELECT '.implode(',',array_map(static fn(string $k):string=>'n.'.$k,$keys)).',MAX(n.id) AS latest_id'
            ." FROM modeled_conversion_profile_versions n WHERE n.project_key='anytour' AND HEX(n.project_key)=HEX('anytour')"
            ." AND n.platform IN ('max','telegram') AND HEX(n.platform) IN (HEX('max'),HEX('telegram'))"
            .($platform==='all'?'':" AND n.platform='".$platform."' AND HEX(n.platform)=HEX('".$platform."')")
            ." AND n.id<=$fence AND n.observed_at_unix<=$now GROUP BY ".implode(',',array_map(static fn(string $k):string=>'n.'.$k,$keys));
        $columns=implode(',',array_map(static fn(string $k):string=>'g.'.$k,$keys));
        $equal=implode(' AND ',array_map(static fn(string $k):string=>'lp.'.$k.'=g.'.$k.' AND HEX(lp.'.$k.')=HEX(g.'.$k.')',$keys));
        // MAX sees every version before access admission; latest denial cannot revive older facts.
        // The conservative input count has already proved that there are at most MAX_RECORDS
        // versions, hence at most that many latest scopes. LIMIT materializes only coordinates
        // and the small access value so type/length checks never reparse the full document.
        if($this->mysql){
            $access='SELECT '.$columns.',JSON_EXTRACT('.$this->safe('lp.profile_json').",".$this->lit('$.access_status').') AS access_value'
                .' FROM ('.$inner.') g JOIN modeled_conversion_profile_versions lp ON lp.id=g.latest_id AND '.$equal
                .' LIMIT '.self::MAX_RECORDS;
            $output=implode(',',array_map(static fn(string $k):string=>'a.'.$k,$keys));
            return 'SELECT '.$output.' FROM ('.$access.') a WHERE HEX('.$this->str('a.access_value',$this->lit('$')).")=HEX('allowed')"
                .' GROUP BY '.$output;
        }
        return 'SELECT '.$columns.' FROM ('.$inner.') g JOIN modeled_conversion_profile_versions lp ON lp.id=g.latest_id AND '.$equal
            .' WHERE HEX('.$this->str($this->safe('lp.profile_json'),$this->lit('$.access_status')).")=HEX('allowed')"
            .' GROUP BY '.$columns;
    }
    private function interestPaths(string $doc):string
    {
        if($this->mysql)return "JSON_SEARCH($doc,'all','substantive_interest',NULL,'$.contextual_signals.events[*].goal')";
        return "(SELECT json_group_array('$.contextual_signals.events['||j.key||'].goal') FROM json_each($doc,'$.contextual_signals.events') j WHERE json_extract(j.value,'$.goal')='substantive_interest')";
    }
    private function findPath(string $doc,string $ref,bool $source):string
    {
        if($this->mysql){
            $value=$source?"SUBSTR($ref,9)":$ref;
            $path=$source?'$.source_references[*].journal_id':'$.profile.observation_input.evidence[*].references[0].id';
            $find="JSON_SEARCH($doc,'all',$value,NULL,".$this->lit($path).')';
            $suffix=$source?'.journal_id':'.references[0].id';
            return "CASE WHEN JSON_TYPE($find)='STRING' THEN REPLACE(JSON_UNQUOTE($find),".$this->lit($suffix).",'') ELSE NULL END";
        }
        $path=$source?'$.source_references':'$.profile.observation_input.evidence';
        $field=$source?'$.journal_id':'$.references[0].id';
        $value=$source?"SUBSTR($ref,9)":$ref;
        return "(SELECT CASE WHEN COUNT(*)=1 THEN MIN(".$this->lit($path.'[')."||j.key||']') ELSE NULL END FROM json_each($doc,".$this->lit($path).") j WHERE json_extract(j.value,".$this->lit($field).")=$value)";
    }
    private function str(string $doc,string $path):string
    {
        $extract=$this->mysql?"JSON_EXTRACT($doc,$path)":"json_extract($doc,$path)";
        $type=$this->mysql?"JSON_TYPE($extract)='STRING'":"json_type($doc,$path)='text'";
        $value=$this->mysql?"JSON_UNQUOTE($extract)":$extract;
        return "CASE WHEN $type AND LENGTH($value)<=128 THEN $value ELSE NULL END";
    }
    private function identity(string $doc,string $path):string
    {
        $extract=$this->mysql?"JSON_EXTRACT($doc,$path)":"json_extract($doc,$path)";
        $type=$this->mysql?"JSON_TYPE($extract) IN ('STRING','INTEGER')":"json_type($doc,$path) IN ('text','integer')";
        $value=$this->mysql?"JSON_UNQUOTE($extract)":"CAST($extract AS TEXT)";
        return "CASE WHEN $type AND LENGTH($value)<=40 THEN $value ELSE NULL END";
    }
    private function integer(string $doc,string $path):string
    {
        $extract=$this->mysql?"JSON_EXTRACT($doc,$path)":"json_extract($doc,$path)";
        $type=$this->mysql?"JSON_TYPE($extract)='INTEGER'":"json_type($doc,$path)='integer'";
        return "CASE WHEN $type THEN $extract ELSE NULL END";
    }
    private function boolean(string $doc,string $path):string
    {
        return$this->mysql?"CASE JSON_UNQUOTE(JSON_EXTRACT($doc,$path)) WHEN 'true' THEN 1 WHEN 'false' THEN 0 ELSE NULL END"
            :"CASE json_type($doc,$path) WHEN 'true' THEN 1 WHEN 'false' THEN 0 ELSE NULL END";
    }
    private function arrayLength(string $doc,string $path):string
    {
        return$this->mysql?"CASE WHEN JSON_TYPE(JSON_EXTRACT($doc,$path))='ARRAY' THEN JSON_LENGTH(JSON_EXTRACT($doc,$path)) ELSE NULL END"
            :"CASE WHEN json_type($doc,$path)='array' THEN json_array_length($doc,$path) ELSE NULL END";
    }
    private function safe(string $column):string{return "CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END";}
    private function cat(string $a,string $b):string{return$this->mysql?"CONCAT($a,$b)":"($a||$b)";}
    private function lit(string $s):string{return"'".str_replace("'","''",$s)."'";}
    private function rows(string $sql,array $args=[]):array
    {
        $this->guard();$this->auth();$error=null;$rows=[];
        try{
            $this->guard();$remaining=(int)floor(($this->deadline-microtime(true))*1000);
            if($remaining<1)throw new ModeledConversionAcquisitionReadFailure('time_limit');
            $milliseconds=min(4500,$remaining);
            $this->queries++;$q=$this->pdo->prepare(preg_replace('/\ASELECT /','SELECT /*+ MAX_EXECUTION_TIME('.$milliseconds.') */ ',$sql,1));
            if(!$q||!$q->execute($args))throw new RuntimeException();
            while($row=$q->fetch(PDO::FETCH_ASSOC)){
                $this->records++;$this->bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR));
                $this->guard();$rows[]=$row;
            }
        }catch(ModeledConversionAcquisitionReadFailure $e){$error=$e;}
        catch(Throwable $e){$error=new ModeledConversionAcquisitionReadFailure('storage_unavailable');}
        $this->auth();if($error)throw$error;$this->guard();return$rows;
    }
    private function guard():void
    {
        if(microtime(true)>$this->deadline)throw new ModeledConversionAcquisitionReadFailure('time_limit');
        if($this->records>self::MAX_RECORDS)throw new ModeledConversionAcquisitionReadFailure('record_limit');
        if($this->bytes>self::MAX_BYTES)throw new ModeledConversionAcquisitionReadFailure('byte_limit');
    }
    private function auth():void{($this->authorize)();}
    private function filters(array $input,int $now):array
    {
        if($now<946684800||array_diff(array_keys($input),['date','period','platform']))throw new InvalidArgumentException('acquisition_filters');
        $today=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Kaliningrad'))->format('Y-m-d');
        $date=$input['date']??$today;$period=$input['period']??30;$platform=$input['platform']??'all';
        if(!is_string($date)||!is_int($period)||!in_array($period,[7,30,90],true)||!in_array($platform,['all','max','telegram'],true))
            throw new InvalidArgumentException('acquisition_filters');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Europe/Kaliningrad'));
        if(!$d||$d->format('Y-m-d')!==$date||$date>$today)throw new InvalidArgumentException('acquisition_filters');
        return ['date'=>$date,'period'=>$period,'platform'=>$platform];
    }
    private function window(array $f,int $now):array
    {
        $end=new DateTimeImmutable($f['date'].' 00:00:00',new DateTimeZone('Europe/Kaliningrad'));
        return ['from'=>self::iso($end->modify('-'.($f['period']-1).' days')->getTimestamp()),
            'until'=>self::iso(min($end->modify('+1 day')->getTimestamp(),$now)),'timezone'=>'Europe/Kaliningrad'];
    }
    private function endExclusive(string $date):int{return(new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Europe/Kaliningrad')))->modify('+1 day')->getTimestamp();}
    private function emptyPlatform(string $membership):array
    {
        return ['inquiries'=>0,'valid_pairs'=>0,'coverage_pct'=>null,'median_seconds'=>null,'p90_seconds'=>null,
            'bins'=>['under_1h'=>0,'from_1h_to_1d'=>0,'from_1d_to_7d'=>0,'from_7d_to_30d'=>0,'over_30d'=>0],
            'excluded'=>['join_missing'=>0,'join_after_inquiry'=>0,'join_ambiguous'=>0,'identity_ambiguous'=>0,'membership_unavailable'=>0],
            'membership_status'=>$membership];
    }
    private function complete(array $p,array $delays):array
    {
        sort($delays,SORT_NUMERIC);$n=count($delays);$p['valid_pairs']=$n;
        if($p['inquiries']>0&&$p['membership_status']==='saved_max_events_only')$p['coverage_pct']=round(100*$n/$p['inquiries'],2);
        if($n){$p['median_seconds']=$n%2?$delays[intdiv($n,2)]:(int)round(($delays[$n/2-1]+$delays[$n/2])/2);
            $p['p90_seconds']=$delays[(int)ceil($n*.9)-1];}
        foreach($delays as$d){$bin=$d<3600?'under_1h':($d<86400?'from_1h_to_1d':($d<604800?'from_1d_to_7d':($d<=2592000?'from_7d_to_30d':'over_30d')));$p['bins'][$bin]++;}
        if($n+array_sum($p['excluded'])!==$p['inquiries'])throw new ModeledConversionAcquisitionReadFailure('source_changed');
        return$p;
    }
    private static function moment($v):?int
    {
        if(!is_string($v)||strlen($v)!==20)return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$v,new DateTimeZone('UTC'));
        return$d&&$d->format('Y-m-d\TH:i:s\Z')===$v?$d->getTimestamp():null;
    }
    private static function unix($v):?int{return(is_int($v)||is_string($v)&&preg_match('/\A[0-9]{1,11}\z/D',$v)===1)&&(int)$v>=946684800&&(int)$v<=4102444800?(int)$v:null;}
    private static function positive($v):bool{return is_string($v)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$v)===1;}
    private static function decimalCompare(string $a,string $b):int{return strlen($a)<=>strlen($b)?:strcmp($a,$b);}
    private static function iso(int $at):string{return gmdate('Y-m-d\TH:i:s\Z',$at);}
}
