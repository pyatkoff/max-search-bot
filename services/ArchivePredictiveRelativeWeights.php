<?php
declare(strict_types=1);

require_once __DIR__.'/ArchiveResearchSemanticExtractor.php';
require_once __DIR__.'/ModeledConversionTextSignals.php';

/** Read-only, project-specific relative weights. No training, activation or source access here. */
final class ArchivePredictiveRelativeWeights
{
    public const VERSION='archive_relative_weights_v1';
    public const TARGET='observed_retained_payment_evidence_30d_v1';
    public const PATH='/var/www/anytoour/data/private/archive-predictive-study/active-weights.json';
    public const FEATURES=['continued_meaningful_engagement','near_term_urgency','long_range_planning',
        'deferral','continued_offer_search','office_question','payment_terms_question'];
    public static function validate(array $p):bool
    {
        $keys=['schema','kind','project_key','run_id','scope_digest','source_fence','manifest_sha256',
            'protocol_sha256','extractor_sha256','text_features_sha256','target','complete','weights_eligible',
            'multipliers','global_odds_bounds','explicit_decline_guard','stage_priors_unchanged',
            'calibration_status','historical_availability','issued_at_unix'];
        if(count($p)!==count($keys)||array_diff(array_keys($p),$keys))return false;
        if(($p['schema']??null)!==1||($p['kind']??null)!=='archive_predictive_relative_weights_v1'
            ||($p['project_key']??null)!=='anytour'||($p['target']??null)!==self::TARGET
            ||($p['complete']??null)!==true||($p['weights_eligible']??null)!==true
            ||($p['stage_priors_unchanged']??null)!==true
            ||($p['calibration_status']??null)!=='not_calibrated_purchase_probability'
            ||($p['historical_availability']??null)!=='unknown'
            ||!self::run($p['run_id']??null)||!self::decimal($p['source_fence']??null)
            ||!is_int($p['issued_at_unix']??null)||$p['issued_at_unix']<946684800||$p['issued_at_unix']>time()+60
            ||!self::multipliers($p['multipliers']??null)
            ||!is_array($p['global_odds_bounds']??null)||array_values($p['global_odds_bounds'])!==$p['global_odds_bounds']
            ||count($p['global_odds_bounds'])!==2
            ||!self::number($p['global_odds_bounds'][0])||!self::number($p['global_odds_bounds'][1])
            ||(float)$p['global_odds_bounds'][0]!==0.25||(float)$p['global_odds_bounds'][1]!==1.3
            ||!self::number($p['explicit_decline_guard']??null)||(float)$p['explicit_decline_guard']!==0.25)return false;
        foreach(['scope_digest','manifest_sha256','protocol_sha256','extractor_sha256','text_features_sha256']as$k)
            if(!self::sha($p[$k]??null))return false;
        $extractor=hash_file('sha256',__DIR__.'/ArchiveResearchSemanticExtractor.php');
        $features=hash_file('sha256',__DIR__.'/ModeledConversionTextSignals.php');
        return is_string($extractor)&&is_string($features)
            &&hash_equals($extractor,$p['extractor_sha256'])&&hash_equals($features,$p['text_features_sha256']);
    }
    public static function load():?array{return self::loadFrom(self::PATH,1012);}
    /** Explicit test seam; production callers use the fixed-path load(). */
    public static function loadFrom(string $path,int $ownerUid):?array
    {
        $f=null;
        try{
            clearstatcache(true,$path);clearstatcache(true,dirname($path));
            $dir=@lstat(dirname($path));$stat=@lstat($path);
            if(!$dir||!$stat||is_link(dirname($path))||is_link($path)
                ||($dir['mode']&0170000)!==0040000||($stat['mode']&0170000)!==0100000
                ||$dir['uid']!==$ownerUid||$stat['uid']!==$ownerUid
                ||($dir['mode']&0077)!==0||($stat['mode']&0077)!==0||$stat['size']<2||$stat['size']>32768)return null;
            $f=@fopen($path,'rb');if(!$f||!flock($f,LOCK_SH|LOCK_NB))return null;
            $opened=fstat($f);
            if(!$opened||$opened['ino']!==$stat['ino']||$opened['dev']!==$stat['dev'])return null;
            $raw=stream_get_contents($f,32769);$after=fstat($f);
            if(!is_string($raw)||strlen($raw)!==$stat['size']||!$after
                ||$after['ino']!==$opened['ino']||$after['size']!==$opened['size']
                ||$after['mtime']!==$opened['mtime'])return null;
            $p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            return is_array($p)&&!array_is_list($p)&&self::validate($p)?self::capsule($p,hash('sha256',$raw)):null;
        }catch(Throwable$ignored){return null;}
        finally{if(is_resource($f)){flock($f,LOCK_UN);fclose($f);}}
    }
    public static function capsule(array $p,string $packageSha):?array
    {
        if(!self::validate($p)||!self::sha($packageSha))return null;
        $out=['version'=>self::VERSION,'target'=>self::TARGET,'run_id'=>$p['run_id'],'package_sha256'=>$packageSha,
            'source_fence'=>$p['source_fence'],'issued_at_unix'=>$p['issued_at_unix'],'protocol_sha256'=>$p['protocol_sha256'],
            'extractor_sha256'=>$p['extractor_sha256'],'text_features_sha256'=>$p['text_features_sha256'],
            'multipliers'=>array_map(static fn($v):float=>(float)$v,$p['multipliers'])];
        return self::validCapsule($out)?$out:null;
    }
    /** Historical snapshots keep their own source hashes even after future feature-code changes. */
    public static function validCapsule(mixed $p):bool
    {
        if(!is_array($p)||array_is_list($p)||count($p)!==10
            ||array_diff(array_keys($p),['version','target','run_id','package_sha256','source_fence',
                'protocol_sha256','extractor_sha256','text_features_sha256','multipliers','issued_at_unix'])
            ||($p['version']??null)!==self::VERSION||($p['target']??null)!==self::TARGET
            ||!self::run($p['run_id']??null)||!self::decimal($p['source_fence']??null)
            ||!is_int($p['issued_at_unix']??null)||$p['issued_at_unix']<946684800||$p['issued_at_unix']>253402300799
            ||!self::multipliers($p['multipliers']??null))return false;
        foreach(['package_sha256','protocol_sha256','extractor_sha256','text_features_sha256']as$k)if(!self::sha($p[$k]??null))return false;
        return true;
    }
    private static function multipliers(mixed $v):bool
    {
        if(!is_array($v)||count($v)!==count(self::FEATURES)||array_diff(array_keys($v),self::FEATURES))return false;
        foreach($v as$n)if(!self::number($n)||$n<0.5||$n>1.5)return false;
        return true;
    }
    private static function number(mixed $v):bool{return (is_int($v)||is_float($v))&&is_finite((float)$v);}
    private static function sha(mixed $v):bool{return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;}
    private static function run(mixed $v):bool{return is_string($v)&&preg_match('/\A[a-z0-9][a-z0-9_-]{0,79}\z/D',$v)===1;}
    private static function decimal(mixed $v):bool{return is_string($v)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$v)===1;}
}
