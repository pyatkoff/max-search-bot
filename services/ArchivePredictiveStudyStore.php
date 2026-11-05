<?php
declare(strict_types=1);

/** Private derived research files only. This class never opens application sources or a database. */
final class ArchivePredictiveStudyStore
{
    public const VERSION='archive_predictive_store_v1';
    public const DIRECTORY='/var/www/anytoour/data/private/archive-predictive-study';
    public const SUMMARY_BYTES=12288;
    public const CHECKPOINT_BYTES=262144;
    public const MANIFEST_BYTES=262144;
    public const CHUNK_BYTES=2097152;
    public const TOTAL_BYTES=536870912;
    private $lock=null;
    private ?string $runId=null;
    private ?array $checkpoint=null;

    public function __construct(private string $directory=self::DIRECTORY,private int $ownerUid=1012)
    {
        if($directory===''||$directory[0]!=='/'||str_contains($directory,"\0")||str_contains($directory,'/../')
            ||str_ends_with($directory,'/..')||str_contains($directory,'//')||str_ends_with($directory,'/'))
            self::fail('study_directory');
    }

    public static function summaryForScope(string $scopeDigest):array
    {
        return (new self())->readSummaryForScope($scopeDigest);
    }

    public function begin(string $runId,array $provenance):array
    {
        self::run($runId);self::provenance($provenance);
        if($this->lock!==null)self::fail('study_already_open');
        $this->root(true);$this->lock=$this->openLock(true);
        if(!flock($this->lock,LOCK_EX|LOCK_NB)){$this->close();self::fail('study_busy');}
        $this->runId=$runId;$run=$this->runDirectory();
        if(!file_exists($run)){if(!mkdir($run,0700))self::fail('study_directory');$this->syncDirectory($this->directory);}
        $this->privateDirectory($run);
        if(file_exists($run.'/checkpoint.json')){
            $c=$this->json($run.'/checkpoint.json',self::CHECKPOINT_BYTES);
            self::checkpoint($c,$runId);
            if(isset($c['lineage'])||file_exists($run.'/derive-intent.json')||is_link($run.'/derive-intent.json')){
                if(!isset($c['lineage'])||$this->json($run.'/derive-intent.json',8192)
                    !==['schema'=>1,'lineage'=>$c['lineage'],'provenance'=>$c['provenance']])self::fail('study_derive_state_changed');
            }
            $identity=$c['provenance'];unset($identity['captured_at_unix']);
            $current=$provenance;unset($current['captured_at_unix']);
            if($identity!==$current)self::fail('study_provenance_changed');
            foreach($c['chunks']as$chunk)$this->verifyChunk($chunk);
            $this->checkpoint=$c;
        }else{
            foreach(['manifest.json','analysis-intent.json','analysis-complete.json','analysis','derive-intent.json']as$name)
                if(file_exists($run.'/'.$name)||is_link($run.'/'.$name))self::fail('study_existing_intent');
            $this->checkpoint=['schema'=>1,'kind'=>'archive_predictive_checkpoint_v1','run_id'=>$runId,
                'provenance'=>$provenance,'salt_hex'=>bin2hex(random_bytes(32)),
                'source_fence'=>null,'scope_digest'=>null,'expected'=>null,'position'=>[],
                'atom_state'=>null,'chunks'=>[],'chain_sha256'=>str_repeat('0',64),
                'source_counts'=>['records'=>0,'desktop_import'=>0,'business_message'=>0,'read_status'=>[]],
                'chat_pairs'=>0,'last_pair'=>null,'complete'=>false,'total_chunk_bytes'=>0];
            $this->saveCheckpoint();
        }
        // Repair only the projection of an already durable checkpoint, never source or research evidence.
        $this->writeManifest();
        return $this->checkpoint;
    }

