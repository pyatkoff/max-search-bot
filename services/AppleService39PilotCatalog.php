<?php
declare(strict_types=1);

/** The reviewed pilot entries shared by the fixed publisher and repair ingress. */
final class AppleService39PilotCatalog
{
    public const PROJECT = 'appleservice39';
    public const BOT = 'id390521129062_bot';
    public const WARRANTY_MONTHS = 3;
    public const WARRANTY_TEXT = 'Гарантия — 3 месяца.';
    private const MODELS = [
        'iphone-x'=>'iPhone X',
        'iphone-15'=>'iPhone 15',
        'iphone-15-plus'=>'iPhone 15 Plus',
        'iphone-15-pro'=>'iPhone 15 Pro',
        'iphone-15-pro-max'=>'iPhone 15 Pro Max',
    ];
    private const SERVICES = [
        'battery_replacement'=>['battery-replacement','Замена аккумулятора'],
        'front_glass_replacement'=>['front-glass-replacement','Замена стекла без дисплея'],
        'display'=>['display','Замена дисплея'],
    ];
    /** Finite search-intent successor entries; the fifteen original repairs stay immutable. */
    private const SEARCH_ENTRIES = [
        'model-iphone-17'=>['model'=>'','service'=>'','scope'=>'model_family','model_family'=>'iphone-17'],
        'repair-iphone-16-display'=>['model'=>'iPhone 16','service'=>'Замена дисплея','scope'=>'search_repair'],
        'service-rear-glass-replacement'=>['model'=>'','service'=>'Замена заднего стекла','scope'=>'service'],
        'symptom-not-charging'=>['model'=>'','service'=>'iPhone не заряжается','scope'=>'symptom','symptom'=>'not_charging'],
        'service-rear-camera-replacement'=>['model'=>'','service'=>'Замена основной камеры','scope'=>'service'],
        'service-earpiece-replacement'=>['model'=>'','service'=>'Ремонт разговорного динамика','scope'=>'service'],
    ];
    private static ?array $catalog = null;

    private static function check(bool $ok): void
    {
        if (!$ok) throw new RuntimeException('appleservice39_pilot_catalog_invalid');
    }

    private static function expected(): array
    {
        $expected = [];
        foreach (self::MODELS as $key=>$model) {
            foreach (self::SERVICES as $serviceKey=>[$suffix,$service]) {
                $expected['repair-'.$key.'-'.$suffix] = [$model,$serviceKey,$service];
            }
        }
        return $expected;
    }

