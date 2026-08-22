<?php

class DestinationAreaResolver
{
    /**
     * Определяет туристическую зону, которой нет отдельной строкой в TvApiRegion,
     * по устойчивой привязке отелей из TvApiHotel к одной стране/региону.
     * Пример: "Лара" отсутствует в HL3, но десятки отелей с отдельным словом LARA
     * имеют UF_CID=4 и UF_TID=20 (Анталья).
     */
    public static function infer($text)
    {
        $tokens = self::tokens($text);
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

                // Нужен сильный консенсус: не менее 3 отелей и минимум 70% точных
                // token-совпадений должны вести в один и тот же регион.
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

    private static function queryHotels($needle, $limit)
    {
        try {
            \Bitrix\Main\Loader::includeModule('highloadblock');
            $hl = \Bitrix\Highloadblock\HighloadBlockTable::getById(6)->fetch();
            if (!$hl) return [];
            $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hl);
            $class = $entity->getDataClass();
            $res = $class::getList([
                'filter' => ['%UF_NAME' => $needle],
                'select' => ['UF_HID','UF_NAME','UF_CID','UF_TID'],
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
        $stop = ['хочу','хотим','нужен','нужна','нужно','отель','гостиница','тур','путевка','путёвка','поехать','ехать','лететь','вылет','на','в','из','и','или','для','с','со','около','район','курорт','начале','середине','конце','октябре','сентябре','ноябре','декабре','январе','феврале','марте','апреле','мае','июне','июле','августе','двоих','троих','неделю','недели','ночей','дней'];
        return array_values(array_filter((array)$parts, static function($p) use ($stop) {
            return mb_strlen($p,'UTF-8') >= 4 && !in_array($p,$stop,true) && !preg_match('/^\d+$/',$p);
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
