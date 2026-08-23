<?php

class ShadowComparisonReport
{
    public static function build(string $structuredLog, int $limit = 200): array
    {
        $events = self::readJsonLines($structuredLog, 4000);
        $pending = [];
        $pairs = [];

        foreach ($events as $event) {
            $component = (string)($event['component'] ?? '');
            $name = (string)($event['event'] ?? '');
            $chat = (string)($event['chat_id'] ?? '');
            if ($chat === '') continue;

            if ($component === 'dialogue_v2_shadow' && $name === 'message_evaluated') {
                $pending[$chat] = $event;
                continue;
            }

            if ($component === 'legacy_dialogue' && $name === 'outcome' && isset($pending[$chat])) {
                $shadow = $pending[$chat];
                unset($pending[$chat]);
                $s = (array)($shadow['data'] ?? []);
                $l = (array)($event['data'] ?? []);
                $shadowAction = (string)($s['rule_action'] ?? '');
                $legacyAction = (string)($l['action'] ?? '');
                if ($shadowAction === '' || $legacyAction === '') continue;

                $pairs[] = [
                    'ts'=>$shadow['ts'] ?? null,
                    'chat_id'=>$chat,
                    'message'=>$s['message'] ?? '',
                    'shadow'=>[
                        'intent'=>$s['extracted']['intent'] ?? null,
                        'changes'=>$s['extracted']['changes'] ?? [],
                        'action'=>$shadowAction,
                        'missing'=>$s['missing'] ?? [],
                        'next_field'=>$s['next_field'] ?? null,
                        'reason'=>$s['reason'] ?? null,
                    ],
                    'legacy'=>[
                        'action'=>$legacyAction,
                        'confidence'=>$l['confidence'] ?? null,
                        'reason'=>$l['reason'] ?? null,
                        'text'=>$l['text'] ?? '',
                        'buttons'=>$l['buttons'] ?? [],
                    ],
                    'same_action'=>$shadowAction === $legacyAction,
                ];
            }
        }

        if (count($pairs) > $limit) $pairs = array_slice($pairs, -$limit);
        $matched = 0;
        $byShadow = [];
        $matrix = [];
        foreach ($pairs as $pair) {
            if ($pair['same_action']) $matched++;
            $s = $pair['shadow']['action'];
            $l = $pair['legacy']['action'];
            if (!isset($byShadow[$s])) $byShadow[$s] = ['total'=>0,'same'=>0];
            $byShadow[$s]['total']++;
            if ($s === $l) $byShadow[$s]['same']++;
            if (!isset($matrix[$s])) $matrix[$s] = [];
            $matrix[$s][$l] = ($matrix[$s][$l] ?? 0) + 1;
        }
        foreach ($byShadow as &$row) {
            $row['agreement_pct'] = $row['total'] ? round(100 * $row['same'] / $row['total'], 1) : null;
        }
        unset($row);

        $mismatches = array_values(array_filter($pairs, static function($p){ return !$p['same_action']; }));
        if (count($mismatches) > 50) $mismatches = array_slice($mismatches, -50);

        return [
            'ok'=>true,
            'generated_at'=>date('c'),
            'note'=>'Legacy action is classified from the actual successfully sent MAX response; classifier confidence is included.',
            'summary'=>[
                'paired_messages'=>count($pairs),
                'same_action'=>$matched,
                'different_action'=>count($pairs)-$matched,
                'agreement_pct'=>count($pairs) ? round(100 * $matched / count($pairs), 1) : null,
                'unpaired_shadow'=>count($pending),
            ],
            'by_shadow_action'=>$byShadow,
            'action_matrix'=>$matrix,
            'mismatches'=>$mismatches,
            'recent_pairs'=>array_slice($pairs, -50),
        ];
    }

    public static function write(string $structuredLog, string $output): bool
    {
        $report = self::build($structuredLog);
        $tmp = $output . '.tmp';
        $json = json_encode($report, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        @chmod($tmp, 0644);
        return rename($tmp, $output);
    }

    private static function readJsonLines(string $file, int $maxLines): array
    {
        if (!is_file($file) || !is_readable($file)) return [];
        $lines = file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return [];
        if (count($lines) > $maxLines) $lines = array_slice($lines, -$maxLines);
        $out = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) $out[] = $row;
        }
        return $out;
    }
}
