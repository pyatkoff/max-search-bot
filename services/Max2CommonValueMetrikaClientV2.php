<?php
declare(strict_types=1);

/** Metrika transport for the two modeled-value destinations. Each instance sends to one goal. */
final class Max2CommonValueMetrikaClientV2
{
    public const COUNTER = '98615635';
    public const GOAL = '687941321';
    public const TARGET = 'anytour_modeled_value_v2_max2';
    public const CONTRACT = 'max2_common_observed_inquiry_value_v2';
    public const UMK_GOAL = '696194739';
    public const UMK_TARGET = 'anytour_umk_funnel_value';
    public const UMK_CONTRACT = 'anytour_umk_funnel_value_v1';
    private bool $umk = false;
    private const API = 'https://api-metrika.yandex.net/management/v1/counter/98615635/';
    private const CAP_KOPEKS = 10000000;
    private bool $postAttempted = false;
    private bool $goalAdmitted = false;
    private $transport;

    public function __construct(private string $token, ?callable $transport = null)
    {
        if ($token === '' || strpbrk($token, "\r\n") !== false) throw new InvalidArgumentException('v2_token_unavailable');
        $this->transport = $transport;
    }

    public static function forUmk(string $token, ?callable $transport = null): self
    {
        $client = new self($token, $transport);
        $client->umk = true;
        return $client;
    }