    /**
     * Reanalyse the exact completed derivative atoms under new analysis code.
     * The original run is only read. Existing or interrupted target directories are never reset.
     */
    public function deriveCompleted(string $parentRunId,string $newRunId,array $provenance,string $currentScopeDigest):array
    {
        self::run($parentRunId);self::run($newRunId);self::provenance($provenance);
        if($parentRunId===$newRunId)self::fail('study_derive_arguments');
        if(!self::digest($currentScopeDigest))self::fail('study_scope');
        if($this->lock!==null)self::fail('study_already_open');
        if(!$this->root(false))self::fail('study_derive_parent_incomplete');
        $this->lock=$this->openLock(false);
        if(!flock($this->lock,LOCK_EX|LOCK_NB)){$this->close();self::fail('study_busy');}
        try{
            $parent=$this->directory.'/'.$parentRunId;$target=$this->directory.'/'.$newRunId;
            if(file_exists($target)||is_link($target))self::fail('study_derive_target_exists');
            $this->privateDirectory($parent);$this->runId=$parentRunId;
            $c=$this->json($parent.'/checkpoint.json',self::CHECKPOINT_BYTES);self::checkpoint($c,$parentRunId);
            if(!$c['complete']||!array_key_exists('position',$c)||$c['position']!==null
                ||!file_exists($parent.'/analysis-complete.json'))self::fail('study_derive_parent_incomplete');
            if(($c['scope_digest']??null)!==$currentScopeDigest)self::fail('study_source_scope_changed');
            foreach(['services/ArchiveResearchReadService.php','services/ArchiveResearchSemanticExtractor.php',
                'services/ManagerAiTextRedactor.php','services/InternalOwnerDialogueScope.php',
                'services/ArchivePredictiveStudyAtoms.php','services/ModeledConversionTextSignals.php']as$file){
                $old=$c['provenance']['code_hashes'][$file]??null;$new=$provenance['code_hashes'][$file]??null;
                if(!self::digest($old)||!self::digest($new)||!hash_equals($old,$new))self::fail('study_derive_source_changed');
            }
            foreach(['extractor_sha256'=>'services/ArchiveResearchSemanticExtractor.php',
                'text_features_sha256'=>'services/ModeledConversionTextSignals.php']as$key=>$file)
                if($c['provenance'][$key]!==$c['provenance']['code_hashes'][$file]
                    ||$provenance[$key]!==$provenance['code_hashes'][$file])self::fail('study_derive_source_changed');
            $manifestRaw=$this->read($parent.'/manifest.json',self::MANIFEST_BYTES);
            if($manifestRaw!==self::encode(self::manifest($c)))self::fail('study_derive_parent_changed');
            $manifest=json_decode($manifestRaw,true,64,JSON_THROW_ON_ERROR);$manifestSha=hash('sha256',$manifestRaw);
            $intent=$this->json($parent.'/analysis-intent.json',4096);
            if(($intent['schema']??null)!==1||($intent['run_id']??null)!==$parentRunId
                ||($intent['state']??null)!=='started'||($intent['manifest_sha256']??null)!==$manifestSha
                ||($intent['analyzer_sha256']??null)!==$c['provenance']['analyzer_sha256'])
                self::fail('study_derive_parent_changed');
            $this->privateDirectory($parent.'/analysis');
            $summaryRaw=$this->read($parent.'/analysis/summary.json',self::SUMMARY_BYTES);
            $candidateRaw=$this->read($parent.'/analysis/candidate_weights.json',16384);
            $summary=json_decode($summaryRaw,true,64,JSON_THROW_ON_ERROR);
            $candidate=json_decode($candidateRaw,true,64,JSON_THROW_ON_ERROR);
            $this->validateBinding($summary,$manifest,$manifestSha,'archive_predictive_study_summary_v1');
            $this->validateBinding($candidate,$manifest,$manifestSha,'archive_predictive_relative_weights_v1');
            if(!is_bool($summary['weights_eligible']??null)||($candidate['weights_eligible']??null)!==$summary['weights_eligible'])
                self::fail('study_analysis_contract');
            $receipt=['schema'=>1,'run_id'=>$parentRunId,'manifest_sha256'=>$manifestSha,
                'summary_sha256'=>hash('sha256',$summaryRaw),'candidate_sha256'=>hash('sha256',$candidateRaw),
                'audits_sha256'=>$this->digestFile($parent.'/analysis/case_audits.jsonl',self::TOTAL_BYTES),
                'prefixes_sha256'=>$this->digestFile($parent.'/analysis/prefixes.jsonl',self::TOTAL_BYTES)];
            if($this->json($parent.'/analysis-complete.json',4096)!==$receipt)self::fail('study_derive_parent_changed');
            $records=0;$bytes=0;$chain=str_repeat('0',64);
            foreach($c['chunks']as$i=>$chunk){
                $this->verifyChunk($chunk);$raw=$this->read($parent.'/'.$chunk['file'],self::CHUNK_BYTES);
                if($chunk['file']!=='chunk_'.str_pad((string)($i+1),6,'0',STR_PAD_LEFT).'.ndjson'
                    ||substr_count($raw,"\n")!==$chunk['records'])self::fail('study_derive_parent_changed');
                $records+=$chunk['records'];$bytes+=strlen($raw);
                $chain=hash('sha256',$chain."\0".$chunk['file']."\0".$chunk['sha256']."\0".$chunk['records']);
            }
            if($records!==($c['source_counts']['records']??null)||$bytes!==$c['total_chunk_bytes']||$chain!==$c['chain_sha256']
                ||($c['source_counts']['desktop_import']??null)!==($c['expected']['desktop_records']??null)
                ||$c['chat_pairs']!==($c['expected']['account_chat_pairs']??null)
                ||($c['source_counts']['desktop_import']??-1)+($c['source_counts']['business_message']??-1)!==$records)
                self::fail('study_derive_parent_changed');
            $lineage=['kind'=>'completed_atoms_copy_v1','parent_run_id'=>$parentRunId,'parent_manifest_sha256'=>$manifestSha];
            $provenance['captured_at_unix']=$c['provenance']['captured_at_unix'];
            $derived=$c;$derived['run_id']=$newRunId;$derived['provenance']=$provenance;$derived['lineage']=$lineage;
            if(!mkdir($target,0700))self::fail('study_directory');$this->privateDirectory($target);$this->syncDirectory($this->directory);
            $this->atomic($target.'/derive-intent.json',self::encode(['schema'=>1,'lineage'=>$lineage,'provenance'=>$provenance]),false);
            foreach($c['chunks']as$chunk){
                $raw=$this->read($parent.'/'.$chunk['file'],self::CHUNK_BYTES);
                if(!hash_equals($chunk['sha256'],hash('sha256',$raw)))self::fail('study_chunk_changed');
                $this->atomic($target.'/'.$chunk['file'],$raw,false);
            }
            $this->runId=$newRunId;$this->checkpoint=$derived;$this->saveCheckpoint();$this->writeManifest();
            return$derived;
        }catch(Throwable $error){$this->close();throw$error;}
    }

