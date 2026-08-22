<?php

class DestinationResolver
{
    private static $countryHL = 2;
    private static $regionHL = 3;
    private static $hotelHL = 6;

    public static function resolveAndStore($chatId, $text)
    {
        $text = trim((string)$text);
        if ($text === '') return [];

        $current = MaxSearchApi::getAiSearchContext($chatId);
        $country = null;
        if (!empty($current['country'])) {
            $country = self::findCountryByName($current['country']);
        }
        if (!$country) {
            $country = self::findCountryInText($text);
        }

        $countryId = $country ? (int)$country['UF_CID'] : 0;
        $region = self::findRegionInText($text, $countryId);
        if ($region && !$countryId) {
            $country = self::findCountryById((int)$region['UF_CID']);
            $countryId = $country ? (int)$country['UF_CID'] : (int)$region['UF_CID'];
        }

        $regionId = $region ? (int)$region['UF_TID'] : 0;
        $hotelResult = self::findHotelInText($text, $countryId, $regionId);
        $hotel = $hotelResult['hotel'] ?? null;

        if ($hotel) {
            $countryId = (int)$hotel['UF_CID'];
            if (!$country || (int)$country['UF_CID'] !== $countryId) {
                $country = self::findCountryById($countryId);
            }

            $hotelRegionId = (int)$hotel['UF_TID'];
            if ($hotelRegionId > 0 && (!$region || (int)$region['UF_TID'] !== $hotelRegionId)) {
                $region = self::findRegionByTid($hotelRegionId);
                $regionId = $region ? (int)$region['UF_TID'] : $hotelRegionId;
            }
        }

        if ($country && empty($current['country'])) {
            MaxSearchApi::applyAiParameters($chatId, ['country' => (string)$country['UF_NAME']]);
        }

        $data = [
            'country_id' => $country ? (int)$country['UF_CID'] : 0,
            'country' => $country ? (string)$country['UF_NAME'] : '',
            'region_id' => $region ? (int)$region['UF_TID'] : 0,
            'region' => $region ? (string)$region['UF_NAME'] : '',
            'hotel_id' => $hotel ? (int)$hotel['UF_HID'] : 0,
            'hotel' => $hotel ? (string)$hotel['UF_NAME'] : '',
            'hotel_ambiguous' => !empty($hotelResult['ambiguous']),
            'hotel_matches' => (int)($hotelResult['matches'] ?? 0),
            'source_text' => $text,
            'updated_at' => date('c'),
        ];

        // Не затираем ранее найденный конкретный курорт/отель коротким ответом на другой вопрос.
        $old = self::getStored($chatId);
        foreach (['region_id','region','hotel_id','hotel'] as $key) {
            if (empty($data[$key]) && !empty($old[$key])) $data[$key] = $old[$key];
        }
        if (empty($data['country_id']) && !empty($old['country_id'])) {
            $data['country_id'] = $old['country_id'];
            $data['country'] = $old['country'] ?? '';
        }

        if ($data['country_id'] || $data['region_id'] || $data['hotel_id'] || $data['hotel_ambiguous']) {
            self::store($chatId, $data);
            MaxSearchApi::funnelLog($chatId, 'destination_resolved', [
                'country_id' => $data['country_id'],
                'country' => $data['country'],
                'region_id' => $data['region_id'],
                'region' => $data['region'],
                'hotel_id' => $data['hotel_id'],
                'hotel' => $data['hotel'],
                'hotel_ambiguous' => $data['hotel_ambiguous'],
                'hotel_matches' => $data['hotel_matches'],
            ]);
        }

        return $data;
    }

