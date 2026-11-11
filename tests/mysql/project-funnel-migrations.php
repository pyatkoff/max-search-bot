<?php
declare(strict_types=1);

/** Called only by the existing isolated native-MySQL migrations job. */
function nativeProjectFunnelMigrationChecks(PDO $pdo): void
{
    if (!preg_match('/\Amaxbot_prepared_test(?:_[a-zA-Z0-9_]+)?\z/D', (string)$pdo->query('SELECT DATABASE()')->fetchColumn())) {
        throw new RuntimeException('unsafe_funnel_migration_fixture');
    }
    require_once dirname(__DIR__, 2) . '/services/MigrationRunner.php';
    $root = dirname(__DIR__, 2);
    $legacy = '086_modeled_conversion_value_export.sql';
    $old = ProjectFunnelContextMigrationRepair::ORIGINAL;
    $repair = ProjectFunnelContextMigrationRepair::REPAIR;
    $tables = ['schema_migrations', 'modeled_conversion_value_export_settings', 'max_channel_conversion_outbox',
        'max_channel_attribution_settings', 'advertising_visit_observations', 'advertising_visit_cursors'];
    $check = static function (bool $ok, string $reason): void {
        if (!$ok) throw new RuntimeException('funnel_native_' . $reason);
    };
    $runSql = static function (string $sql) use ($pdo): void {
        foreach (preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [] as $statement) {
            if (trim($statement) !== '') $pdo->exec(trim($statement));
        }
    };
    $snapshot = static function () use ($pdo): array {
        return [
            'observations' => $pdo->query('SELECT * FROM advertising_visit_observations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'outbox' => $pdo->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ];
    };
    $preserved = static function (array $before, array $after) use ($check): void {
        foreach ($before as $table => $rows) {
            $check(count($after[$table]) === count($rows), $table . '_row_count_changed');
            foreach ($rows as $i => $row) $check(array_intersect_key($after[$table][$i], $row) === $row, $table . '_legacy_bytes_changed');
        }
    };
    $journal = static fn(): array => $pdo->query('SELECT version,checksum,baseline,execution_ms,applied_at FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
    $refuses = static function (callable $call, string $contains) use ($check): void {
        try { $call(); } catch (RuntimeException $error) {
            $check(str_contains($error->getMessage(), $contains), 'unexpected_refusal_' . $error->getMessage());
            return;
        }
        throw new RuntimeException('funnel_native_missing_refusal_' . $contains);
    };

    foreach (['fresh', 'partial', 'wrong_context', 'literal_null_default', 'quoted_empty_default', 'missing_086', 'changed_086', 'changed_104', 'changed_105', 'legacy_baseline'] as $mode) {
        // Refuse an occupied fixture before acquiring any table ownership.
        foreach ($tables as $table) {
            $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $q->execute([$table]);
            $check((int)$q->fetchColumn() === 0, 'occupied_fixture_' . $table);
        }
        $dir = sys_get_temp_dir() . '/funnel-migration-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) throw new RuntimeException('funnel_native_temp_directory');
        $owned = [];
        try {
            foreach ([$legacy, $old, $repair] as $file) {
                if (!copy($root . '/migrations/' . $file, $dir . '/' . $file)) throw new RuntimeException('funnel_native_copy');
            }
            // Only these known test tables are owned after the absence check.
            $owned = $tables;
            $runSql((string)file_get_contents($root . '/migrations/063_advertising_visits.sql'));
            $runSql((string)file_get_contents($root . '/migrations/037_max_channel_conversion_outbox.sql'));
            $runSql((string)file_get_contents($root . '/migrations/' . $legacy));
            $runner = new MigrationRunner($dir, $pdo);
            $runner->ensureTable();
            $pdo->prepare('INSERT INTO schema_migrations(version,checksum,baseline,execution_ms) VALUES(?,?,1,0)')
                ->execute([$legacy, hash_file('sha256', $dir . '/' . $legacy)]);
            $pdo->exec("INSERT INTO advertising_visit_observations(project_key,source_key,platform,counter_id,source_cursor,visit_id,visit_at_unix,observed_at_unix,identity_status,payload_sha256,received_at_unix) VALUES('funnel_fixture','fixture','max','1','1','fixture',1,1,'verified',REPEAT('a',64),1)");
            // Requires all ten existing integer digits: narrowing to DECIMAL(11,2)
            // would lose valid legacy values.
            $pdo->exec("INSERT INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,event_unix,value_rub) VALUES('funnel_fixture',1,'1','1','fixture',1,1234567890.12)");

            if ($mode === 'partial') {
                $collision = false;
                try { $runSql((string)file_get_contents($dir . '/' . $old)); }
                catch (PDOException $error) { $collision = (int)($error->errorInfo[1] ?? 0) === 1060 && str_contains($error->getMessage(), 'value_rub'); }
                $check($collision, 'raw_086_104_collision_not_reproduced');
                $pdo->exec("UPDATE advertising_visit_observations SET post_ref='retained-post',offer_ref='retained-offer',context_json='{\"retained\":true}'");
                // Also exercise interruption during 105: one valid outbox
                // context column can already exist with data, without a receipt.
                $pdo->exec('ALTER TABLE max_channel_conversion_outbox ADD COLUMN funnel_context_json TEXT NULL');
                $pdo->exec("UPDATE max_channel_conversion_outbox SET funnel_context_json='retained context'");
            } elseif ($mode === 'wrong_context') {
                $pdo->exec("ALTER TABLE advertising_visit_observations ADD COLUMN post_ref VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''");
            } elseif ($mode === 'literal_null_default') {
                $pdo->exec("ALTER TABLE max_channel_conversion_outbox ADD COLUMN funnel_policy_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT 'NULL'");
            } elseif ($mode === 'quoted_empty_default') {
                $pdo->exec("ALTER TABLE advertising_visit_observations ADD COLUMN post_ref VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''''''");
            } elseif ($mode === 'missing_086' || $mode === 'legacy_baseline') {
                $pdo->exec('DELETE FROM schema_migrations');
                if ($mode === 'missing_086') {
                    // A different accepted version avoids the old initial-baseline path.
                    $pdo->exec("INSERT INTO schema_migrations(version,checksum,baseline,execution_ms) VALUES('fixture-history.sql',REPEAT('a',64),0,0)");
                }
            } elseif ($mode === 'changed_086') {
                $pdo->exec("UPDATE schema_migrations SET checksum=REPEAT('b',64)");
            } elseif ($mode === 'changed_104' || $mode === 'changed_105') {
                $file = $mode === 'changed_104' ? $old : $repair;
                file_put_contents($dir . '/' . $file, "\n-- altered fixture\n", FILE_APPEND);
            }
            $before = $snapshot();
            $journalBefore = $journal();
            if (!in_array($mode, ['fresh', 'partial'], true)) {
                $reason = match ($mode) {
                    'wrong_context', 'literal_null_default', 'quoted_empty_default' => 'column mismatch',
                    'missing_086' => 'accepted 086 receipt',
                    'changed_086' => 'Applied migration was modified',
                    'legacy_baseline' => 'accepted migration history',
                    default => 'source changed',
                };
                $call = $mode === 'missing_086'
                    ? static fn() => (new ProjectFunnelContextMigrationRepair($pdo, $dir))->apply()
                    : static fn() => $runner->migrate();
                $refuses($call, $reason);
                $check($snapshot() === $before && $journal() === $journalBefore, $mode . '_wrote_data_or_receipts');
                $check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='advertising_visit_observations' AND COLUMN_NAME='offer_ref'")->fetchColumn() === 0, $mode . '_ddl_before_refusal');
                echo 'PASS native MySQL funnel repair refuses ' . $mode . " before DDL or receipt writes\n";
                continue;
            }

            $result = $runner->migrate();
            $check($result['baselined'] === [$old] && count($result['executed']) === 1
                && $result['executed'][0]['version'] === $repair && $result['pending'] === [], 'repair_classification');
            $preserved($before, $snapshot());
            $check($pdo->query('SELECT value_rub FROM max_channel_conversion_outbox')->fetchColumn() === '1234567890.12', 'legacy_value_narrowed');
            $rows = array_column($journal(), null, 'version');
            $check((int)$rows[$old]['baseline'] === 1 && (int)$rows[$old]['execution_ms'] === 0
                && $rows[$old]['checksum'] === hash_file('sha256', $dir . '/' . $old)
                && (int)$rows[$repair]['baseline'] === 0
                && $rows[$repair]['checksum'] === hash_file('sha256', $dir . '/' . $repair)
                && $rows[$legacy] === array_column($journalBefore, null, 'version')[$legacy], 'durable_adoption_or_legacy_receipt_changed');
            $stableRows = $snapshot();
            $stableJournal = $journal();
            $again = $runner->migrate();
            $check($again === ['baselined' => [], 'executed' => [], 'pending' => []]
                && $snapshot() === $stableRows && $journal() === $stableJournal, 'repeat_not_stable');

            // Reconcile an already recorded 105 with a missing 104 baseline:
            // the actual repair must not execute again.
            $pdo->prepare('DELETE FROM schema_migrations WHERE version=?')->execute([$old]);
            $resume = $runner->migrate();
            $check($resume['baselined'] === [$old] && $resume['executed'] === [] && $resume['pending'] === []
                && $snapshot() === $stableRows, 'recorded_105_replayed');
            $resumedJournal = $journal();
            file_put_contents($dir . '/' . $old, "\n-- changed after adoption\n", FILE_APPEND);
            $refuses(static fn() => $runner->migrate(), 'Applied migration was modified');
            $check($snapshot() === $stableRows && $journal() === $resumedJournal, 'applied_checksum_failure_wrote');
            echo 'PASS native MySQL funnel ' . $mode . " repair preserves legacy rows/DECIMAL(12,2), explicit 104 baseline, actual 105 and stable resume\n";
        } finally {
            foreach ($owned as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $table);
            foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
            rmdir($dir);
        }
    }
}
