<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerSelectionLinks.php';
require_once __DIR__.'/ManagerSelectionPageParser.php';

/** An observed-at, in-memory view, not a send-time archive or booking quote. */
final class ManagerSelectionSnapshot
{
    public const VERSION = 1;
    private function __construct(private readonly string $binding, private readonly array $data, private readonly array $completeIds) {}

    public static function read(array $authorized, ?callable $transport = null): self
    {
        $context = $authorized['context'] ?? [];
        $sourceLimitReached = count($authorized['selection_sources'] ?? []) > 40;
        $binding = self::binding($context); $sources = []; $groups = [];
        $messages = array_column($context['messages'] ?? [], null, 'id');
        foreach (array_slice($authorized['selection_sources'] ?? [], 0, 40) as $source) {
            if (!is_array($source) || !isset($messages[$source['message_id'] ?? 0])) continue;
            $message = $messages[$source['message_id']];
            if (($message['sender_type'] ?? '') !== ($source['sender_type'] ?? '') || !empty($message['text_truncated'])) continue;
            $url = is_string($source['url'] ?? null) ? ManagerSelectionLinks::canonical($source['url']) : null;
            $source = ['message_id'=>$message['id'], 'sender_type'=>$message['sender_type'],
                'sent_at'=>(string)($message['created_at'] ?? ''), 'url'=>$url];
            $sources[] = $source;
            if ($url !== null) { unset($groups[$url]); $groups[$url] = true; }
        }
        $urls = array_slice(array_keys($groups), -2);
        $received = [];
        if ($urls) {
            try { $received = ($transport ?? [self::class,'fetch'])($urls); }
            catch (Throwable $ignored) { $received = []; }
        }
        $observed = gmdate('Y-m-d\TH:i:s\Z'); $snapshots = []; $completeUrls = [];
        foreach ($urls as $url) {
            $response = is_array($received) && is_array($received[$url] ?? null) ? $received[$url] : [];
            $status = $response['status'] ?? 0;
            $parsed = ['state'=>in_array($status,[403,404,410],true)?'unavailable':'unread','offer_count'=>0,'offers'=>[],'truncated'=>false];
            if ($status === 200 && is_string($response['html'] ?? null)
                && is_string($response['type'] ?? null) && preg_match('~\A(?:text/html|application/xhtml\+xml)(?:;|\z)~i', $response['type'])) {
                $parsed = ManagerSelectionPageParser::parse($response['html']);
            }
            $occurrences = [];
            foreach ($sources as $source) if ($source['url'] === $url) {
                $occurrences[] = array_intersect_key($source, array_flip(['message_id','sender_type','sent_at']));
            }
            $snapshots[] = ['source'=>'qui_quo_public_selection', 'source_messages'=>array_slice($occurrences,-20),
                'observed_at'=>$observed, 'historical_send_snapshot'=>false, 'current_price_verified'=>false,
                'http_status'=>is_int($status) && $status>=100 && $status<=599 ? $status : null,
                'state'=>$parsed['state'], 'offer_count'=>$parsed['offer_count'],
                'offers_truncated'=>$parsed['truncated'], 'offers'=>$parsed['offers']];
            $completeUrls[$url] = $parsed['state'] === 'observed' && !$parsed['truncated'] && count($occurrences)<=20;
        }
        // Bound the extra model payload separately from the existing 40k transcript.
        while (self::length(json_encode($snapshots, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) > 12000) {
            $changed = false;
            for ($i=count($snapshots)-1; $i>=0; --$i) if ($snapshots[$i]['offers']) {
                array_pop($snapshots[$i]['offers']); $snapshots[$i]['offers_truncated'] = true;
                $completeUrls[$urls[$i]] = false; $changed = true; break;
            }
            if (!$changed) { $snapshots = []; $completeUrls = []; break; }
        }
        $completeIds = [];
        foreach ($sources as $source) {
            $id = $source['message_id'];
            $completeIds[$id] = ($completeIds[$id] ?? true) && $source['url'] !== null && !empty($completeUrls[$source['url']]);
        }
        return new self($binding, ['selection_snapshots'=>$snapshots,
            'selection_coverage'=>['supported_unique_links'=>count($groups),'links_attempted'=>count($urls),
                'links_omitted'=>max(0,count($groups)-count($urls)), 'unsupported_links'=>count(array_filter($sources,static fn($s)=>$s['url']===null)),
                'source_limit_reached'=>$sourceLimitReached]],
            $sourceLimitReached ? [] : array_keys(array_filter($completeIds)));
    }

    public function forContext(array $context): ?array
    {
        return hash_equals($this->binding, self::binding($context)) ? $this->data : null;
    }

    public function completeMessageIds(): array { return $this->completeIds; }

    private static function binding(array $context): string
    {
        $rows = [];
        foreach ($context['messages'] ?? [] as $message) $rows[] = [$message['id']??null,$message['sender_type']??null,$message['text']??null,
            $message['event_type']??'', $message['created_at']??'', $message['text_truncated']??false];
        return hash('sha256',json_encode([$context['conversation_id']??null,$context['as_of_message_id']??null,$rows],JSON_THROW_ON_ERROR));
    }

    private static function length(string $text): int { return (int)preg_match_all('/./us',$text); }

    /** Fixed-service HTTPS only; no credentials, redirects, proxy, cookies or retries. */
    private static function fetch(array $urls): array
    {
        if (!function_exists('curl_multi_init')) return [];
        $records = @dns_get_record('qui-quo.ru', DNS_A);
        $ip = null;
        foreach (is_array($records)?$records:[] as $record) if (self::publicAddress($record['ip']??'')) { $ip=$record['ip']; break; }
        if ($ip === null) return [];
        $multi = curl_multi_init(); $handles = []; $buffers = []; $types = []; $out = [];
        try {
            foreach ($urls as $index=>$url) {
                if (ManagerSelectionLinks::canonical($url) !== $url) continue;
                $h = curl_init($url); if ($h === false) continue;
                $buffers[$index] = ''; $types[$index] = '';
                $ok = curl_setopt_array($h, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROXY=>'',
                    CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                    CURLOPT_RESOLVE=>['qui-quo.ru:443:'.$ip], CURLOPT_CONNECTTIMEOUT_MS=>500, CURLOPT_TIMEOUT_MS=>1400,
                    CURLOPT_MAXFILESIZE=>ManagerSelectionPageParser::MAX_BYTES, CURLOPT_ENCODING=>'',
                    CURLOPT_USERAGENT=>'AnyTour-Manager-Selection-Reader/1.0', CURLOPT_HTTPHEADER=>['Accept: text/html'],
                    CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$buffers,$index): int {
                        if (strlen($buffers[$index])+strlen($chunk)>ManagerSelectionPageParser::MAX_BYTES) return 0;
                        $buffers[$index].=$chunk; return strlen($chunk);
                    },
                    CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$types,$index): int {
                        if (stripos($line,'Content-Type:')===0) $types[$index]=trim(substr($line,13));
                        return strlen($line);
                    }]);
                if (!$ok || curl_multi_add_handle($multi,$h)!==CURLM_OK) { curl_close($h); continue; }
                $handles[$index] = $h;
            }
            $deadline = hrtime(true)+1600000000;
            do {
                $code = curl_multi_exec($multi,$running);
                if ($code!==CURLM_OK || !$running || hrtime(true)>$deadline) break;
                if (curl_multi_select($multi,0.05)===-1) usleep(1000);
            } while (true);
            $finished = [];
            while ($info=curl_multi_info_read($multi)) {
                $index = array_search($info['handle'], $handles, true);
                if ($index!==false && $info['result']===CURLE_OK) $finished[$index]=true;
            }
            foreach ($handles as $index=>$h) if (isset($finished[$index])) {
                $out[$urls[$index]]=['status'=>(int)curl_getinfo($h,CURLINFO_HTTP_CODE),'type'=>$types[$index],'html'=>$buffers[$index]];
            }
        } finally {
            foreach ($handles as $h) { curl_multi_remove_handle($multi,$h); curl_close($h); }
            curl_multi_close($multi);
        }
        return $out;
    }

    private static function publicAddress(string $ip): bool
    {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
        $n = ip2long($ip);
        foreach ([[0x64400000,0xffc00000],[0xc0000000,0xffffff00],[0xc0000200,0xffffff00],
            [0xc6120000,0xfffe0000],[0xc6336400,0xffffff00],[0xcb007100,0xffffff00],[0xe0000000,0xf0000000]] as [$prefix,$mask]) {
            if (($n & $mask)===$prefix) return false;
        }
        return true;
    }
}
