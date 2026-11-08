<?php

declare(strict_types=1);

/**
 * An explicit, immutable snapshot of one server-selected project's settings.
 * Construction validates scope; it does not authorize access or activate routing.
 */
final class ProjectConfiguration
{
    private string $projectKey;
    private array $values;

    public function __construct(string $expectedProjectKey, array $values)
    {
        if (!preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', $expectedProjectKey)) {
            throw new InvalidArgumentException('project_config_invalid_key');
        }
        if (($values['id'] ?? null) !== $expectedProjectKey) {
            throw new InvalidArgumentException('project_config_scope_mismatch');
        }
        $this->projectKey = $expectedProjectKey;
        $this->values = self::copyValue($values, 0);
    }

    public function projectId(): string
    {
        return $this->projectKey;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $path, $default = null)
    {
        return self::valueAtPath($this->values, $path, $default);
    }

    /** Shared with the legacy facade; explicit null, false and zero stay values. */
    public static function valueAtPath(array $values, string $path, $default = null)
    {
        $value = $values;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Break PHP array references as well as ordinary copy-on-write sharing. */
    private static function copyValue($value, int $depth)
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('project_config_too_deep');
        }
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $entry) {
                $copy[$key] = self::copyValue($entry, $depth + 1);
            }
            return $copy;
        }
        if ($value === null || is_string($value) || is_bool($value) || is_int($value)
            || (is_float($value) && is_finite($value))) {
            return $value;
        }
        throw new InvalidArgumentException('project_config_invalid_value');
    }
}