    private static function text($value, int $limit): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= $limit
            && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value) === 0;
    }

    /** No provider request, runtime configuration or publication state is consulted. */
    public static function entries(): array
    {
        if (self::$catalog !== null) return array_values(self::$catalog);
        $raw = @file_get_contents(__DIR__.'/../resources/appleservice39-pilot-entries.json', false, null, 0, 65537);
        self::check(is_string($raw) && strlen($raw) <= 65536);
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        self::check(is_array($data) && ($data['schema'] ?? null) === 1
            && ($data['project_key'] ?? null) === self::PROJECT && ($data['bot_key'] ?? null) === self::BOT
            && is_string($data['checked_date'] ?? null) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $data['checked_date']) === 1
            && is_string($data['source_posts_sha256'] ?? null) && preg_match('/\A[0-9a-f]{64}\z/D', $data['source_posts_sha256']) === 1
            && is_array($data['entries'] ?? null) && array_is_list($data['entries']) && count($data['entries']) === 15);
        $expected = self::expected();
        $catalog = [];
        foreach ($data['entries'] as $entry) {
            self::check(is_array($entry) && is_string($entry['post_id'] ?? null));
            $id = $entry['post_id'];
            self::check(isset($expected[$id]) && !isset($catalog[$id]));
            [$model,$serviceKey,$service] = $expected[$id];
            self::check(($entry['model'] ?? null) === $model && ($entry['service_key'] ?? null) === $serviceKey
                && ($entry['service'] ?? null) === $service && in_array($entry['price_kind'] ?? '', ['listed','variants','on_request'], true)
                && self::text($entry['price_text'] ?? null, 1600) && self::text($entry['question'] ?? null, 1000)
                && is_array($entry['source_ids'] ?? null) && array_is_list($entry['source_ids']) && count($entry['source_ids']) >= 1
                && count($entry['source_ids']) <= 20 && is_array($entry['source_urls'] ?? null)
                && array_is_list($entry['source_urls']) && count($entry['source_urls']) >= 1 && count($entry['source_urls']) <= 20);
            foreach ($entry['source_ids'] as $sourceId) {
                self::check(is_string($sourceId) && preg_match('/\Aas39_[0-9a-f]{20}\z/D', $sourceId) === 1);
            }
            foreach ($entry['source_urls'] as $url) {
                self::check(is_string($url) && strlen($url) <= 512
                    && preg_match('~\Ahttps://appleservice39\.ru/[a-zA-Z0-9/_-]+\z~D', $url) === 1);
            }
            $entry['start_payload'] = 'as39_'.$id;
            self::check(strlen($entry['start_payload']) <= 64);
            $catalog[$id] = $entry;
        }
        self::check(count($catalog) === count($expected));
        self::$catalog = $catalog;
        return array_values($catalog);
    }

    public static function forPost(string $postId): ?array
    {
        $expected = self::expected();
        if (!isset($expected[$postId])) return null;
        self::entries();
        return self::$catalog[$postId] ?? null;
    }

    public static function payloadForPost(string $postId): ?string
    {
        $entry = self::forPost($postId);
        return $entry['start_payload'] ?? null;
    }


    /** The nine navigation pages have bounded partial contexts, never a guessed repair price. */
    public static function landingEntryForPost(string $postId): ?array
    {
        $repair = self::forPost($postId);
        if ($repair !== null) return $repair;
        if (isset(self::SEARCH_ENTRIES[$postId])) {
            return ['post_id'=>$postId] + self::SEARCH_ENTRIES[$postId] + ['start_payload'=>'as39_'.$postId];
        }
        $model = ''; $service = ''; $scope = '';
        if ($postId === 'menu') $scope = 'menu';
        elseif (str_starts_with($postId, 'model-') && isset(self::MODELS[substr($postId, 6)])) {
            $scope = 'model'; $model = self::MODELS[substr($postId, 6)];
        } else {
            foreach (self::SERVICES as [$suffix,$name]) if ($postId === 'service-'.$suffix) {
                $scope = 'service'; $service = $name; break;
            }
        }
        if ($scope === '') {
            require_once __DIR__.'/AppleService39CoverageRevision.php';
            return (new AppleService39CoverageRevision())->entry($postId);
        }
        return ['post_id'=>$postId,'model'=>$model,'service'=>$service,'scope'=>$scope,'start_payload'=>'as39_'.$postId];
    }

    public static function landingPayloadForPost(string $postId): ?string
    {
        return self::landingEntryForPost($postId)['start_payload'] ?? null;
    }

    /** A service selected on an existing model card retains that exact model. */
    public static function modelServicePayload(string $modelPost, string $servicePost): ?string
    {
        $codes = ['service-battery-replacement'=>'battery',
            'service-front-glass-replacement'=>'glass', 'service-display'=>'display'];
        $code = $codes[$servicePost] ?? null;
        return $code !== null ? (self::modelServiceEntry($modelPost, $code)['start_payload'] ?? null) : null;
    }

    private static function modelServiceEntry(string $modelPost, string $code): ?array
    {
        $services = ['battery'=>'Замена аккумулятора', 'glass'=>'Замена стекла без дисплея',
            'display'=>'Замена дисплея'];
        if (!isset($services[$code])) return null;
        require_once __DIR__.'/AppleService39CoverageRevision.php';
        $model = (new AppleService39CoverageRevision())->entry($modelPost);
        if ($model === null || $model['scope'] !== 'model' || $model['model'] === '') return null;
        $payload = 'as39_pair_'.$modelPost.'_'.$code;
        self::check(strlen($payload) <= 64);
        // Attribution still belongs to the real published model card. No price,
        // availability, new publication or credential is inferred from the link.
        return ['post_id'=>$modelPost, 'model'=>$model['model'], 'service'=>$services[$code],
            'scope'=>'model_service', 'service_code'=>$code, 'start_payload'=>$payload];
    }

    public static function resolve(string $projectKey, string $botKey, string $payload): ?array
    {
        if ($projectKey !== self::PROJECT || $botKey !== self::BOT) return null;
        if (preg_match('/\Aas39_pair_(model-iphone-[a-z0-9-]{1,40})_(battery|glass|display)\z/D', $payload, $parts)) {
            return self::modelServiceEntry($parts[1], $parts[2]);
        }
        if ($payload === 'as39_x_battery') return self::forPost('repair-iphone-x-battery-replacement');
        return str_starts_with($payload, 'as39_') ? self::landingEntryForPost(substr($payload, 5)) : null;
    }

    /** Same-conversation display labels only; never carry prices or an old diagnosis. */
    public static function entryContext(array $entry, array $known, string $payload): array
    {
        self::check(self::resolve(self::PROJECT, self::BOT, $payload) === $entry);
        $context = ['model'=>$entry['model'],'service'=>$entry['service'],'entry_code'=>$payload];
        $scope = $entry['scope'] ?? 'repair';
        if (in_array($scope, ['repair','search_repair','model_service'], true)) return $context;
        $model = $known['model'] ?? '';
        $model = is_string($model) && self::text($model, 160)
            && preg_match('/[\x00-\x1f\x7f]/u', $model) === 0
            && (str_starts_with($model, 'iPhone ') || $model === 'Серия iPhone 17') ? $model : '';
        $services = array_column(self::SERVICES, 1);
        foreach (self::SEARCH_ENTRIES as $candidate) {
            if ($candidate['service'] !== '') $services[] = $candidate['service'];
        }
        $service = is_string($known['service'] ?? null) && in_array($known['service'], $services, true)
            ? $known['service'] : '';
        $knownFamily = $model === 'Серия iPhone 17' && ($known['model_family'] ?? '') === 'iphone-17';
        if ($model === 'Серия iPhone 17' && !$knownFamily) $model = '';
        if ($scope === 'menu') {
            $context['model'] = $model;
            $context['service'] = $service;
            if ($knownFamily) $context['model_family'] = 'iphone-17';
            if ($service === 'iPhone не заряжается' && ($known['symptom'] ?? '') === 'not_charging') {
                $context['symptom'] = 'not_charging';
            }
        } elseif ($scope === 'model_family') {
            $compatible = in_array($model, ['iPhone 17','iPhone 17 Pro','iPhone 17 Pro Max'], true) || $knownFamily;
            $context['model'] = $compatible ? $model : 'Серия iPhone 17';
            $context['service'] = $compatible ? $service : '';
            $context['model_family'] = 'iphone-17';
            if ($compatible && $service === 'iPhone не заряжается' && ($known['symptom'] ?? '') === 'not_charging') {
                $context['symptom'] = 'not_charging';
            }
        } elseif ($scope === 'model') {
            if ($entry['model'] === $model) $context['service'] = $service;
            if ($entry['model'] === $model && $service === 'iPhone не заряжается'
                && ($known['symptom'] ?? '') === 'not_charging') $context['symptom'] = 'not_charging';
        } elseif ($scope === 'service' || $scope === 'symptom') {
            $context['model'] = $model;
            if ($knownFamily) $context['model_family'] = 'iphone-17';
            if ($scope === 'symptom') $context['symptom'] = 'not_charging';
        }
        return $context;
    }

    /** Only a fresh entry uses merged copy; saved historical intents are never regenerated. */
    public static function contextResponseText(array $entry, string $brand, array $context): string
    {
        if (($entry['scope'] ?? '') === 'model_service') {
            $questions = ['battery'=>'Телефон быстро разряжается или выключается с остатком заряда?',
                'glass'=>'Изображение и сенсор работают без искажений?',
                'display'=>'Экран показывает изображение и реагирует на касания?'];
            self::check(isset($questions[$entry['service_code']]));
            return 'Здравствуйте! Это '.$brand."\n".$entry['model'].' — '.mb_strtolower($entry['service'], 'UTF-8').".\n"
                .$questions[$entry['service_code']]."\n".self::WARRANTY_TEXT
                ."\nСтоимость и наличие подходящей детали уточнит менеджер.";
        }
        if (!isset($entry['scope'])) return self::responseText($entry, $brand);
        if (!isset(self::SEARCH_ENTRIES[$entry['post_id']])
            && $context['model'] === $entry['model'] && $context['service'] === $entry['service']) {
            return self::responseText($entry, $brand);
        }
        $model = $context['model'];
        $service = $context['service'];
        $familyOnly = $model === 'Серия iPhone 17' && ($context['model_family'] ?? '') === 'iphone-17';
        $scope = $entry['scope'];
        $intro = 'Здравствуйте! Это '.$brand.".\n";
        $end = "\n".self::WARRANTY_TEXT."\nСтоимость, наличие детали и возможность записи уточнит менеджер.";
        if ($scope === 'menu') {
            if ($model !== '' && $service !== '') {
                return $intro.'Сохранено ваше обращение: '.$model.' — '.$service.'. Что нужно уточнить по ремонту?'.$end;
            }
            if ($model !== '') {
                $question = $familyOnly ? 'Уточните версию — 17, 17 Pro или 17 Pro Max — и что произошло.' : 'Расскажите, что произошло с телефоном.';
                return $intro.'Поможем с ремонтом: '.$model.'. '.$question.$end;
            }
            return $intro.'Поможем с ремонтом iPhone в Калининграде. Какая у вас модель и что произошло?'.$end;
        }
        if ($scope === 'model_family') {
            $question = $familyOnly ? 'Какая у вас версия — 17, 17 Pro или 17 Pro Max — и что произошло?' : 'Расскажите, что произошло с телефоном.';
            if ($service !== '') $question = $familyOnly ? 'Какая у вас версия — 17, 17 Pro или 17 Pro Max?' : 'Что нужно уточнить по ремонту?';
            return $intro.'Ремонт: '.$model.($service !== '' ? ' — '.$service : '').'. '.$question.$end;
        }
        if ($scope === 'symptom') {
            $question = ($model === '' ? 'Какая у вас модель? ' : ($familyOnly ? 'Какая у вас версия — 17, 17 Pro или 17 Pro Max? ' : ''))
                .'Телефон совсем не реагирует на кабель или зарядка прерывается?';
            return $intro.'Поможем разобраться с зарядкой'.($model !== '' ? ': '.$model : ' iPhone').'. '.$question.$end;
        }
        if ($scope === 'model') {
            return $intro.'Ремонт '.$model.($service !== '' ? ' — '.$service : '').'. '
                .($service !== '' ? 'Что нужно уточнить по ремонту?' : 'Расскажите, что произошло с телефоном.').$end;
        }
        $question = $model === '' ? 'Какая у вас модель iPhone?' : ($familyOnly ? 'Какая у вас версия — 17, 17 Pro или 17 Pro Max?' : '');
        if ($scope === 'search_repair') {
            $question = 'Что произошло с экраном: пропало изображение, появились полосы или повреждено стекло?';
        } elseif ($entry['post_id'] === 'service-rear-camera-replacement') {
            $question .= ($question !== '' ? ' ' : '').'Как проявляется неисправность основной камеры?';
        } elseif ($entry['post_id'] === 'service-earpiece-replacement') {
            $question .= ($question !== '' ? ' ' : '').'Собеседника не слышно совсем или звук искажён?';
        } elseif ($question === '') {
            $question = 'Что нужно уточнить по ремонту?';
        }
        return $intro.$service.($model !== '' ? ' — '.$model : ' iPhone').'. '.$question.$end;
    }

    /** Capture this text once in the entry event before attempting delivery. */
    public static function responseText(array $entry, string $brand): string
    {
        if (($entry['scope'] ?? '') === 'model_service' || isset(self::SEARCH_ENTRIES[$entry['post_id'] ?? ''])) {
            return self::contextResponseText($entry, $brand,
                self::entryContext($entry, [], $entry['start_payload']));
        }
        if (isset($entry['scope'])) {
            $intro = 'Здравствуйте! Это '.$brand.".\n";
            $warranty = "\n".self::WARRANTY_TEXT."\n";
            if ($entry['scope'] === 'model') return $intro.'Ремонт '.$entry['model'].'.'.$warranty.'Расскажите, что случилось с телефоном. Менеджер поможет уточнить стоимость и возможность записи.';
            if ($entry['scope'] === 'service') return $intro.$entry['service'].'.'.$warranty.'Какая у вас модель iPhone и что произошло? Уточним подходящий вариант, стоимость и наличие детали.';
            return $intro.'Поможем с ремонтом iPhone в Калининграде.'.$warranty.'Что хотите уточнить? Если это новое устройство, укажите модель и проблему.';
        }
        return 'Здравствуйте! Это '.$brand.".\n".$entry['service'].' — '.$entry['model'].".\n"
            .$entry['price_text']."\n".self::WARRANTY_TEXT."\n".$entry['question'];
    }
}