    public function status(string $runId):array
    {
        $lock=null;
        try{
            self::run($runId);if(!$this->root(false))return ['ok'=>true,'exists'=>false];
            $lock=$this->openLock(false);
            if(!flock($lock,LOCK_SH|LOCK_NB))return ['ok'=>false,'reason'=>'study_busy'];
            $run=$this->directory.'/'.$runId;
            if(!file_exists($run)&&!is_link($run))return ['ok'=>true,'exists'=>false];
            $this->privateDirectory($run);$c=$this->json($run.'/checkpoint.json',self::CHECKPOINT_BYTES);
            self::checkpoint($c,$runId);
            return ['ok'=>true,'exists'=>true,'capture_complete'=>$c['complete'],
                'analysis_complete'=>file_exists($run.'/analysis-complete.json')];
        }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
    }

    public function currentCheckpoint():array
    {
        if($this->checkpoint===null)self::fail('study_not_open');
        return $this->checkpoint;
    }

    public function commitPage(array $page,array $atoms,array $atomState):array
    {
        $c=$this->currentCheckpoint();
        if($c['complete'])self::fail('study_capture_complete');
        if(($page['project_key']??null)!=='anytour'||!self::decimal($page['source_fence']??null,true)
            ||!self::digest($page['scope_digest']??null)||!is_array($page['windows']??null)
            ||!array_is_list($page['windows'])||!is_bool($page['has_more']??null)
            ||!is_int($page['returned_records']??null)||!array_is_list($atoms)
            ||$page['returned_records']!==count($atoms))self::fail('study_page_contract');
        if(strlen(self::encode($atomState))>65536)self::fail('study_atom_state_limit');
        if($c['source_fence']===null){
            $expected=$page['expected']??null;
            if(!is_array($expected)||array_diff(array_keys($expected),['desktop_records','account_chat_pairs'])
                ||count($expected)!==2||!is_int($expected['desktop_records'])||$expected['desktop_records']<0
                ||!is_int($expected['account_chat_pairs'])||$expected['account_chat_pairs']<0)
                self::fail('study_expected_contract');
            $c['source_fence']=$page['source_fence'];$c['scope_digest']=$page['scope_digest'];$c['expected']=$expected;
        }elseif($c['source_fence']!==$page['source_fence']||$c['scope_digest']!==$page['scope_digest'])
            self::fail('study_source_scope_changed');
        $records=0;
        foreach($page['windows']as$window){
            if(!is_array($window)||!self::decimal($window['account_id']??null)
                ||!self::decimal($window['chat_id']??null)||!is_array($window['messages']??null)
                ||!array_is_list($window['messages']))self::fail('study_window_contract');
            $pair=[$window['account_id'],$window['chat_id']];
            if($c['last_pair']!==$pair){
                if($c['last_pair']!==null&&self::pairCompare($pair,$c['last_pair'])<=0)self::fail('study_source_order');
                $c['chat_pairs']++;$c['last_pair']=$pair;
            }
            foreach($window['messages']as$m){
                if(!is_array($m)||!in_array($m['source_kind']??null,['desktop_import','business_message'],true)
                    ||!is_string($m['read_status']??null)||preg_match('/\A[a-z_]{1,64}\z/D',$m['read_status'])!==1)
                    self::fail('study_message_contract');
                $records++;$c['source_counts']['records']++;$c['source_counts'][$m['source_kind']]++;
                $s=$m['read_status'];$c['source_counts']['read_status'][$s]=($c['source_counts']['read_status'][$s]??0)+1;
            }
        }
        if($records!==count($atoms))self::fail('study_page_reconciliation');
        $next=$page['next_position']??null;
        if($page['has_more']!==($next!==null)||($next!==null&&!is_array($next))
            ||($page['has_more']&&$records===0))self::fail('study_position_contract');
        if($next!==null){
            if(count($next)!==5||array_diff(array_keys($next),['f','a','c','n','scope_digest'])
                ||($next['f']??null)!==$c['source_fence']||($next['scope_digest']??null)!==$c['scope_digest']
                ||!self::decimal($next['a']??null)||!self::decimal($next['c']??null)||!self::decimal($next['n']??null)
                ||[$next['a'],$next['c']]!==$c['last_pair'])self::fail('study_position_contract');
        }
        $raw='';
        foreach($atoms as$atom){
            if(!is_array($atom)||array_is_list($atom)||($atom['type']??null)!=='message')self::fail('study_atom_contract');
            self::noSourceText($atom);$line=self::encode($atom);
            if(strlen($line)>16384)self::fail('study_atom_limit');
            $raw.=$line."\n";
        }
        if(strlen($raw)>self::CHUNK_BYTES||$c['total_chunk_bytes']+strlen($raw)>self::TOTAL_BYTES)
            self::fail('study_chunk_limit');
        if($atoms){
            $file='chunk_'.str_pad((string)(count($c['chunks'])+1),6,'0',STR_PAD_LEFT).'.ndjson';
            $chunk=['file'=>$file,'sha256'=>hash('sha256',$raw),'records'=>count($atoms)];
            $path=$this->runDirectory().'/'.$file;
            // A crash may leave one uncommitted immutable chunk. Reuse only byte-identical evidence.
            if(file_exists($path)||is_link($path)){
                if(!hash_equals($chunk['sha256'],hash('sha256',$this->read($path,self::CHUNK_BYTES))))
                    self::fail('study_orphan_conflict');
                // The earlier process may have stopped between rename and directory fsync.
                $this->syncDirectory($this->runDirectory());
            }else $this->atomic($path,$raw,false);
            $c['chunks'][]=$chunk;$c['total_chunk_bytes']+=strlen($raw);
            $c['chain_sha256']=hash('sha256',$c['chain_sha256']."\0".$file."\0".$chunk['sha256']."\0".$chunk['records']);
        }
        $c['position']=$next;$c['atom_state']=$atomState;$c['complete']=!$page['has_more'];
        if($c['complete']&&($c['source_counts']['desktop_import']!==$c['expected']['desktop_records']
            ||$c['chat_pairs']!==$c['expected']['account_chat_pairs']))self::fail('study_corpus_reconciliation');
        $this->checkpoint=$c;
        // Immutable derived bytes are durable before the position is allowed to advance.
        $this->saveCheckpoint();$this->writeManifest();
        return $c;
    }

