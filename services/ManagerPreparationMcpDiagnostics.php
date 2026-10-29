<?php
declare(strict_types=1);

require_once __DIR__.'/DiagnosticLogger.php';

/** Fixed protocol categories only. Never persist credentials, identities or RPC payloads. */
final class ManagerPreparationMcpDiagnostics
{
    private const METHODS = ['initialize','notifications/initialized','server/discover','tools/list','events/list','tools/call','events/subscribe','ping'];
    private const EVENTS = ['development_work.pending','development_work.attention'];
    private const VERSIONS = ['2026-07-28','2025-11-25','2025-06-18','2025-03-26','2024-11-05'];
    private const SURFACES = ['legacy','development','replies','hotels'];
    private const STAGES = ['origin','credentials','http_method','body','rpc','complete','unavailable'];

    public static function metadata(array $server, ?array $rpc, int $status, string $stage, ?int $error, string $surface = 'legacy'): array
    {
        $header=(string)($server['HTTP_AUTHORIZATION']??'');
        $credential=$header==='' ? (empty($server['REDIRECT_HTTP_AUTHORIZATION'])?'missing':'redirect_only')
            : (preg_match('/\ABearer [A-Za-z0-9_-]{32,512}\z/D',$header) ? 'bearer'
                : (preg_match('/\Abearer /i',$header)?'noncanonical_bearer':'other'));
        $params=is_array($rpc['params']??null)?$rpc['params']:[];
        return self::sanitize([
            // Fixed by the PHP endpoint, never derived from request metadata.
            'surface'=>$surface,
            'http_method'=>$server['REQUEST_METHOD']??null,
            'credential_header'=>$credential,
            'client_kind'=>($server['HTTP_USER_AGENT']??'')==='AnyTour-MCP-Header-Probe/1.0'?'header_probe':'other',
            'rpc_method'=>$rpc['method']??null,
            'event_name'=>($rpc['method']??null)==='events/subscribe'
                ? (($params['name']??null)===null?'absent':(in_array($params['name'],self::EVENTS,true)?$params['name']:'other'))
                : 'absent',
            'requested_version'=>$params['protocolVersion']??null,
            'header_version'=>$server['HTTP_MCP_PROTOCOL_VERSION']??null,
            'http_status'=>$status,'stage'=>$stage,'rpc_error'=>$error,
        ]);
    }

    public static function record(array $server, ?array $rpc, int $status, string $stage, ?int $error, string $surface = 'legacy'): void
    {
        try {
            DiagnosticLogger::log('manager_preparation_mcp','http_result',self::metadata($server,$rpc,$status,$stage,$error,$surface));
        } catch (Throwable $ignored) { /* Diagnostics must not affect authentication or responses. */ }
    }

    /** Read a bounded tail of the existing private log; re-allowlist every published field.
     * Coverage is opt-in to preserve the historical top-level snapshot contract.
     * These global observations do not identify a queue or prove a native commit.
     */
    public static function snapshot(bool $includeCoverage = false): array
    {
        $attempts=[];$available=false;$reason='unavailable';
        $tailTruncated=null;$sampleTruncated=null;
        $file=DiagnosticLogger::filePath();
        $handle=$file!==''?@fopen($file,'rb'):false;
        if ($handle) {
            try {
                $stat=fstat($handle);
                if (!is_array($stat)||!isset($stat['size'])||$stat['size']<0) throw new RuntimeException('diagnostic_read_failed');
                $tailTruncated=$stat['size']>524288;
                if ($tailTruncated&&fseek($handle,-524288,SEEK_END)!==0) throw new RuntimeException('diagnostic_read_failed');
                $raw=@stream_get_contents($handle,524288);
                if ($raw===false) throw new RuntimeException('diagnostic_read_failed');
                // Discard an incomplete first row inside the same fixed byte budget.
                if ($tailTruncated) {
                    $newline=strpos($raw,"\n");
                    $raw=$newline===false?'':substr($raw,$newline+1);
                }
                foreach (array_reverse(explode("\n",$raw)) as $line) {
                    $row=json_decode($line,true);
                    if (!is_array($row)||($row['component']??'')!=='manager_preparation_mcp'||($row['event']??'')!=='http_result'
                        ||!is_array($row['data']??null)||!is_string($row['ts']??null)
                        ||!preg_match('/\\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})\\z/D',$row['ts'])) continue;
                    $attempts[]=['at'=>$row['ts']]+self::sanitize($row['data']);
                    if (count($attempts)>20) break;
                }
                $sampleTruncated=count($attempts)>20;
                $attempts=array_slice($attempts,0,20);
                $available=true;$reason=null;
            } catch (RuntimeException $unavailable) {
                $attempts=[];$reason='read_error';$tailTruncated=null;$sampleTruncated=null;
            } finally {fclose($handle);}
        }
        $result=['sampled_count'=>count($attempts),'recent_attempts'=>$attempts];
        if ($includeCoverage) $result+=['available'=>$available,'coverage'=>$available?'bounded_log_tail':'unavailable',
            'tail_truncated'=>$tailTruncated,'sample_truncated'=>$sampleTruncated,'reason'=>$reason];
        return $result;
    }

    /** Pure matcher shared by installed ingress smoke and negative regressions. */
    public static function headerProbeDelivered(array $snapshot, int $started, string $surface): bool
    {
        if (!in_array($surface,self::SURFACES,true)) throw new InvalidArgumentException('invalid_mcp_surface');
        foreach (($snapshot['recent_attempts']??[]) as $attempt) {
            if (!is_array($attempt)||($attempt['surface']??null)!==$surface
                ||($attempt['client_kind']??null)!=='header_probe'
                ||($attempt['credential_header']??null)!=='bearer'
                ||($attempt['http_status']??null)!==401||($attempt['stage']??null)!=='credentials'
                ||($attempt['rpc_error']??null)!==-32001||!is_string($attempt['at']??null)) continue;
            $at=strtotime($attempt['at']);
            if ($at!==false&&$at>=$started) return true;
        }
        return false;
    }

    private static function sanitize(array $data): array
    {
        $category=static fn($value,array $allowed):string=>$value===null?'absent':(in_array($value,$allowed,true)?$value:'other');
        return [
            // Historical rows without the field are not evidence for either new request.
            'surface'=>$category($data['surface']??null,self::SURFACES),
            'http_method'=>$category($data['http_method']??null,['POST','GET','HEAD','OPTIONS','DELETE']),
            'credential_header'=>$category($data['credential_header']??null,['missing','redirect_only','bearer','noncanonical_bearer','other']),
            'client_kind'=>$category($data['client_kind']??null,['header_probe','other','absent']),
            'rpc_method'=>$category($data['rpc_method']??null,[...self::METHODS,'absent']),
            'event_name'=>($data['rpc_method']??null)==='events/subscribe'
                ? $category($data['event_name']??null,[...self::EVENTS,'absent']) : 'absent',
            'requested_version'=>$category($data['requested_version']??null,[...self::VERSIONS,'absent']),
            'header_version'=>$category($data['header_version']??null,[...self::VERSIONS,'absent']),
            'http_status'=>in_array($data['http_status']??null,[200,202,400,401,403,405,413,503],true)?$data['http_status']:0,
            'stage'=>$category($data['stage']??null,self::STAGES),
            'rpc_error'=>in_array($data['rpc_error']??null,[-32700,-32600,-32601,-32602,-32603,-32001,-32015],true)?$data['rpc_error']:null,
        ];
    }
}