    private static function id($value): bool
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,39}\z/D', $value) === 1;
    }

    private static function clientId($value): bool
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,63}\z/D', $value) === 1;
    }

    private static function hash($value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    /** The caller must atomically move its OWN event from reserved to attempted before this projection. */
    public static function csv(array $event, int $now): string
    {
        return self::encodeCsv($event, $now, self::GOAL, self::TARGET, self::CONTRACT);
    }

    public static function umkCsv(array $event, int $now): string
    {
        return self::encodeCsv($event, $now, self::UMK_GOAL, self::UMK_TARGET, self::UMK_CONTRACT);
    }

    private static function encodeCsv(array $event, int $now, string $goal, string $target, string $contract): string
    {
        if (($event['project_key'] ?? null) !== 'anytour' || ($event['counter_id'] ?? null) !== self::COUNTER
            || ($event['goal_id'] ?? null) !== $goal || ($event['target'] ?? null) !== $target
            || ($event['value_contract'] ?? null) !== $contract || ($event['delivery_state'] ?? null) !== 'attempted'
            || ($event['attempt_count'] ?? null) !== 1 || !self::hash($event['event_key'] ?? null)
            || !self::hash($event['attempt_key'] ?? null) || !self::clientId($event['client_id'] ?? null)
            || !is_int($event['delta_kopeks'] ?? null) || $event['delta_kopeks'] < 1 || $event['delta_kopeks'] > self::CAP_KOPEKS
            || !is_int($event['event_unix'] ?? null) || $event['event_unix'] < $now - 21 * 86400
            || $event['event_unix'] > $now || $now < 946684800) {
            throw new InvalidArgumentException('v2_event_not_admitted');
        }
        $price = (string)intdiv($event['delta_kopeks'], 100) . '.' . str_pad((string)($event['delta_kopeks'] % 100), 2, '0', STR_PAD_LEFT);
        $stream = fopen('php://temp', 'w+');
        try {
            fputcsv($stream, ['ClientId', 'Target', 'DateTime', 'Price', 'Currency'], ',', '"', '');
            fputcsv($stream, [$event['client_id'], $target, (string)$event['event_unix'], $price, 'RUB'], ',', '"', '');
            rewind($stream);
            $csv = stream_get_contents($stream);
            if (!is_string($csv) || strlen($csv) > 1024) throw new RuntimeException('v2_csv_failed');
            return $csv;
        } finally { fclose($stream); }
    }

    public static function comment(array $event): string
    {
        return self::eventComment('AnyTourV2_', $event);
    }

    public static function umkComment(array $event): string
    {
        return self::eventComment('AnyTourUMK_', $event);
    }

    private static function eventComment(string $prefix, array $event): string
    {
        if (!self::hash($event['event_key'] ?? null)) throw new InvalidArgumentException('v2_event_key_required');
        return $prefix . $event['event_key'];
    }

    /** Read only: confirms the existing exact goal and rejects overlapping action conditions. */
    public function goalReady(): bool
    {
        $this->goalAdmitted = false;
        try {
            $response = $this->request('GET', 'goals');
            if (($response['error'] ?? null) !== 0 || ($response['http'] ?? null) !== 200) return false;
            $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $goals = $data['goals'] ?? null;
            if (!is_array($goals) || !array_is_list($goals) || count($goals) > 200) return false;
            $goalId = $this->umk ? self::UMK_GOAL : self::GOAL;
            $target = $this->umk ? self::UMK_TARGET : self::TARGET;
            $selected = 0;
            foreach ($goals as $goal) {
                if (!is_array($goal)) return false;
                $id = (string)($goal['id'] ?? '');
                if ($id === $goalId) {
                    if (($goal['type'] ?? null) !== 'action' || (!$this->umk && ($goal['name'] ?? null) !== $target)
                        || !is_array($goal['conditions'] ?? null) || count($goal['conditions']) !== 1
                        || ($goal['conditions'][0]['type'] ?? null) !== 'exact'
                        || ($goal['conditions'][0]['url'] ?? null) !== $target) return false;
                    $selected++;
                } elseif (($goal['type'] ?? null) === 'action') {
                    if (!is_array($goal['conditions'] ?? null)) return false;
                    foreach ($goal['conditions'] as $condition) {
                        $value = $condition['url'] ?? null; $kind = $condition['type'] ?? null;
                        if (!is_string($value) || $value === '') return false;
                        if (in_array($kind, ['exact', 'action'], true)) { if ($value === $target) return false; }
                        elseif ($kind === 'contain') { if (str_contains($target, $value)) return false; }
                        elseif ($kind === 'start') { if (str_starts_with($target, $value)) return false; }
                        else return false;
                    }
                }
            }
            $this->goalAdmitted = $selected === 1;
            return $this->goalAdmitted;
        } catch (Throwable $ignored) { return false; }
    }

    private static function validReceipt($value): bool
    {
        return is_array($value) && (is_int($value['id'] ?? null) || is_string($value['id'] ?? null))
            && self::id((string)$value['id'])
            && in_array($value['status'] ?? null, ['PREPARED', 'UPLOADED', 'EXPORTED', 'MATCHED', 'PROCESSED', 'LINKAGE_FAILURE'], true)
            && (!array_key_exists('type', $value) || $value['type'] === 'BASIC')
            && (!array_key_exists('client_id_type', $value) || $value['client_id_type'] === 'CLIENT_ID');
    }

    /** One POST only. Any uncertainty remains reserved; this adapter never retries it. */
    public function upload(array $event, int $now): array
    {
        $csv = $this->umk ? self::umkCsv($event, $now) : self::csv($event, $now);
        if (!self::hash($event['csv_sha256'] ?? null)
            || !hash_equals($event['csv_sha256'], hash('sha256', $csv))) {
            throw new InvalidArgumentException('v2_attempt_csv_changed');
        }
        if (!$this->goalAdmitted) throw new RuntimeException('v2_goal_not_admitted');
        if ($this->postAttempted) throw new RuntimeException('v2_post_already_attempted');
        $this->postAttempted = true;
        $unknown = ['outcome' => 'unknown', 'reason' => 'v2_upload_outcome_unknown', 'http_status' => 0, 'automatic_retry_permitted' => false];
        try {
            $comment = $this->umk ? self::umkComment($event) : self::comment($event);
            $response = $this->request('POST', 'offline_conversions/upload?type=BASIC&comment=' . rawurlencode($comment), $csv);
            $http = $response['http'] ?? null;
            if (is_int($http) && ($http === 0 || ($http >= 100 && $http <= 599))) $unknown['http_status'] = $http;
            if (($response['error'] ?? null) !== 0 || !is_int($http)) return $unknown;
            if ($http >= 400 && $http < 500) return ['outcome' => 'rejected', 'reason' => 'v2_api_rejected', 'http_status' => $http, 'automatic_retry_permitted' => false];
            $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $receipt = $data['uploading'] ?? null;
            if ($http >= 200 && $http < 300 && self::validReceipt($receipt)
                && (($receipt['comment'] ?? null) === $comment || (!$this->umk && !array_key_exists('comment', $receipt)))) {
                return ['outcome' => 'accepted', 'upload_id' => (string)$receipt['id'], 'status' => $receipt['status'], 'http_status' => $http,
                    'automatic_retry_permitted' => false, 'visit_linkage_verified' => false];
            }
            return $unknown;
        } catch (Throwable $ignored) { return $unknown; }
    }

    /** Polls only a known exact receipt. Processing is not individual visit-linkage evidence. */
    public function poll(string $uploadId, ?string $eventKey = null): ?array
    {
        if (!self::id($uploadId) || ($this->umk && !self::hash($eventKey))) return null;
        try {
            $response = $this->request('GET', 'offline_conversions/uploading/' . $uploadId);
            if (($response['error'] ?? null) !== 0 || ($response['http'] ?? null) !== 200) return null;
            $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $value = $data['uploading'] ?? null;
            if (!self::validReceipt($value) || (string)$value['id'] !== $uploadId) return null;
            if ($this->umk && ($value['comment'] ?? null) !== self::umkComment(['event_key' => $eventKey])) return null;
            $result = ['upload_id' => $uploadId, 'status' => $value['status'], 'visit_linkage_verified' => false];
            foreach (['source_quantity', 'line_quantity', 'processed_quantity'] as $key) {
                if (array_key_exists($key, $value)) {
                    if (!is_int($value[$key]) || $value[$key] < 0 || $value[$key] > 1) return null;
                    $result[$key] = $value[$key];
                }
            }
            return $result;
        } catch (Throwable $ignored) { return null; }
    }

    /** The optional allocator is only a local fixture seam; production always uses tempnam. */
    private static function privateCsv(string $directory, string $csv, ?callable $allocator = null): string
    {
        $private = realpath($directory);
        if (!is_string($private) || !is_dir($private) || is_link($directory)) {
            throw new RuntimeException('v2_private_csv_unavailable');
        }
        $temp = $allocator === null ? tempnam($private, 'max2-v2-') : $allocator($private, 'max2-v2-');
        try {
            // tempnam may fall back to a system directory; reject that before writing any CSV.
            if (!is_string($temp) || !is_file($temp) || is_link($temp)
                || realpath($temp) !== $temp || dirname($temp) !== $private
                || !chmod($temp, 0600) || file_put_contents($temp, $csv) !== strlen($csv)) {
                throw new RuntimeException('v2_private_csv_unavailable');
            }
            return $temp;
        } catch (Throwable $error) {
            if (is_string($temp) && is_file($temp) && !is_link($temp)) unlink($temp);
            throw new RuntimeException('v2_private_csv_unavailable');
        }
    }

    private function request(string $method, string $path, ?string $csv = null): array
    {
        if (!in_array($method, ['GET', 'POST'], true)) throw new InvalidArgumentException('v2_method_not_admitted');
        if ($method === 'POST' && ($csv === null || strlen($csv) > 1024)) throw new InvalidArgumentException('v2_csv_not_admitted');
        $url = self::API . $path;
        if ($this->transport !== null) {
            $response = ($this->transport)($method, $url, $csv);
            if (!is_array($response) || !is_string($response['body'] ?? null) || strlen($response['body']) > 131072)
                throw new RuntimeException('v2_response_unavailable');
            return $response;
        }
        require_once __DIR__ . '/MaxTrackerLookupConfig.php';
        $temp = null; $curl = null; $body = ''; $tooLarge = false;
        try {
            $curl = curl_init($url);
            if ($curl === false) throw new RuntimeException('v2_transport_unavailable');
            $options = [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Authorization: OAuth ' . $this->token, 'Accept: application/json'],
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > 131072) { $tooLarge = true; return 0; }
                    $body .= $chunk; return strlen($chunk);
                }];
            if ($method === 'POST') {
                $temp = self::privateCsv(MaxTrackerLookupConfig::csvDirectory(), $csv);
                $options[CURLOPT_POST] = true;
                $name = $this->umk ? 'anytour_umk_funnel_value.csv' : 'max2_common_value_v2.csv';
                $options[CURLOPT_POSTFIELDS] = ['file' => new CURLFile($temp, 'text/csv', $name)];
            }
            curl_setopt_array($curl, $options);
            curl_exec($curl);
            return ['http' => (int)curl_getinfo($curl, CURLINFO_HTTP_CODE), 'error' => $tooLarge ? 23 : curl_errno($curl), 'body' => $body];
        } finally {
            if ($curl !== null && $curl !== false) curl_close($curl);
            if (is_string($temp) && is_file($temp)) unlink($temp);
        }
    }
}
