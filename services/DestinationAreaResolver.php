<?php
require_once(__DIR__ . '/DateNoiseGuard.php');
require_once(__DIR__ . '/NeedApplicationService.php');

class DestinationAreaResolver
{
    public static function resolveAndStore($chatId, $text)
    {
        $inferred = self::infer($text);
        if (!$inferred) return null;

        $country = self::getCountry((int)$inferred['country_id']);
        $region = self::getRegion((int)$inferred['region_id']);
        if (!$country || !$region) return null;

        NeedApplicationService::applyParameters($chatId, ['country' => (string)$country['UF_NAME']]);

        $dir = dirname(__DIR__) . '/ai_destination';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . '/' . preg_replace('/[^0-9\-]/','',(string)$chatId) . '.json';
        $old = [];
        if (is_file($file)) {
            $tmp = json_decode((string)@file_get_contents($file), true);
            if (is_array($tmp)) $old = $tmp;
        }

        $data = array_merge($old, [
            'country_id' => (int)$country['UF_CID'],
            'country' => (string)$country['UF_NAME'],
            'region_id' => (int)$region['UF_TID'],
            'region' => (string)$region['UF_NAME'],
            'area' => (string)$inferred['area'],
            'area_inferred' => true,
            'area_confidence' => $inferred['confidence'],
            'area_evidence_count' => $inferred['evidence_count'],
            'area_evidence_total' => $inferred['evidence_total'],
            'source_text' => (string)$text,
            'updated_at' => date('c'),
        ]);
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX);