    public function claimAnalysis():bool
    {
        $c=$this->currentCheckpoint();if(!$c['complete'])self::fail('study_capture_incomplete');
        $path=$this->runDirectory().'/analysis-intent.json';
        if(file_exists($path)||is_link($path)){
            $intent=$this->json($path,4096);
            if(($intent['manifest_sha256']??null)!==hash('sha256',$this->read($this->runDirectory().'/manifest.json',self::MANIFEST_BYTES)))
                self::fail('study_analysis_intent_changed');
            if(file_exists($this->runDirectory().'/analysis-complete.json'))return false;
            self::fail('study_analysis_unknown');
        }
        if(file_exists($this->runDirectory().'/analysis')||is_link($this->runDirectory().'/analysis'))
            self::fail('study_analysis_unknown');
        $this->atomic($path,self::encode(['schema'=>1,'run_id'=>$this->runId,
            'manifest_sha256'=>hash('sha256',$this->read($this->runDirectory().'/manifest.json',self::MANIFEST_BYTES)),
            'analyzer_sha256'=>$c['provenance']['analyzer_sha256'],'state'=>'started','started_at_unix'=>time()]),false);
        return true;
    }

    public function publishAnalysis():array
    {
        $c=$this->currentCheckpoint();if(!$c['complete'])self::fail('study_capture_incomplete');
        $run=$this->runDirectory();$this->privateDirectory($run.'/analysis');
        $manifestRaw=$this->read($run.'/manifest.json',self::MANIFEST_BYTES);$manifest=json_decode($manifestRaw,true,64,JSON_THROW_ON_ERROR);
        $summaryRaw=$this->read($run.'/analysis/summary.json',self::SUMMARY_BYTES);
        $summary=json_decode($summaryRaw,true,64,JSON_THROW_ON_ERROR);
        $candidateRaw=$this->read($run.'/analysis/candidate_weights.json',16384);
        $candidate=json_decode($candidateRaw,true,64,JSON_THROW_ON_ERROR);
        $this->validateBinding($summary,$manifest,hash('sha256',$manifestRaw),'archive_predictive_study_summary_v1');
        $this->validateBinding($candidate,$manifest,hash('sha256',$manifestRaw),'archive_predictive_relative_weights_v1');
        if(!is_bool($summary['weights_eligible']??null)||($candidate['weights_eligible']??null)!==$summary['weights_eligible'])
            self::fail('study_analysis_contract');
        self::noSummaryIdentity($summary);
        $receipt=['schema'=>1,'run_id'=>$this->runId,'manifest_sha256'=>hash('sha256',$manifestRaw),
            'summary_sha256'=>hash('sha256',$summaryRaw),'candidate_sha256'=>hash('sha256',$candidateRaw),
            'audits_sha256'=>$this->digestFile($run.'/analysis/case_audits.jsonl',self::TOTAL_BYTES),
            'prefixes_sha256'=>$this->digestFile($run.'/analysis/prefixes.jsonl',self::TOTAL_BYTES)];
        // Publish completion only after all analyzer output names and its directory entry are durable.
        $this->syncDirectory($run.'/analysis');$this->syncDirectory($run);
        $complete=$run.'/analysis-complete.json';
        if(file_exists($complete)){
            if($this->json($complete,4096)!==$receipt)self::fail('study_analysis_receipt_changed');
            if(file_exists($this->directory.'/latest.json')){
                $latest=$this->json($this->directory.'/latest.json',4096);
                if(($latest['run_id']??null)!==$this->runId)
                    return ['ok'=>true,'completed'=>true,'private_outputs_published'=>false,'weights_eligible'=>$summary['weights_eligible']];
                if($latest!==$receipt)self::fail('study_analysis_receipt_changed');
            }
        }else $this->atomic($complete,self::encode($receipt),false);
        $this->atomic($this->directory.'/latest.json',self::encode($receipt),true);
        return ['ok'=>true,'completed'=>true,'private_outputs_published'=>true,'weights_eligible'=>$summary['weights_eligible']];
    }

