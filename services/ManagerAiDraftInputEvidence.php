<?php

declare(strict_types=1);

/** Exact private text input, with an explicit non-replayable image manifest. No I/O. */
final class ManagerAiDraftInputEvidence
{
    public const MAX_BYTES = 262144;
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    private const IMAGE_BYTES = 6291456;

    private function __construct(private readonly string $serialized, private readonly array $context,
        private readonly string $instructionsHash) {}

    /** Use only the already normalized arguments actually supplied to the provider. */
    public static function capture(array $context, string $instructions, array $images = []): ?self
    {
        try {
            if (!array_is_list($images) || count($images) > 4) return null;
            $manifest = []; $imageBytes = 0;
            foreach ($images as $index => $image) {
                if (!is_array($image) || array_keys($image) !== ['type','image_url','detail']
                    || $image['type'] !== 'input_image' || $image['detail'] !== 'high'
                    || !is_string($image['image_url'])
                    || preg_match('~\Adata:(image/(?:jpeg|png|webp));base64,[A-Za-z0-9+/]+={0,2}\z~D', $image['image_url'], $parts) !== 1) return null;
                $size = strlen($image['image_url']); $imageBytes += $size;
                if ($imageBytes > self::IMAGE_BYTES) return null;
                $manifest[] = ['image_index'=>$index+1,'type'=>'input_image','mime_type'=>$parts[1],
                    'detail'=>'high','payload_sha256'=>hash('sha256', $image['image_url']),'payload_bytes'=>$size];
            }
            $value = ['schema_version'=>1,'context'=>$context,'instructions'=>$instructions,
                'instructions_sha256'=>hash('sha256', $instructions),
                'coverage'=>$manifest ? 'images_omitted' : 'text_only','images'=>$manifest];
            $json = json_encode($value, self::JSON_FLAGS, 64);
            return self::fromJson($json);
        } catch (Throwable $ignored) { return null; }
    }

    /** Strict persisted-envelope validation; this is not source authenticity or send approval. */
    public static function fromJson(string $json): ?self
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) return null;
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($value) || array_keys($value) !== ['schema_version','context','instructions','instructions_sha256','coverage','images']
                || $value['schema_version'] !== 1 || !self::validContext($value['context'])
                || !is_string($value['instructions']) || $value['instructions'] === ''
                || !self::digest($value['instructions_sha256'])
                || !hash_equals(hash('sha256', $value['instructions']), $value['instructions_sha256'])
                || !is_array($value['images']) || !array_is_list($value['images']) || count($value['images']) > 4
                || $value['coverage'] !== ($value['images'] ? 'images_omitted' : 'text_only')) return null;
            $imageBytes = 0;
            foreach ($value['images'] as $index => $image) {
                if (!is_array($image) || array_keys($image) !== ['image_index','type','mime_type','detail','payload_sha256','payload_bytes']
                    || $image['image_index'] !== $index+1 || $image['type'] !== 'input_image'
                    || !in_array($image['mime_type'], ['image/jpeg','image/png','image/webp'], true)
                    || $image['detail'] !== 'high' || !self::digest($image['payload_sha256'])
                    || !is_int($image['payload_bytes']) || $image['payload_bytes'] <= 0) return null;
                $imageBytes += $image['payload_bytes'];
                if ($imageBytes > self::IMAGE_BYTES) return null;
            }
            // Only our exact encoding is accepted: duplicate keys, alternate numeric
            // forms and hidden object/list conversions must not survive readback.
            if (json_encode($value, self::JSON_FLAGS, 64) !== $json) return null;
            return new self($json, $value['context'], $value['instructions_sha256']);
        } catch (Throwable $ignored) { return null; }
    }

    public function json(): string { return $this->serialized; }
    public function hash(): string { return hash('sha256', $this->serialized); }
    public function instructionHash(): string { return $this->instructionsHash; }
    public function context(): array { return $this->context; }

    /** Exact ordered source window, not merely a subset of citation IDs. */
    public function matches(int $cid, int $latest, array $allowedIds): bool
    {
        return $cid === $this->context['conversation_id'] && $latest === $this->context['as_of_message_id']
            && array_is_list($allowedIds) && $allowedIds === array_column($this->context['messages'], 'id');
    }

    private static function validContext(mixed $context): bool
    {
        if (!is_array($context) || array_is_list($context)
            || !is_int($context['conversation_id'] ?? null) || $context['conversation_id'] <= 0
            || !is_int($context['as_of_message_id'] ?? null) || $context['as_of_message_id'] <= 0
            || !is_bool($context['history_incomplete'] ?? null)
            || !is_bool($context['media_unread'] ?? null) || !is_bool($context['selections_link_unread'] ?? null)
            || !is_array($context['verified_company_facts'] ?? null)
            || !is_array($context['messages'] ?? null) || !array_is_list($context['messages'])
            || !$context['messages'] || count($context['messages']) > 120) return false;
        $previous = 0; $characters = 0;
        foreach ($context['messages'] as $message) {
            if (!is_array($message) || !is_int($message['id'] ?? null) || $message['id'] <= $previous
                || $message['id'] > $context['as_of_message_id']
                || !in_array($message['sender_type'] ?? null, ['customer','manager','ai','system'], true)
                || !is_string($message['text'] ?? null)) return false;
            $length = preg_match_all('/./us', $message['text']);
            if ($length === false || ($characters += $length) > 40000) return false;
            foreach (['media_unread','selections_link_unread','text_truncated'] as $flag) {
                if (array_key_exists($flag, $message) && !is_bool($message[$flag])) return false;
            }
            if (!empty($message['text_truncated']) && !$context['history_incomplete']) return false;
            $previous = $message['id'];
        }
        return $previous === $context['as_of_message_id'];
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
}
