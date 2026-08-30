<?php

declare(strict_types=1);

$manifest = require __DIR__ . '/required_checks_manifest.php';

if (!is_array($manifest)) {
    throw new RuntimeException('Required-check manifest must return an array');
}

$catalog = [];
foreach ($manifest as $group => $commands) {
    if (!is_string($group) || !is_array($commands)) {
        throw new RuntimeException('Invalid required-check manifest group');
    }

    $catalog[$group] = [];
    foreach ($commands as $command) {
        if (!is_string($command) || trim($command) === '') {
            throw new RuntimeException('Invalid required-check command in group ' . $group);
        }

        $normalized = preg_replace('/\s+/', ' ', trim($command)) ?: trim($command);
        $parts = preg_split('/\s+/', $normalized) ?: [];
        $target = (string)($parts[1] ?? $parts[0] ?? 'check');
        $base = pathinfo($target, PATHINFO_FILENAME);
        $args = array_slice($parts, 2);
        $suffix = $args ? '-' . implode('-', array_map(static fn(string $value): string => preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)) ?: 'arg', $args)) : '';
        $id = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $base) ?: 'check') . $suffix;

        $type = 'regression';
        if (str_contains($base, 'smoke')) {
            $type = 'smoke';
        } elseif ($base === 'run_scenarios') {
            $type = 'scenario';
        } elseif (str_contains($base, 'inventory') || str_contains($base, 'contract')) {
            $type = 'contract';
        }

        $catalog[$group][] = [
            'id' => $id,
            'group' => $group,
            'type' => $type,
            'required' => true,
            'command' => $normalized,
        ];
    }
}

return $catalog;