    public function readSummaryForScope(string $scopeDigest):array
    {
        $lock=null;
        try{
            if(!self::digest($scopeDigest))self::fail('study_scope');
            if(!$this->root(false))return ['available'=>false,'status'=>'not_built'];
            $lock=$this->openLock(false);
            if(!flock($lock,LOCK_SH|LOCK_NB))return ['available'=>false,'status'=>'busy'];
            if(!file_exists($this->directory.'/latest.json'))return ['available'=>false,'status'=>'not_built'];
            $latest=$this->json($this->directory.'/latest.json',4096);self::run($latest['run_id']??null);
            foreach(['manifest_sha256','summary_sha256','candidate_sha256','audits_sha256','prefixes_sha256']as$k)
                if(!self::digest($latest[$k]??null))self::fail('study_latest_contract');
            $run=$this->directory.'/'.$latest['run_id'];$this->privateDirectory($run);$this->privateDirectory($run.'/analysis');
            $manifestRaw=$this->read($run.'/manifest.json',self::MANIFEST_BYTES);
            if(!self::digest($latest['manifest_sha256']??null)||!hash_equals($latest['manifest_sha256'],hash('sha256',$manifestRaw)))
                self::fail('study_manifest_changed');
            $manifest=json_decode($manifestRaw,true,64,JSON_THROW_ON_ERROR);
            if(($manifest['scope_digest']??null)!==$scopeDigest)return ['available'=>false,'status'=>'scope_changed'];
            $raw=$this->read($run.'/analysis/summary.json',self::SUMMARY_BYTES);
            if(!self::digest($latest['summary_sha256']??null)||!hash_equals($latest['summary_sha256'],hash('sha256',$raw)))
                self::fail('study_summary_changed');
            $summary=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            $this->validateBinding($summary,$manifest,$latest['manifest_sha256'],'archive_predictive_study_summary_v1');
            self::noSummaryIdentity($summary);
            $result=['available'=>true,'status'=>'complete','summary_sha256'=>$latest['summary_sha256'],
                'candidate_sha256'=>$latest['candidate_sha256']]+$summary;
            if(strlen(self::encode($result))>self::SUMMARY_BYTES)self::fail('study_summary_limit');
            return $result;
        }catch(Throwable $e){return ['available'=>false,'status'=>'unavailable'];}
        finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
    }

    public function activate(string $reviewedCandidateSha,callable $validator):array
    {
        if(!self::digest($reviewedCandidateSha))self::fail('study_review_hash');
        $c=$this->currentCheckpoint();if(!$c['complete'])self::fail('study_capture_incomplete');
        $receipt=$this->json($this->runDirectory().'/analysis-complete.json',4096);
        if(($receipt['candidate_sha256']??null)!==$reviewedCandidateSha)self::fail('study_review_hash');
        $raw=$this->read($this->runDirectory().'/analysis/candidate_weights.json',16384);
        if(!hash_equals($reviewedCandidateSha,hash('sha256',$raw)))self::fail('study_candidate_changed');
        $candidate=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        $manifestRaw=$this->read($this->runDirectory().'/manifest.json',self::MANIFEST_BYTES);
        $manifest=json_decode($manifestRaw,true,64,JSON_THROW_ON_ERROR);
        $this->validateBinding($candidate,$manifest,hash('sha256',$manifestRaw),'archive_predictive_relative_weights_v1');
        if(($candidate['weights_eligible']??null)!==true||$validator($candidate)!==true)self::fail('study_weights_not_eligible');
        $path=$this->directory.'/active-weights.json';
        if(file_exists($path)||is_link($path)){
            $old=$this->read($path,16384);
            if(hash_equals($reviewedCandidateSha,hash('sha256',$old)))
                return ['ok'=>true,'activated'=>true,'already_active'=>true];
        }
        $this->atomic($path,$raw,true);
        if(!hash_equals($reviewedCandidateSha,hash('sha256',$this->read($path,16384))))self::fail('study_activation_readback');
        return ['ok'=>true,'activated'=>true,'already_active'=>false];
    }

