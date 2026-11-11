<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ProjectClientCredentialStore.php';

/** Persisted extension of the trusted connection registry, never a sender. */
final class ProjectClientRegistry
{
    private static ?self $testStore=null;
    public function __construct(public readonly PDO $pdo,public readonly ProjectClientCredentialStore $credentials) {}
    public static function current(): self {return self::$testStore??new self(ConversationDb::connection(),new ProjectClientCredentialStore());}
    public static function hasTestStore(): bool {return self::$testStore!==null;}
    public static function resetForTests(?self $store=null): void {if(PHP_SAPI!=='cli')throw new LogicException('test_only');self::$testStore=$store;}
    /** A different valid-length key must not silently create a mixed-key registry. */
    public function assertCredentialKey(): void
    {
        $row=$this->pdo->query('SELECT p.project_key,c.bot_key,c.credentials_json,c.token_fingerprint FROM project_client_connections c JOIN projects p ON p.id=c.project_id ORDER BY c.project_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if(!$row)return;
        $credentials=$this->credentials->open((string)$row['project_key'],(string)$row['bot_key'],(string)$row['credentials_json']);
        if(!hash_equals((string)$row['token_fingerprint'],$this->credentials->fingerprint($credentials['token'])))throw new RuntimeException('credential_store_unavailable');
    }
    public static function runtimeEntries(?string $project=null,bool $configured=false): array
    {
        if(!self::$testStore&&!ConversationDb::isConfigured())return [];
        try{
            $store=self::current();
            if($project!==null&&!$configured){$q=$store->pdo->prepare('SELECT p.id FROM projects p JOIN project_client_connections c ON c.project_id=p.id WHERE p.project_key=? LIMIT 1');$q->execute([$project]);if(!$q->fetchColumn())return [];}
            return $store->entries();
        }catch(Throwable $ignored){return [];}
    }
    public function entries(): array
    {
        $q=$this->pdo->query('SELECT p.project_key,p.is_active,c.bot_key,c.settings_json,c.credentials_json FROM project_client_connections c JOIN projects p ON p.id=c.project_id ORDER BY c.project_id LIMIT 1001');$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>1000)throw new RuntimeException('client_capacity');$out=[];
        foreach($rows as $row){
            $project=(string)$row['project_key'];
            // Unreadable/disabled bindings still participate in bot uniqueness.
            $out[$project]=['project'=>[],'max'=>['bot_key'=>(string)$row['bot_key']]];
            if(!(int)$row['is_active'])continue;
            try{
                $settings=json_decode((string)$row['settings_json'],true);
                if(!is_array($settings)||($settings['id']??null)!==$project)continue;
                $secret=$this->credentials->open($project,(string)$row['bot_key'],(string)$row['credentials_json']);
                $out[$project]=['project'=>$settings,'max'=>['bot_key'=>(string)$row['bot_key']]+$secret];
            }catch(Throwable $ignored){}
        }
        return $out;
    }
}
