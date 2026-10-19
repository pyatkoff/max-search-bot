<?php
declare(strict_types=1);

require_once __DIR__.'/DiagnosticLogger.php';

/** Fixed protocol categories only. Never persist credentials, identities or RPC payloads. */
final class ManagerPreparationMcpDiagnostics
{
    private const METHODS = ['initialize','notifications/initialized','server/discover','tools/list','events/list','ping'];
    private const VERSIONS = ['2026-07-28','2025-11-25','2025-06-18','2025-03-26','2024-11-05'];
    private const STAGES = ['origin','credentials','http_method','body','rpc','complete','unavailable'];

    public static function metadata(array $server, ?array $rpc, int $status, string $stage, ?int $error): array
    {
        $header=(string)($server['HTTP_AUTHORIZATION']??'');
        $credential=$header==='' ? (empty($server['REDIRECT_HTTP_AUTHORIZATION'])?'missing':'redirect_only')
            : (preg_match('/\ABearer [A-Za-z0-9_-]{32,512}\z/D',$header) ? 'bearer'
                : (preg_match('/\Abearer /i',$header)?'noncanonical_bearer':'other'));
        $params=is_array($rpc['params']??null)?$rpc['params']:[];
        return self::sanitize([
            'http_method'=>$server['REQUEST_METHOD']??null,
            'credential_header'=>$credential,
            'client_kind'=>($server['HTTP_USER_AGENT']??'')==='AnyTour-MCP-Header-Probe/1.0'?'header_probe':'other',
            'rpc_method'=>$rpc['method']??null,
            'requested_version'=>$params['protocolVersion']??null,
            'header_version'=>$server['HTTP_MCP_PROTOCOL_VERSION']??null,
            'http_status'=>$status,'stage'=>$stage,'rpc_error'=>$error,
        ]);
    }

    public static function record(array $server, ?array $rpc, int $status, string $stage, ?int $error): void
    {
        try {
            DiagnosticLogger::log('manager_preparation_mcp','http_result',self::metadata($server,$rpc,$status,$stage,$error));
        } catch (Throwable $ignored) { /* Diagnostics must not affect authentication or responses. */ }
    }

    /** Read a bounded tail of the existing private log; re-allowlist every published field. */
    public static function snapshot(): array
    {
        $attempts=[];
        $file=DiagnosticLogger::filePath();
        $handle=$file!==''?@fopen($file,'rb'):false;
        if ($handle) {
            try {
                $size=(int)(fstat($handle)['size']??0);
                if ($size>524288) {fseek($handle,-524288,SEEK_END);fgets($handle);}
                $raw=stream_get_contents($handle,524288);
                foreach (array_reverse(explode("\n",(string)$raw)) as $line) {
                    $row=json_decode($line,true);
                    if (!is_array($row)||($row['component']??'')!=='manager_preparation_mcp'||($row['event']??'')!=='http_result'
                        ||!is_array($row['data']??null)||!is_string($row['ts']??null)
                        ||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D',$row['ts'])) continue;
                    $attempts[]=['at'=>$row['ts']]+self::sanitize($row['data']);
                    if (count($attempts)>=20) break;
                }
            } finally {fclose($handle);}
        }
        return ['sampled_count'=>count($attempts),'recent_attempts'=>$attempts];
    }

    private static function sanitize(array $data): array
    {
        $category=static fn($value,array $allowed):string=>$value===null?'absent':(in_array($value,$allowed,true)?$value:'other');
        return [
            'http_method'=>$category($data['http_method']??null,['POST','GET','HEAD','OPTIONS','DELETE']),
            'credential_header'=>$category($data['credential_header']??null,['missing','redirect_only','bearer','noncanonical_bearer','other']),
            'client_kind'=>$category($data['client_kind']??null,['header_probe','other','absent']),
            'rpc_method'=>$category($data['rpc_method']??null,[...self::METHODS,'absent']),
            'requested_version'=>$category($data['requested_version']??null,[...self::VERSIONS,'absent']),
            'header_version'=>$category($data['header_version']??null,[...self::VERSIONS,'absent']),
            'http_status'=>in_array($data['http_status']??null,[200,202,400,401,403,405,413,503],true)?$data['http_status']:0,
            'stage'=>$category($data['stage']??null,self::STAGES),
            'rpc_error'=>in_array($data['rpc_error']??null,[-32700,-32600,-32601,-32602,-32603,-32001,-32015],true)?$data['rpc_error']:null,
        ];
    }
}