    public function path():string{return $this->runDirectory();}

    public function close():void
    {
        if(is_resource($this->lock)){flock($this->lock,LOCK_UN);fclose($this->lock);}
        $this->lock=null;$this->runId=null;$this->checkpoint=null;
    }
    public function __destruct(){$this->close();}

    private static function manifest(array $c):array
    {
        $p=$c['provenance'];
        $m=['schema'=>1,'kind'=>'archive_predictive_study_v1','project_key'=>'anytour','run_id'=>$c['run_id'],
            'source_commit'=>$p['source_commit'],'scope_digest'=>$c['scope_digest'],'source_fence'=>$c['source_fence'],
            'extractor_sha256'=>$p['extractor_sha256'],'text_features_sha256'=>$p['text_features_sha256'],
            'analyzer_sha256'=>$p['analyzer_sha256'],'rule_revision'=>$p['rule_revision'],
            'captured_at_unix'=>$p['captured_at_unix'],'code_hashes'=>$p['code_hashes'],'complete'=>$c['complete'],
            'chunks'=>$c['chunks'],'expected'=>$c['expected'],'chain_sha256'=>$c['chain_sha256'],
            'coverage'=>['source_history_complete'=>false,'historical_availability'=>'unknown','immutable_snapshot'=>false,
                'source_counts'=>$c['source_counts'],'account_chat_pairs'=>$c['chat_pairs']]];
        if(isset($c['lineage']))$m['lineage']=$c['lineage'];
        return$m;
    }

    private function writeManifest():void
    {
        $raw=self::encode(self::manifest($this->currentCheckpoint()));
        if(strlen($raw)>self::MANIFEST_BYTES)self::fail('study_manifest_limit');
        $this->atomic($this->runDirectory().'/manifest.json',$raw,true);
    }

    private function saveCheckpoint():void
    {
        $raw=self::encode($this->currentCheckpoint());if(strlen($raw)>self::CHECKPOINT_BYTES)self::fail('study_checkpoint_limit');
        $this->atomic($this->runDirectory().'/checkpoint.json',$raw,true);
    }
    private function verifyChunk(array $chunk):void
    {
        if(count($chunk)!==3||preg_match('/\Achunk_[0-9]{6}\.ndjson\z/D',$chunk['file']??'')!==1
            ||!self::digest($chunk['sha256']??null)||!is_int($chunk['records']??null)||$chunk['records']<1)
            self::fail('study_chunk_contract');
        if(!hash_equals($chunk['sha256'],hash('sha256',$this->read($this->runDirectory().'/'.$chunk['file'],self::CHUNK_BYTES))))
            self::fail('study_chunk_changed');
    }
    private function validateBinding(array $value,array $manifest,string $manifestSha,string $kind):void
    {
        if(($manifest['complete']??null)!==true||($manifest['schema']??null)!==1
            ||($manifest['project_key']??null)!=='anytour'||($manifest['kind']??null)!=='archive_predictive_study_v1'
            ||($value['schema']??null)!==1||($value['kind']??null)!==$kind||($value['project_key']??null)!=='anytour'
            ||($value['complete']??null)!==true||($value['manifest_sha256']??null)!==$manifestSha
            ||!self::digest($value['protocol_sha256']??null))self::fail('study_analysis_binding');
        foreach(['run_id','scope_digest','source_fence']as$k)if(($value[$k]??null)!==($manifest[$k]??null))self::fail('study_analysis_binding');
        if($kind==='archive_predictive_relative_weights_v1'){
            foreach(['extractor_sha256','text_features_sha256']as$k)
                if(($value[$k]??null)!==($manifest[$k]??null))self::fail('study_analysis_binding');
        }
    }

