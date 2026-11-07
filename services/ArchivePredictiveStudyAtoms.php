<?php
declare(strict_types=1);

require_once __DIR__.'/ArchiveResearchSemanticExtractor.php';
require_once __DIR__.'/ModeledConversionTextSignals.php';

/** Pure compact research projection. Native positions stay in a private checkpoint; no text is retained. */
final class ArchivePredictiveStudyAtoms
{
    public const VERSION='archive_predictive_atoms_v1';
    private array $state;
    public function __construct(private string $salt,?array $state=null)
    {
        if(strlen($salt)!==32)throw new InvalidArgumentException('archive_atoms_salt');
        $this->state=$state??['v'=>1,'account_id'=>null,'chat_id'=>null,'seq'=>0,
            'semantic'=>ArchiveResearchSemanticExtractor::initialState()];
        $s=$this->state;
        if(count($s)!==5||array_diff(array_keys($s),['v','account_id','chat_id','seq','semantic'])
            ||$s['v']!==1||!is_int($s['seq'])||$s['seq']<0||$s['seq']>500000
            ||!is_array($s['semantic'])||!ArchiveResearchSemanticExtractor::validState($s['semantic'])
            ||(($s['account_id']===null)!==($s['chat_id']===null))
            ||($s['account_id']!==null&&(!self::id($s['account_id'])||!self::id($s['chat_id']))))
            throw new InvalidArgumentException('archive_atoms_state');
    }
    public function state():array{return $this->state;}
    public function consume(array $window):array
    {
        $a=$window['account_id']??null;$c=$window['chat_id']??null;$alias=$window['account_alias']??null;
        if(!self::id($a)||!self::id($c)||!is_string($alias)||preg_match('/\AA[1-9][0-9]{0,2}\z/D',$alias)!==1
            ||!is_array($window['messages']??null)||!array_is_list($window['messages'])||count($window['messages'])>100
            ||!is_bool($window['chat_window_complete']??null))throw new InvalidArgumentException('archive_atoms_window');
        if($this->state['account_id']!==$a||$this->state['chat_id']!==$c){
            $this->state=['v'=>1,'account_id'=>$a,'chat_id'=>$c,'seq'=>0,
                'semantic'=>ArchiveResearchSemanticExtractor::initialState()];
        }
        $case=hash_hmac('sha256',"archive-case-v1\0".$a."\0".$c,$this->salt);
        $group=hash_hmac('sha256',"archive-peer-v1\0".$c,$this->salt);
        $out=[];
        foreach($window['messages']as$m){
            if(!is_array($m)||!is_string($m['source_ref']??null)
                ||preg_match('/\Aarchive_message:[1-9][0-9]{0,19}\z/D',$m['source_ref'])!==1)
                throw new InvalidArgumentException('archive_atoms_message');
            $r=ArchiveResearchSemanticExtractor::step($m,$this->state['semantic']);
            $this->state['semantic']=$r['state'];$this->state['seq']++;
            if($this->state['seq']>500000)throw new RuntimeException('archive_atoms_case_limit');
            $f=['criteria_families'=>[],'explicit_comparison'=>false,'explicit_modification'=>false,
                'price_alternative_continued_search'=>false,'near_term_urgency'=>false,
                'long_range_planning'=>false,'explicit_decline'=>false,'customer_text_nonempty'=>false];
            if($r['status']==='scanned'&&($m['role']??null)==='customer'){
                $f=ModeledConversionTextSignals::scan($m['text']);
                $f['customer_text_nonempty']=trim($m['text'])!=='';
            }
            $goals=[];
            foreach($r['events']as$e)if(!in_array($e['goal'],$goals,true))$goals[]=$e['goal'];
            $hash=$m['record_sha256']??null;
            if($hash!==null&&(!is_string($hash)||preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1))
                throw new InvalidArgumentException('archive_atoms_record_hash');
            $out[]=['type'=>'message','case_key'=>$case,'group_key'=>$group,'account_alias'=>$alias,
                'ref'=>$m['source_ref'],'at'=>self::clock($m['created_at']??null),
                'version_at'=>self::clock($m['version_at']??null),'record_sha256'=>$hash,
                'role'=>in_array($m['role']??null,['customer','manager'],true)?$m['role']:'unknown',
                'status'=>$r['status'],'episode'=>$r['state']['e'],'boundary'=>$r['episode_boundary'],
                'product'=>$r['state']['p'],'goals'=>$goals,'flags'=>$r['flags'],'text_features'=>$f];
        }
        return $out;
    }
    private static function id(mixed $v):bool
    {return is_string($v)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$v)===1;}
    private static function clock(mixed $v):?int
    {return is_int($v)&&$v>0&&$v<=253402300799?$v:null;}
}
