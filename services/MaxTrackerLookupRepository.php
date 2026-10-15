<?php
declare(strict_types=1);
require_once __DIR__.'/MaxTrackerLookupProtocol.php';
require_once __DIR__.'/MaxSubscriptionAttributionPolicy.php';

/** Direct SELECT-only access on the retained Bitrix host, without Bitrix bootstrap. */
final class MaxTrackerLookupRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo=$pdo; }

    public static function fromSettings(string $settingsPath): self
    {
        if (!is_file($settingsPath)) throw new RuntimeException('tracker_unavailable');
        $level=ob_get_level(); ob_start();
        try { $s=require $settingsPath; } finally { while(ob_get_level()>$level)ob_end_clean(); }
        $d=$s['connections']['value']['default']??null;
        if (!is_array($d)) throw new RuntimeException('tracker_unavailable');
        $host=(string)($d['host']??'localhost'); $name=(string)($d['database']??'');
        if (strpbrk($host.$name,";\r\n")!==false) throw new RuntimeException('tracker_unavailable');
        $pdo=new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',(string)($d['login']??''),(string)($d['password']??''),
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
        return new self($pdo);
    }

    public function lookup(string $counterId, array $userIds): array
    {
        MaxTrackerLookupProtocol::body($counterId,$userIds,1,str_repeat('0',32));
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $this->pdo->inTransaction()) throw new RuntimeException('tracker_readonly_required');
        $this->pdo->exec('SET TRANSACTION READ ONLY');
        $this->pdo->beginTransaction();
        try {
            // UF_PLATFORM in this MAX-only table is a device platform (android/ios),
            // not a messenger namespace. MAX identity comes from this store itself.
            $sql="SELECT s.ID,s.UF_CLIENT_ID,s.UF_DATE FROM max_sub_links s WHERE s.UF_CHAT_ID=?"
                ." AND s.UF_CLIENT_ID REGEXP '^[1-9][0-9]{0,39}$' AND s.UF_CLIENT_ID NOT REGEXP '[^0-9]'"
                ." AND EXISTS (SELECT 1 FROM b_iblock_element_property ep JOIN b_iblock_property p ON p.ID=ep.IBLOCK_PROPERTY_ID"
                ." WHERE p.IBLOCK_ID=8 AND p.CODE='YA_METRIK_ID' AND ep.IBLOCK_ELEMENT_ID="
                ." CASE WHEN s.UF_METRIC_SOURCE IS NOT NULL AND s.UF_METRIC_SOURCE<>'' THEN s.UF_METRIC_SOURCE ELSE s.UF_SOURCE END AND ep.VALUE=?)"
                ." ORDER BY s.ID DESC LIMIT 1";
            $q=$this->pdo->prepare($sql); $found=[];
            foreach ($userIds as $userId) {
                $q->execute([$userId,$counterId]); $r=$q->fetch();
                if (!$r) { $found[]=['external_user_id'=>$userId,'found'=>false]; continue; }
                $cid=(string)$r['UF_CLIENT_ID']; $record=(string)$r['ID'];
                if (!MaxTrackerLookupProtocol::id($cid,40)||!MaxTrackerLookupProtocol::id($record)) throw new RuntimeException('tracker_invalid_record');
                $observed=null;
                try { $observed=MaxSubscriptionAttributionPolicy::eventTimeUnix((string)$r['UF_DATE'],new DateTimeZone('Europe/London')); } catch (InvalidArgumentException $ignored) {}
                $found[]=['external_user_id'=>$userId,'found'=>true,'client_id'=>$cid,'record_id'=>$record,'observed_at_unix'=>$observed];
            }
            return $found;
        } finally { if($this->pdo->inTransaction())$this->pdo->rollBack(); }
    }
}