    private function root(bool $create):bool
    {
        if(!$create&&!file_exists($this->directory)&&!is_link($this->directory))return false;
        $this->ancestors(dirname($this->directory),$create);
        if(!file_exists($this->directory)&&!is_link($this->directory)){
            if(!$create)return false;
            if(!mkdir($this->directory,0700))self::fail('study_directory');
            $this->syncDirectory(dirname($this->directory));
        }
        $this->privateDirectory($this->directory);return true;
    }
    private function ancestors(string $path,bool $create):void
    {
        $parts=explode('/',trim($path,'/'));$prefix='';
        foreach($parts as$part){
            $prefix.='/'.$part;$s=@lstat($prefix);
            if($s===false){
                if($create&&$this->directory===self::DIRECTORY&&$prefix==='/var/www/anytoour/data/private'){
                    if(!mkdir($prefix,0700))self::fail('study_directory');
                    $this->syncDirectory(dirname($prefix));$s=@lstat($prefix);
                }else self::fail('study_directory');
            }
            if(!is_array($s)||($s['mode']&0170000)!==0040000||is_link($prefix)
                ||(($s['mode']&0002)!==0&&($s['mode']&01000)===0)
                ||!in_array($s['uid'],[0,$this->ownerUid],true))self::fail('study_directory');
        }
    }
    private function privateDirectory(string $path):void
    {
        $s=@lstat($path);
        if(!is_array($s)||($s['mode']&0170000)!==0040000||($s['mode']&0077)!==0
            ||$s['uid']!==$this->ownerUid||is_link($path))self::fail('study_directory');
    }
    private function runDirectory():string
    {
        if($this->runId===null)self::fail('study_not_open');
        return $this->directory.'/'.$this->runId;
    }
    private function openLock(bool $create)
    {
        $path=$this->directory.'/lock';
        if(!file_exists($path)&&!is_link($path)){
            if(!$create)self::fail('study_lock_missing');
            $file=@fopen($path,'x+b');if(!is_resource($file))self::fail('study_lock');
            if(!chmod($path,0600)){fclose($file);self::fail('study_lock');}
        }else $file=@fopen($path,$create?'r+b':'rb');
        if(!is_resource($file))self::fail('study_lock');
        $this->sameFile($path,$file,4096);return$file;
    }
    private function read(string $path,int $limit):string
    {
        $this->regular($path,$limit);$file=@fopen($path,'rb');
        if(!is_resource($file))self::fail('study_file');
        try{
            $this->sameFile($path,$file,$limit);$raw=stream_get_contents($file,$limit+1);
            if(!is_string($raw)||strlen($raw)>$limit)self::fail('study_file_limit');
            $this->sameFile($path,$file,$limit);return$raw;
        }finally{fclose($file);}
    }
    private function digestFile(string $path,int $limit):string
    {
        $this->regular($path,$limit);$file=@fopen($path,'rb');if(!is_resource($file))self::fail('study_file');
        try{
            $this->sameFile($path,$file,$limit);$context=hash_init('sha256');
            $bytes=hash_update_stream($context,$file);$stat=fstat($file);
            if(!is_int($bytes)||!is_array($stat)||$bytes!==$stat['size'])self::fail('study_file_changed');
            $this->sameFile($path,$file,$limit);return hash_final($context);
        }finally{fclose($file);}
    }
    private function json(string $path,int $limit):array
    {
        $v=json_decode($this->read($path,$limit),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($v)||array_is_list($v))self::fail('study_json');return$v;
    }
    private function regular(string $path,int $limit):array
    {
        $s=@lstat($path);
        if(!is_array($s)||($s['mode']&0170000)!==0100000||($s['mode']&0077)!==0
            ||$s['uid']!==$this->ownerUid||$s['nlink']!==1||$s['size']>$limit||is_link($path))self::fail('study_file');
        return$s;
    }
    private function sameFile(string $path,$file,int $limit):void
    {
        $a=$this->regular($path,$limit);$b=fstat($file);
        if(!is_array($b)||$a['dev']!==$b['dev']||$a['ino']!==$b['ino']||$a['size']!==$b['size'])
            self::fail('study_file_changed');
    }
    private function atomic(string $path,string $raw,bool $replace):void
    {
        if(file_exists($path)||is_link($path)){
            if(!$replace)self::fail('study_file_exists');
            $this->regular($path,max(self::CHUNK_BYTES,self::MANIFEST_BYTES));
        }
        $tmp=dirname($path).'/.tmp-'.bin2hex(random_bytes(16));$file=@fopen($tmp,'x+b');
        if(!is_resource($file))self::fail('study_file');
        try{
            if(!chmod($tmp,0600))self::fail('study_file');
            $at=0;$n=strlen($raw);
            while($at<$n){$written=fwrite($file,substr($raw,$at));if(!is_int($written)||$written<1)self::fail('study_file');$at+=$written;}
            if(!function_exists('fsync')||!fflush($file)||!fsync($file))self::fail('study_file');
            $this->sameFile($tmp,$file,max($n,1));
            if(!rename($tmp,$path))self::fail('study_file');
            $this->sameFile($path,$file,max($n,1));
            $this->syncDirectory(dirname($path));
        }finally{fclose($file);if(file_exists($tmp))@unlink($tmp);}
    }

