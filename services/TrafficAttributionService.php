<?php

/**
 * Runtime traffic metadata for one chat.
 *
 * Keeps yclid/region/campaign/entry-channel file persistence out of the legacy base class.
 * The service does not know about Bitrix and never reads secrets.
 */
class TrafficAttributionService
{
    public static function file($baseDir, $chatID)
    {
        $dir = rtrim((string)$baseDir, '/') . '/traffic';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . '/' . preg_replace('/[^0-9\-]/', '', (string)$chatID) . '.json';
    }

    public static function parseStartPayload($payload)
    {
        $raw=trim((string)$payload);$clean=preg_replace('/^ya/i','',$raw);
        $context=[];
        // Only the versioned, bounded paid-entry suffix carries offer hints.
        // Old payloads (including their permissive fallback parsing) stay intact.
        if (strlen($raw)<=512 && str_contains($clean,'_ctx1_')) {
            [$prefix,$encoded]=explode('_ctx1_',$clean,2);
            $candidate=self::decodeContext($encoded);
            if ($candidate && self::contextPrefix($prefix)) {
                $clean=$prefix;
                $context=$candidate;
            }
        }
        $out=['yclid'=>'','region_id'=>'','campaign_id'=>'','entry_channel'=>'','raw'=>$raw];
        if($clean==='')return$out;

        // Legacy published MAX source links pass the canonical source key directly
        // as ?start=max_anytour_*. Keep those links backward-compatible.
        if(preg_match('/^max_anytour_[a-z0-9_-]{1,48}$/i',$clean)){
            $out['entry_channel']=strtolower($clean);
            return$out;
        }

        if(preg_match('/^(\d{6,})_region_([^_]*)_campaign_([^_]*)/i',$clean,$m)){
            $out['yclid']=$m[1]??'';$out['region_id']=$m[2]??'';$out['campaign_id']=$m[3]??'';
        }elseif(preg_match('/^(\d{6,})_key_(.*?)_(\d+)_campaign_([^_]+)/i',$clean,$m)){
            $out['yclid']=$m[1]??'';$out['region_id']=$m[3]??'';$out['campaign_id']=$m[4]??'';
        }elseif(preg_match('/^_?(\d{6,})_r_([^_]+)(?:_c_([^_]+))?/i',$clean,$m)){
            $out['yclid']=$m[1]??'';$out['region_id']=$m[2]??'';$out['campaign_id']=$m[3]??'';
        }elseif(preg_match('/\A([0-9]{6,})_([0-9]+)_campaign_([0-9]+)\z/i',$clean,$m)){
            // MAX25 positional payload; keep IDs as strings and all legacy formats intact.
            $out['yclid']=$m[1];$out['region_id']=$m[2];$out['campaign_id']=$m[3];
        }elseif(preg_match('/^(\d{6,})/',$clean,$m)){
            $out['yclid']=$m[1]??'';
        }

        if($out['region_id']==='' && preg_match('/(?:^|_)region_([^_]*)_campaign_([^_]*)/i',$clean,$m)){
            $out['region_id']=$m[1]??'';$out['campaign_id']=$m[2]??'';
        }
        if($out['region_id']==='' && preg_match('/(?:^|_)r_([^_]+)(?:_c_([^_]+))?/i',$clean,$m)){
            $out['region_id']=$m[1]??'';$out['campaign_id']=$m[2]??'';
        }
        if($out['campaign_id']==='' && preg_match('/(?:^|_)campaign_([^_]+)/i',$clean,$m))$out['campaign_id']=$m[1]??'';

        if(preg_match('/(?:^|_)entry_(.+?)(?=_region_|_campaign_|_r_|_c_|$)/i',$clean,$m)){
            $entry=strtolower(trim((string)($m[1]??'')));
            if(preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/',$entry))$out['entry_channel']=$entry;
        }
        return array_merge($out,$context);
    }

    public static function save($baseDir, $chatID, $yclid = '', $region = '', $campaign = '', $raw = '', $entryChannel = '')
    {
        $data = [
            'chat_id' => (string)$chatID,
            'yclid' => (string)$yclid,
            'region_id' => (string)$region,
            'campaign_id' => (string)$campaign,
            'entry_channel' => (string)$entryChannel,
            'raw' => (string)$raw,
            'updated_at' => date('c'),
        ];
        // The unchanged live caller supplies the original start payload. Never
        // attach its offer to different explicit advertising fields or reuse a
        // preceding chat's cached offer on an ordinary/static post start.
        $parsed=self::parseStartPayload($raw);
        if ((string)$yclid===$parsed['yclid'] && (string)$region===$parsed['region_id'] && (string)$campaign===$parsed['campaign_id']) {
            $data=array_merge($data,self::context($parsed));
        }

        $file = self::file($baseDir, $chatID);
        $tmp = $file . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        @chmod($tmp, 0644);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return $data;
    }

    public static function get($baseDir, $chatID)
    {
        $file = self::file($baseDir, $chatID);
        if (!is_file($file) || !is_readable($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public static function buildMiniappUrl($botUrl, array $meta, $latestYclid = '', bool $includeContext = false)
    {
        $yclid = trim((string)$latestYclid);
        if ($yclid === '') $yclid = trim((string)($meta['yclid'] ?? ''));
        $region = trim((string)($meta['region_id'] ?? ''));
        $campaign = trim((string)($meta['campaign_id'] ?? ''));
        $entry = trim((string)($meta['entry_channel'] ?? ''));

        $start = $yclid;
        if($entry!=='')$start.=($start!==''?'_':'').'entry_'.$entry;
        if ($region !== '' || $campaign !== '') {
            $start .= ($start!==''?'_':'') . 'region_' . $region . '_campaign_' . $campaign;
        }
        if ($start === '') $start = '0';

        $context=self::context($meta);
        // latestYclid is a separate legacy owner: a different click must not
        // inherit the cached offer. This file is a latest-chat cache, not a visit ledger.
        if ($includeContext && $context && $yclid===(string)($meta['yclid']??'') && self::contextPrefix($start)) {
            $extended=$start.'_ctx1_'.self::encodeContext($context);
            if (strlen($extended)<=512) $start=$extended;
        }

        return rtrim((string)$botUrl, '?&') . '?startapp=' . rawurlencode($start);
    }

    /** Untrusted publication references, never proof of a visit, choice or identity. */
    private static function context(array $meta): array
    {
        $post=$meta['post_id']??null;
        $offer=$meta['offer_id']??'';
        if (!is_string($post) || !preg_match('/\A[1-9][0-9]{0,19}\z/D',$post)) return [];
        if (!is_string($offer) || ($offer!=='' && !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D',$offer))) return [];
        return ['post_id'=>$post,'offer_id'=>$offer];
    }

    private static function contextPrefix(string $prefix): bool
    {
        if (!preg_match('/\A[0-9]{6,64}(?:(?:_entry_[a-z0-9][a-z0-9_-]{0,63})?_region_[0-9]{1,20}_campaign_[0-9]{1,20}|_[0-9]{1,20}_campaign_[0-9]{1,20})\z/iD',$prefix)) return false;
        // Keep zero/missing advertising IDs out of the extended paid contract.
        $base=self::parseStartPayload($prefix);
        return (bool)preg_match('/[1-9]/',$base['yclid']) && (bool)preg_match('/[1-9]/',$base['region_id']);
    }

    private static function encodeContext(array $context): string
    {
        return rtrim(strtr(base64_encode(json_encode($context,JSON_UNESCAPED_SLASHES)),'+/','-_'),'=');
    }

    private static function decodeContext(string $encoded): array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D',$encoded)) return [];
        $decoded=base64_decode(strtr($encoded,'-_','+/'),true);
        if ($decoded===false) return [];
        $value=json_decode($decoded,true,4);
        if (!is_array($value) || array_keys($value)!==['post_id','offer_id']) return [];
        $context=self::context($value);
        // Canonical bytes reject duplicate keys, type coercion and ambiguous encodings.
        return $context && self::encodeContext($context)===$encoded ? $context : [];
    }
}