    public static function getStored($chatId)
    {
        $file = self::storeFile($chatId);
        if (!is_file($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public static function clear($chatId)
    {
        $file = self::storeFile($chatId);
        if (is_file($file)) @unlink($file);
    }

    private static function store($chatId, array $data)
    {
        @file_put_contents(
            self::storeFile($chatId),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private static function storeFile($chatId)
    {
        $dir = dirname(__DIR__).'/ai_destination';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir.'/'.preg_replace('/[^0-9\-]/', '', (string)$chatId).'.json';
    }

    private static function findCountryInText($text)
    {
        $rows = self::allRows(self::$countryHL, ['UF_CID','UF_NAME']);
        $norm = self::norm($text);
        $best = null;
        $bestLen = 0;
        foreach ($rows as $row) {
            $name = self::norm($row['UF_NAME'] ?? '');
            if ($name === '') continue;
            if (self::containsName($norm, $name) && mb_strlen($name, 'UTF-8') > $bestLen) {
                $best = $row;
                $bestLen = mb_strlen($name, 'UTF-8');
            }
        }
        return $best;
    }

    private static function findCountryByName($name)
    {
        $rows = self::query(self::$countryHL, ['=UF_NAME' => trim((string)$name)], ['UF_CID','UF_NAME'], 1);
        return $rows[0] ?? null;
    }

    private static function findCountryById($cid)
    {
        if ((int)$cid <= 0) return null;
        $rows = self::query(self::$countryHL, ['=UF_CID' => (int)$cid], ['UF_CID','UF_NAME'], 1);
        return $rows[0] ?? null;
    }

    private static function findRegionByTid($tid)
    {
        if ((int)$tid <= 0) return null;
        $rows = self::query(self::$regionHL, ['=UF_TID' => (int)$tid], ['UF_TID','UF_CID','UF_NAME','UF_PARENT_TID'], 1);
        return $rows[0] ?? null;
    }

    private static function findRegionInText($text, $countryId = 0)
    {
        $tokens = self::tokens($text);
        $candidates = [];

        foreach ($tokens as $token) {
            if (mb_strlen($token, 'UTF-8') < 4) continue;
            $stemLen = max(3, mb_strlen($token, 'UTF-8') - 1);
            $stem = mb_substr($token, 0, $stemLen, 'UTF-8');
            $filter = ['%UF_NAME' => $stem];
            if ($countryId > 0) $filter['=UF_CID'] = $countryId;
            foreach (self::query(self::$regionHL, $filter, ['UF_TID','UF_CID','UF_NAME','UF_PARENT_TID'], 30) as $row) {
                $candidates[(int)$row['UF_TID']] = $row;
            }
        }

        $best = null;
        $bestScore = 0;
        foreach ($candidates as $row) {
            $score = self::nameScore((string)$row['UF_NAME'], $text);
            if ($score > $bestScore) {
                $best = $row;
                $bestScore = $score;
            }
        }
        return $bestScore >= 3 ? $best : null;
    }

    private static function findHotelInText($text, $countryId = 0, $regionId = 0)
    {
        $tokens = self::tokens($text);
        $terms = [];

        // Более длинные фрагменты сначала: "rixos premium dubai" надёжнее, чем просто "rixos".
        $maxN = min(4, count($tokens));
        for ($n = $maxN; $n >= 1; $n--) {
            for ($i = 0; $i + $n <= count($tokens); $i++) {
                $part = array_slice($tokens, $i, $n);
                $term = trim(implode(' ', $part));
                if (mb_strlen($term, 'UTF-8') < 4) continue;
                $terms[$term] = true;
            }
        }

        $candidates = [];
        foreach (array_keys($terms) as $term) {
            $filter = ['%UF_NAME' => $term];
            if ($countryId > 0) $filter['=UF_CID'] = $countryId;
            if ($regionId > 0) $filter['=UF_TID'] = $regionId;
            foreach (self::query(self::$hotelHL, $filter, ['UF_HID','UF_NAME','UF_CID','UF_TID','UF_RATE'], 20) as $row) {
                $candidates[(int)$row['UF_HID']] = $row;
            }
            // Если нашли по длинному выражению, не размываем результат одиночными словами.
            if ($n ?? 0 > 1 && !empty($candidates)) break;
        }

        if (empty($candidates)) return ['hotel'=>null,'ambiguous'=>false,'matches'=>0];

        $scored = [];
        foreach ($candidates as $row) {
            $score = self::nameScore((string)$row['UF_NAME'], $text);
            if ($regionId > 0 && (int)$row['UF_TID'] === $regionId) $score += 5;
            if ($countryId > 0 && (int)$row['UF_CID'] === $countryId) $score += 2;
            if ($score > 0) $scored[] = ['score'=>$score,'row'=>$row];
        }
        usort($scored, static function($a,$b){ return $b['score'] <=> $a['score']; });
        if (empty($scored)) return ['hotel'=>null,'ambiguous'=>false,'matches'=>0];

        $top = $scored[0];
        $sameTop = array_values(array_filter($scored, static function($x) use ($top) {
            return $x['score'] === $top['score'];
        }));

        // Одно короткое бренд-слово (например RIXOS) без курорта часто неоднозначно.
        $meaningfulTokens = array_values(array_filter($tokens, static function($t){ return mb_strlen($t,'UTF-8') >= 4; }));
        $ambiguous = count($sameTop) > 1 || (count($meaningfulTokens) <= 1 && count($scored) > 1 && $regionId <= 0);

        return [
            'hotel' => $ambiguous ? null : $top['row'],
            'ambiguous' => $ambiguous,
            'matches' => count($scored),
        ];
    }

    private static function nameScore($name, $text)
    {
        $nameTokens = self::tokens($name);
        $textTokens = self::tokens($text);
        if (empty($nameTokens) || empty($textTokens)) return 0;

        $score = 0;
        foreach ($nameTokens as $nt) {
            if (mb_strlen($nt, 'UTF-8') < 3) continue;
            foreach ($textTokens as $tt) {
                if (mb_strlen($tt, 'UTF-8') < 3) continue;
                $len = min(mb_strlen($nt,'UTF-8'), mb_strlen($tt,'UTF-8'));
                $prefix = min(5, max(3, $len - 1));
                if (mb_substr($nt,0,$prefix,'UTF-8') === mb_substr($tt,0,$prefix,'UTF-8')) {
                    $score += $prefix;
                    break;
                }
            }
        }
        return $score;
    }

    private static function containsName($normText, $normName)
    {
        if (strpos($normText, $normName) !== false) return true;
        return self::nameScore($normName, $normText) >= 4;
    }

    private static function tokens($text)
    {
        $norm = self::norm($text);
        $parts = preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY);
        $stop = ['хочу','хотим','нужен','нужна','нужно','отель','гостиница','тур','путевка','путёвка','поехать','ехать','лететь','вылет','на','в','из','и','или','для','с','со','около','район','курорт'];
        return array_values(array_filter((array)$parts, static function($p) use ($stop) {
            return !in_array($p, $stop, true) && mb_strlen($p,'UTF-8') >= 2;
        }));
    }

    private static function norm($text)
    {
        $s = function_exists('mb_strtolower') ? mb_strtolower((string)$text, 'UTF-8') : strtolower((string)$text);
        $s = str_replace('ё', 'е', $s);
        $s = preg_replace('/[^a-zа-я0-9]+/ui', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function allRows($hlId, array $select)
    {
        return self::query($hlId, [], $select, 500);
    }

    private static function query($hlId, array $filter, array $select, $limit = 20)
    {
        try {
            \Bitrix\Main\Loader::includeModule('highloadblock');
            $hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById((int)$hlId)->fetch();
            if (!$hlblock) return [];
            $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
            $class = $entity->getDataClass();
            $res = $class::getList([
                'filter' => $filter,
                'select' => $select,
                'limit' => (int)$limit,
            ]);
            $rows = [];
            while ($row = $res->fetch()) $rows[] = $row;
            return $rows;
        } catch (\Throwable $e) {
            @file_put_contents(
                dirname(__DIR__).'/destination_errors.log',
                date('d.m.Y H:i:s').'--- '.$e->getMessage().PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
            return [];
        }
    }
}
