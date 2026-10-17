<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class TelegramArchiveHttpFixture
{
    public string $directory;
    public string $url;
    private $process;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().'/telegram-archive-http-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700); $root = $this->directory.'/app'; mkdir($root,0700);
        $repo = dirname(__DIR__,2);
        foreach (['services','manager','manager/lib','manager/assets'] as $dir) if (!is_dir($root.'/'.$dir)) mkdir($root.'/'.$dir,0700,true);
        foreach (glob($repo.'/services/*.php') as $file) copy($file,$root.'/services/'.basename($file));
        foreach (['project.php','telegram_archive_webhook.php','manager/telegram-archive.php','manager/telegram-archive-api.php','manager/lib/ManagerHttp.php','manager/assets/manager-http-client.js','manager/assets/telegram-archive.css','manager/assets/telegram-archive.js'] as $file) copy($repo.'/'.$file,$root.'/'.$file);
        file_put_contents($root.'/config.php', '<?php define("MAX_SEARCH_STANDALONE_RUNTIME",true); define("MAX_SEARCH_TELEGRAM_ARCHIVE_PRIVATE_DIR",'.var_export($this->directory.'/credentials',true).');'); chmod($root.'/config.php',0600);
        $pdo = new PDO('sqlite:'.$this->directory.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); chmod($this->directory.'/fixture.sqlite',0600);
        self::schema($pdo);
        $socket = stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
        if (!$socket) throw new RuntimeException('fixture_socket_unavailable');
        $address = stream_socket_get_name($socket,false); fclose($socket);
        $this->url = 'http://'.$address;
        $env = getenv(); $env['TELEGRAM_ARCHIVE_HTTP_FIXTURE']='1'; $env['TELEGRAM_ARCHIVE_HTTP_ROOT']=$root; $env['TELEGRAM_ARCHIVE_HTTP_STATE']=$this->directory;
        $log = $this->directory.'/server.log'; touch($log); chmod($log,0600);
        $this->process = proc_open([PHP_BINARY,'-d','display_errors=0','-S',$address,'-t',$root,$repo.'/tests/fixtures/telegram_archive_admin_router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,$env);
        if (!is_resource($this->process)) throw new RuntimeException('fixture_server_unavailable');
        fclose($pipes[0]);
        for ($i=0;$i<100;$i++) { $connection = @stream_socket_client('tcp://'.$address,$errno,$error,0.05); if ($connection) { fclose($connection); return; } usleep(30000); }
        throw new RuntimeException('fixture_start_timeout');
    }

    public static function schema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE managers (id INTEGER PRIMARY KEY,login VARCHAR(64),display_name VARCHAR(255),role VARCHAR(32),email VARCHAR(255),is_active INTEGER,is_working INTEGER)');
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY,project_key VARCHAR(64),display_name VARCHAR(255),is_active INTEGER)');
        $pdo->exec('CREATE TABLE manager_projects (manager_id INTEGER,project_id INTEGER)');
        $pdo->exec("INSERT INTO projects VALUES (1,'anytour','AnyTour',1),(2,'other','Other',1)");
        $pdo->exec("INSERT INTO managers VALUES (11,'admin','Администратор','admin','',1,0),(12,'manager','Анна','manager','',1,0),(13,'inactive','Неактивный','manager','',0,0),(14,'foreign','Другой проект','admin','',1,0)");
        $pdo->exec('INSERT INTO manager_projects VALUES (11,1),(12,1),(13,1),(14,2)');
        $pdo->exec('CREATE TABLE telegram_dialogue_connections (project_key TEXT,bot_key TEXT,connection_id TEXT,account_id TEXT,is_enabled INTEGER,last_update_id INTEGER,PRIMARY KEY(project_key,bot_key,connection_id))');
        $pdo->exec('CREATE TABLE telegram_dialogue_messages (id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,account_id TEXT,chat_id TEXT,message_id TEXT,version_at INTEGER,source_kind TEXT,is_deleted INTEGER,record_json TEXT,UNIQUE(project_key,account_id,chat_id,message_id))');
        $pdo->exec('CREATE TABLE telegram_archive_accounts (project_key TEXT,bot_key TEXT,account_id TEXT,connection_id TEXT,display_name TEXT,username TEXT,connection_date INTEGER,last_update_id INTEGER,provider_enabled INTEGER,approved INTEGER,manager_id INTEGER,PRIMARY KEY(project_key,bot_key,account_id),UNIQUE(project_key,bot_key,connection_id))');
    }

    public function request(string $path, ?array $body = null, string $cookie = '', array $extraHeaders = []): array
    {
        $headers = array_merge(['Content-Type: application/json'], $cookie !== '' ? ['Cookie: '.$cookie] : [], $extraHeaders);
        $context = stream_context_create(['http'=>['method'=>$body === null ? 'GET' : 'POST','header'=>implode("\r\n",$headers),'content'=>$body === null ? '' : json_encode($body),'ignore_errors'=>true,'timeout'=>10]]);
        $text = file_get_contents($this->url.$path,false,$context); $response = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', $response[0] ?? '', $status);
        $session = '';
        foreach ($response as $header) if (preg_match('/^Set-Cookie:\s*(anytour_manager_panel=[^;]+)/i',$header,$match)) $session=$match[1];
        return ['status'=>(int)($status[1]??0),'text'=>(string)$text,'json'=>json_decode((string)$text,true),'cookie'=>$session];
    }

    public function close(): void
    {
        if (is_resource($this->process)) { proc_terminate($this->process); proc_close($this->process); $this->process=null; }
        if (!is_dir($this->directory)) return;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($this->directory);
    }
}