    /** Linux plain-file streams support directory descriptors; unsupported fsync fails closed. */
    private function syncDirectory(string $path):void
    {
        clearstatcache(true,$path);$a=@lstat($path);
        if(!is_array($a)||($a['mode']&0170000)!==0040000||is_link($path)
            ||!in_array($a['uid'],[0,$this->ownerUid],true)
            ||(($a['mode']&0002)!==0&&($a['mode']&01000)===0)||!function_exists('fsync'))
            self::fail('study_directory_sync');
        $directory=@fopen($path,'rb');
        if(!is_resource($directory))self::fail('study_directory_sync');
        try{
            $b=fstat($directory);
            if(!is_array($b)||($b['mode']&0170000)!==0040000||$a['dev']!==$b['dev']||$a['ino']!==$b['ino']
                ||!@fsync($directory))self::fail('study_directory_sync');
            clearstatcache(true,$path);$after=@lstat($path);
            if(!is_array($after)||($after['mode']&0170000)!==0040000||is_link($path)
                ||$a['dev']!==$after['dev']||$a['ino']!==$after['ino'])self::fail('study_directory_sync');
        }finally{fclose($directory);}
    }

    private static function provenance(array $p):void
    {
        if(array_diff(array_keys($p),['source_commit','extractor_sha256','text_features_sha256','analyzer_sha256',
            'rule_revision','captured_at_unix','code_hashes'])||count($p)!==7
            ||!is_string($p['source_commit']??null)||preg_match('/\A[a-f0-9]{40}\z/D',$p['source_commit'])!==1
            ||!self::digest($p['extractor_sha256']??null)||!self::digest($p['text_features_sha256']??null)
            ||!self::digest($p['analyzer_sha256']??null)||($p['rule_revision']??null)!==3
            ||!is_int($p['captured_at_unix']??null)||$p['captured_at_unix']<946684800
            ||!is_array($p['code_hashes']??null)||array_is_list($p['code_hashes'])||count($p['code_hashes'])>20)
            self::fail('study_provenance');
        foreach($p['code_hashes']as$name=>$hash)if(!is_string($name)||preg_match('/\A(?:services|tools)\/[A-Za-z0-9_]+\.(?:php|py)\z/D',$name)!==1
            ||!self::digest($hash))self::fail('study_provenance');
    }
    private static function checkpoint(array $c,string $runId):void
    {
        if(($c['schema']??null)!==1||($c['kind']??null)!=='archive_predictive_checkpoint_v1'
            ||($c['run_id']??null)!==$runId||!is_array($c['provenance']??null)
            ||!self::digest($c['salt_hex']??null)||!is_array($c['chunks']??null)||!array_is_list($c['chunks'])
            ||!is_array($c['source_counts']??null)||!is_bool($c['complete']??null)||!is_int($c['chat_pairs']??null)
            ||!is_int($c['total_chunk_bytes']??null)||$c['total_chunk_bytes']<0||$c['total_chunk_bytes']>self::TOTAL_BYTES
            ||!self::digest($c['chain_sha256']??null))self::fail('study_checkpoint');
        self::provenance($c['provenance']);
        if(array_key_exists('lineage',$c)){
            $l=$c['lineage'];
            if(!is_array($l)||count($l)!==3||array_diff(array_keys($l),['kind','parent_run_id','parent_manifest_sha256'])
                ||($l['kind']??null)!=='completed_atoms_copy_v1'||!self::digest($l['parent_manifest_sha256']??null))
                self::fail('study_derive_state_changed');
            self::run($l['parent_run_id']??null);
            if($l['parent_run_id']===$runId)self::fail('study_derive_state_changed');
        }
    }
    private static function noSourceText(mixed $value):void
    {
        if(!is_array($value))return;
        foreach($value as$k=>$v){
            if(is_string($k)&&in_array(strtolower($k),['text','fragment','record_json','html','url','client_id','yclid','chat_id','account_id','native_message_id'],true))
                self::fail('study_raw_source_refused');
            self::noSourceText($v);
        }
    }
    private static function noSummaryIdentity(mixed $value):void
    {
        if(is_string($value)&&preg_match('/(?:archive_message|journal):[0-9]+/',$value)===1)self::fail('study_summary_identity');
        if(!is_array($value))return;
        foreach($value as$k=>$v){
            if(is_string($k)&&in_array(strtolower($k),['text','fragment','record_json','html','url','client_id','yclid','chat_id','account_id','native_message_id','case_key','group_key','source_ref'],true))
                self::fail('study_summary_identity');
            self::noSummaryIdentity($v);
        }
    }
    private static function pairCompare(array $a,array $b):int
    {
        foreach([0,1]as$i){$n=strlen($a[$i])<=>strlen($b[$i]);if($n!==0)return$n;$n=strcmp($a[$i],$b[$i]);if($n!==0)return$n;}return 0;
    }
    private static function decimal(mixed $v,bool $zero=false):bool{return is_string($v)&&preg_match($zero?'/\A(?:0|[1-9][0-9]{0,19})\z/D':'/\A[1-9][0-9]{0,19}\z/D',$v)===1;}
    private static function digest(mixed $v):bool{return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;}
    private static function run(mixed $v):void{if(!is_string($v)||preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D',$v)!==1)self::fail('study_run');}
    private static function encode(array $v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function fail(string $reason):never{throw new RuntimeException($reason);}
}
