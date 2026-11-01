<?php
declare(strict_types=1);

/** Isolated metadata schema; never connects to a production or hotel database. */
final class DevelopmentWorkTestSchema
{
    public static array $lastProof=[];
    public static function tables(): array
    {
        return ['development_work_connection_handoffs','development_work_events','development_work_appends','development_work_retirements','development_work_resolutions','development_work_receipts',
            'development_work_items','development_work_queues','development_work_lane'];
    }
    public static function create(PDO $pdo,bool $reset=true): void
    {
        if ($reset) foreach (self::tables() as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
        $first=self::apply($pdo);$second=self::apply($pdo);
        if ($first!==$second||$first<6) throw new RuntimeException('development_fixture_migration_reapply_failed');
        self::$lastProof['applies']=2;
    }
    public static function apply(PDO $pdo): int
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver,['sqlite','mysql'],true)) throw new RuntimeException('unsafe_development_fixture_driver');
        $source=(string)file_get_contents(dirname(__DIR__,2).'/migrations/074_development_work_queue.sql')."\n".(string)file_get_contents(dirname(__DIR__,2).'/migrations/075_development_work_connection_handoffs.sql');
        if ($source==='') throw new RuntimeException('development_fixture_migration_missing');
        $count=0;
        foreach (explode(';',$source) as $statement) {
            $sql=trim((string)preg_replace('/^\s*--[^\r\n]*$/m','',$statement));
            if ($sql==='') continue;
            if ($driver==='sqlite') {
                $sql=(string)preg_replace('/\s+CHARACTER SET ascii COLLATE ascii_bin/i','',$sql);
                $sql=(string)preg_replace('/\b(?:VARBINARY|VARCHAR|CHAR)\(\d+\)/i','TEXT',$sql);
                $sql=(string)preg_replace('/\b(?:BIGINT|TINYINT|INT)\s+UNSIGNED\b/i','INTEGER',$sql);
                $sql=(string)preg_replace('/,\s*KEY\s+[a-zA-Z0-9_]+\s*\([^)]*\)/i','',$sql);
                $sql=(string)preg_replace('/\bUNIQUE KEY\s+[a-zA-Z0-9_]+\s*\(/i','UNIQUE (',$sql);
                $sql=(string)preg_replace('/\s+ENGINE=InnoDB\s+DEFAULT CHARSET=utf8mb4\b/i','',$sql);
                $sql=(string)preg_replace('/\bINSERT IGNORE INTO\b/i','INSERT OR IGNORE INTO',$sql);
            }
            $pdo->exec($sql);++$count;
        }
        self::$lastProof=['driver'=>$driver,'statements'=>$count,'migration_sha256'=>hash('sha256',$source),'applies'=>1];
        return $count;
    }
}

final class DevelopmentWorkTestStatement extends PDOStatement
{
    protected function __construct(private DevelopmentWorkTestPdo $owner) {}
    public function execute(?array $params=null): bool
    {
        $this->owner->observe($this->queryString);
        if ($this->owner->beforeExecute!==null) ($this->owner->beforeExecute)($this->queryString,$params);
        return parent::execute($params);
    }
}

final class DevelopmentWorkTestPdo extends PDO
{
    public array $writes=[];
    public $beforeExecute=null;
    public function __construct(?string $sqliteFile=null,bool $reset=true)
    {
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>false];
        if (getenv('DEVELOPMENT_WORK_NATIVE_MYSQL')==='1') {
            $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
            if (!str_starts_with($dsn,'mysql:')||preg_match('/(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$match)!==1) {
                throw new RuntimeException('unsafe_development_fixture_database');
            }
            $options[PDO::ATTR_EMULATE_PREPARES]=false;
            parent::__construct($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);
            if (parent::query('SELECT DATABASE()')->fetchColumn()!==$match[1]) throw new RuntimeException('unsafe_development_fixture_database');
        } else {
            parent::__construct($sqliteFile===null?'sqlite::memory:':'sqlite:'.$sqliteFile,null,null,$options);
            parent::exec('PRAGMA busy_timeout=5000');
        }
        if ($reset) DevelopmentWorkTestSchema::create($this);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[DevelopmentWorkTestStatement::class,[$this]]);
    }
    public function observe(string $sql): void
    {
        if (preg_match('/\A\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+([a-z_]+)/i',$sql,$match)) {
            $table=strtolower($match[1]);
            if (!in_array($table,DevelopmentWorkTestSchema::tables(),true)) throw new RuntimeException('development_fixture_rejected_cross_scope_write');
            $this->writes[]=$table;
        }
    }
}