        MaxSearchApi::funnelLog($chatId, 'destination_area_resolved', $data);
        return $data;
    }

    public static function infer($text)
    {
        $destinationText = self::destinationPart((string)$text);
        $tokens = self::tokens($destinationText);
        if (!$tokens) return null;

        foreach ($tokens as $token) {
            if (mb_strlen($token, 'UTF-8') < 4) continue;

            $variants = array_values(array_unique(array_filter([
                $token,
                self::translitRu($token),
            ])));

            foreach ($variants as $needle) {
                if (strlen($needle) < 4) continue;
                $rows = self::queryHotels($needle, 200);
                if (!$rows) continue;

                $matched = [];
                foreach ($rows as $row) {
                    $hotelTokens = self::hotelTokens((string)($row['UF_NAME'] ?? ''));
                    if (!in_array(self::normAscii($needle), $hotelTokens, true)) continue;
                    $matched[] = $row;
                }

                if (count($matched) < 3) continue;

                $groups = [];
                foreach ($matched as $row) {
                    $cid = (int)($row['UF_CID'] ?? 0);
                    $tid = (int)($row['UF_TID'] ?? 0);
                    if ($cid <= 0 || $tid <= 0) continue;
                    $key = $cid . ':' . $tid;
                    if (!isset($groups[$key])) $groups[$key] = ['count'=>0,'cid'=>$cid,'tid'=>$tid];
                    $groups[$key]['count']++;
                }
                if (!$groups) continue;

                usort($groups, static function($a,$b){ return $b['count'] <=> $a['count']; });
                $top = $groups[0];
                $total = array_sum(array_column($groups, 'count'));
                $share = $total > 0 ? ($top['count'] / $total) : 0;

                if ($top['count'] < 3 || $share < 0.70) continue;

                return [
                    'area' => $token,
                    'country_id' => (int)$top['cid'],
                    'region_id' => (int)$top['tid'],
                    'evidence_count' => (int)$top['count'],
                    'evidence_total' => (int)$total,
                    'confidence' => round($share, 3),
                ];
            }
        }
        return null;
    }

    private static function destinationPart($text)
    {
        $text = trim((string)$text);
        if ($text === '') return '';

        if (preg_match('/(?:^|\s)из\s+.+?\s+в\s+(.+)$/ui', $text, $m)) {
            $part = trim((string)($m[1] ?? ''));
            // A trailing month/date phrase is not a destination. This matters for
            // requests such as "туры из Калининграда ... в августе": the generic
            // "из ... в ..." pattern must not turn "августе" into an area.
            if ($part !== '' && self::tokens($part)) return $part;
            return '';
        }

        if (preg_match('/(?:с\s+)?вылет(?:ом)?\s+из\s+[\p{L}\-]+(?:\s+[\p{L}\-]+)*/ui', $text)) {
            return '';
        }
        if (preg_match('/(?:^|\s)туры?\s+из\s+[\p{L}\-]+(?:\s+[\p{L}\-]+)*/ui', $text)) {
            return '';
        }
        if (preg_match('/(?:^|\s)из\s+[\p{L}\-]+(?:\s+[\p{L}\-]+)*\s*$/ui', $text)) {
            return '';
        }

        return $text;
    }

    private static function getCountry($cid)
    {
        return self::queryHl(2, ['=UF_CID'=>$cid], ['UF_CID','UF_NAME'], 1)[0] ?? null;
    }

    private static function getRegion($tid)
    {
        return self::queryHl(3, ['=UF_TID'=>$tid], ['UF_TID','UF_CID','UF_NAME','UF_PARENT_TID'], 1)[0] ?? null;
    }

    private static function queryHotels($needle, $limit)
    {
        return self::queryHl(6, ['%UF_NAME'=>$needle], ['UF_HID','UF_NAME','UF_CID','UF_TID'], $limit);
    }

    private static function queryHl($hlId, array $filter, array $select, $limit)
    {
        try {
            \Bitrix\Main\Loader::includeModule('highloadblock');
            $hl = \Bitrix\Highloadblock\HighloadBlockTable::getById((int)$hlId)->fetch();
            if (!$hl) return [];
            $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hl);
            $class = $entity->getDataClass();
            $res = $class::getList([
                'filter' => $filter,
                'select' => $select,
                'limit' => (int)$limit,
            ]);
            $rows=[];
            while ($row=$res->fetch()) $rows[]=$row;
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function tokens($text)
    {
        $s = function_exists('mb_strtolower') ? mb_strtolower((string)$text,'UTF-8') : strtolower((string)$text);
        $s = str_replace('ё','е',$s);
        $s = preg_replace('/[^a-zа-я0-9]+/ui',' ',$s);
        $parts = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        $stop = [
            'хочу','хотим','нужен','нужна','нужно','отель','гостиница','тур','путевка','путёвка','поехать','ехать','лететь','вылет',
            'на','в','из','и','или','для','с','со','около','район','курорт','начале','середине','конце','двоих','троих','неделю','недели','ночей','дней',
            'куда','можно','какие','какой','какая','вариант','варианты','предложить','посоветуй','посоветуйте','лучше','хорошее','хороший','хорошую','теплую','теплое','теплый',
            // Питание — это ответ на meal-вопрос, а не география. Эти слова часто встречаются
            // в названиях отелей и раньше могли давать ложный area/hotel match.
            'завтрак','завтраки','обед','обеды','ужин','ужины','питание','полупансион','пансион','все','всё','включено','включено'
        ];
        return array_values(array_filter((array)$parts, static function($p) use ($stop) {
            return mb_strlen($p,'UTF-8') >= 4
                && !in_array($p,$stop,true)
                && !DateNoiseGuard::isMonthWord($p)
                && !preg_match('/^\d+$/',$p);
        }));
    }

    private static function hotelTokens($name)
    {
        $s = self::normAscii($name);
        return array_values(array_filter(preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY), static function($p){ return strlen($p)>=3; }));
    }

    private static function normAscii($text)
    {
        $s = function_exists('mb_strtolower') ? mb_strtolower((string)$text,'UTF-8') : strtolower((string)$text);
        $s = self::translitRu($s);
        $s = preg_replace('/[^a-z0-9]+/',' ',strtolower($s));
        return trim(preg_replace('/\s+/',' ',$s));
    }

    private static function translitRu($s)
    {
        return strtr((string)$s, [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
            'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'Zh','З'=>'Z','И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'H','Ц'=>'C','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch','Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya'
        ]);
    }
}
