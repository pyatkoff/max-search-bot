<?php
declare(strict_types=1);

/** Exact immutable app publication + the accepted canonical tracker map. */
final class Max2CommonEntryEvidenceV2
{
    public const MAP_SHA256 = 'c5ee090912b5e20402a46b79294137ef3045a02b1dd37abf4a1f9cb5fae50172';
    public const RAW_MAP_SHA256 = '581591d2165213a65b53a457123f3078fd36b0db1bbf664d91008efa359e5595';
    private array $map;
    public function __construct(private PDO $pdo, string $mapFile)
    {
        if (is_link($mapFile) || !is_file($mapFile) || filesize($mapFile) > 300000) throw new RuntimeException('V2_MAP_UNAVAILABLE');
        $raw = file_get_contents($mapFile);
        if (!is_string($raw) || hash('sha256',$raw) !== self::MAP_SHA256) throw new RuntimeException('V2_MAP_CHANGED');
        $m = json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (($m['kind'] ?? null) !== 'anytour_max2_canonical_publication_offer_map_v1'
            || ($m['project_key'] ?? null) !== 'anytour' || ($m['platform'] ?? null) !== 'max'
            || ($m['source_map_sha256'] ?? null) !== self::RAW_MAP_SHA256 || ($m['binding_count'] ?? null) !== 1055
            || !is_array($m['bindings_by_post_id'] ?? null) || count($m['bindings_by_post_id']) !== 1055) throw new RuntimeException('V2_MAP_SCOPE');
        $this->map = $m;
    }
    private static function id($v): bool { return is_string($v) && preg_match('/\A[1-9][0-9]{0,19}\z/D',$v) === 1; }
    private static function sha($v): bool { return is_string($v) && preg_match('/\A[a-f0-9]{64}\z/D',$v) === 1; }

    /** Caller supplies the canonical just-recorded entry, never a latest-by-actor selection. */
    public function read(string $entryId, string $conversationId, string $actor, string $sourceEventKey): ?array
    {
        if (!self::id($entryId) || !self::id($conversationId) || !self::id($actor) || !self::sha($sourceEventKey)) return null;
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $length = $mysql ? 'OCTET_LENGTH(v.snapshot_json)' : 'LENGTH(CAST(v.snapshot_json AS BLOB))';
        $sql = 'SELECT '.($mysql?'/*+ MAX_EXECUTION_TIME(250) */ ':'')
            . 'e.id,e.entry_key,e.availability_reason,c.id AS conversation_id,c.customer_id,c.customer_channel_id,c.project_key,c.channel,c.external_chat_id,c.is_test,'
            . 'cc.project_key AS channel_project,cc.channel AS channel_platform,cc.customer_id AS channel_customer,cc.external_user_id,cc.external_chat_id AS channel_chat,'
            . 'p.project_key AS publication_project,pc.platform,pc.bot_key,pc.canonical_bot_username,pc.channel_id,pp.id AS publication_id,v.id AS version_id,'
            . 'CASE WHEN '.$length.'<=131072 THEN v.snapshot_json ELSE NULL END AS snapshot_json'
            . ' FROM post_entries e JOIN conversations c ON c.id=e.conversation_id'
            . ' JOIN customer_channels cc ON cc.id=c.customer_channel_id'
            . ' JOIN post_publications pp ON pp.id=e.publication_id JOIN post_channels pc ON pc.id=pp.post_channel_id'
            . ' JOIN projects p ON p.id=pc.project_id JOIN post_versions v ON v.id=e.version_id AND v.publication_id=pp.id'
            . ' WHERE e.id=? AND e.conversation_id=? LIMIT 1';
        $q = $this->pdo->prepare($sql); $q->execute([$entryId,$conversationId]); $r = $q->fetch(PDO::FETCH_ASSOC); $q->closeCursor();
        if (!is_array($r) || (string)$r['id'] !== $entryId || (string)$r['conversation_id'] !== $conversationId
            || $r['availability_reason'] !== '' || $r['project_key'] !== 'anytour' || $r['publication_project'] !== 'anytour'
            || $r['channel_project'] !== 'anytour' || $r['channel'] !== 'max' || $r['channel_platform'] !== 'max' || $r['platform'] !== 'max'
            || (string)$r['is_test'] !== '0' || !self::id((string)$r['customer_id']) || !self::id((string)$r['customer_channel_id'])
            || (string)$r['channel_customer'] !== (string)$r['customer_id'] || $r['external_user_id'] !== $actor
            || $r['external_chat_id'] !== '-'.$actor || $r['channel_chat'] !== '-'.$actor
            || $r['bot_key'] !== $this->map['post_bot_key'] || $r['channel_id'] !== $this->map['channel_id']
            || $r['entry_key'] !== hash('sha256','max|'.$r['bot_key'].'|'.$sourceEventKey) || !is_string($r['snapshot_json'])) return null;
        $pub = (string)$r['publication_id']; $version = (string)$r['version_id'];
        $pair = $this->map['bindings_by_post_id'][$pub] ?? null;
        $snapshot = json_decode($r['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
        if (!self::id($pub) || !self::id($version) || !is_array($pair) || !is_array($snapshot)
            || ($pair['publication_id'] ?? null) !== $pub || ($pair['post_id'] ?? null) !== $pub
            || ($snapshot['post_url'] ?? null) !== $pair['post_url']) return null;
        $subject = ['project_key'=>'anytour','platform'=>'max','customer_key'=>(string)$r['customer_id'],'conversation_id'=>$conversationId];
        $pairHash = hash('sha256',json_encode(['version'=>1,'map_sha256'=>self::MAP_SHA256,'pair'=>$pair,
            'entry_id'=>$entryId,'entry_key'=>$r['entry_key'],'version_id'=>$version,'snapshot_sha256'=>hash('sha256',$r['snapshot_json'])],JSON_THROW_ON_ERROR));
        return ['subject'=>$subject,'external_user_id'=>$actor,'external_chat_id'=>'-'.$actor,
            'customer_channel_id'=>(string)$r['customer_channel_id'],'entry_id'=>$entryId,'entry_key'=>$r['entry_key'],
            'source_event_key'=>$sourceEventKey,
            'publication_id'=>$pub,'version_id'=>$version,'offer_id'=>$pair['offer_id'],'hotel_id'=>$pair['hotel_id'],
            'pair_mapping_sha256'=>$pairHash,'map_sha256'=>self::MAP_SHA256,
            'subject_actor_binding_sha256'=>hash('sha256',json_encode([$subject,(string)$r['customer_channel_id'],$actor,'-'.$actor],JSON_THROW_ON_ERROR))];
    }
}
