<?php

declare(strict_types=1);

namespace Bitrix\Main {
    final class Loader
    {
        public static bool $available = true;
        public static function includeModule(string $module): bool
        {
            return $module === 'highloadblock' && self::$available;
        }
    }
}

namespace PaidYclidFixture {
    final class Result
    {
        private array $rows;
        public function __construct(array $rows) { $this->rows = array_values($rows); }
        public function fetch()
        {
            return array_shift($this->rows) ?: false;
        }
    }

    final class Data
    {
        public static array $rows = [];
        public static array $query = [];
        public static function getList(array $query): Result
        {
            self::$query = $query;
            return new Result(self::$rows);
        }
    }

    final class Entity
    {
        public function getDataClass(): string { return Data::class; }
    }
}

namespace Bitrix\Highloadblock {
    final class BlockResult
    {
        public function fetch(): array { return ['ID' => 34, 'NAME' => 'Yclid']; }
    }

    final class HighloadBlockTable
    {
        public static int $requestedId = 0;
        public static function getById(int $id): BlockResult
        {
            self::$requestedId = $id;
            return new BlockResult();
        }
        public static function compileEntity(array $block): \PaidYclidFixture\Entity
        {
            return new \PaidYclidFixture\Entity();
        }
    }
}

namespace {
    require_once dirname(__DIR__).'/services/LegacyPaidYclidReader.php';

    function paidReaderCheck(bool $ok, string $name): void
    {
        if (!$ok) throw new RuntimeException($name);
        echo 'PASS '.$name."\n";
    }

    \PaidYclidFixture\Data::$rows = [
        ['ID' => 9, 'UF_CHATID' => '-111', 'UF_YCLID' => 'CURRENT_SECRET'],
        ['ID' => 8, 'UF_CHATID' => '-222', 'UF_YCLID' => ''],
        ['ID' => 7, 'UF_CHATID' => '-222', 'UF_YCLID' => 'OLD_SECRET'],
        ['ID' => 6, 'UF_CHATID' => '-999', 'UF_YCLID' => 'OUT_OF_SCOPE'],
    ];
    $paid = LegacyPaidYclidReader::paidChatKeys(['-111', '-222'], 34);
    paidReaderCheck($paid === ['-111' => true], 'latest row defines current paid attribution');
    paidReaderCheck(\Bitrix\Highloadblock\HighloadBlockTable::$requestedId === 34, 'configured highload block is used');
    $query = \PaidYclidFixture\Data::$query;
    paidReaderCheck(($query['order']['ID'] ?? null) === 'DESC', 'latest records are read first');
    paidReaderCheck(($query['filter']['@UF_CHATID'] ?? null) === ['-111', '-222'], 'only requested chats are queried');
    paidReaderCheck(($query['limit'] ?? null) === 10001, 'legacy result is bounded');
    paidReaderCheck(strpos((string)json_encode($paid), 'SECRET') === false, 'yclid values never leave reader');

    try {
        LegacyPaidYclidReader::paidChatKeys(['unsafe/path'], 34);
        throw new RuntimeException('expected invalid chat failure');
    } catch (RuntimeException $e) {
        paidReaderCheck($e->getMessage() === 'paid_report_invalid_chat_key', 'invalid chat key fails closed');
    }
    \Bitrix\Main\Loader::$available = false;
    try {
        LegacyPaidYclidReader::paidChatKeys(['-111'], 34);
        throw new RuntimeException('expected unavailable store failure');
    } catch (RuntimeException $e) {
        paidReaderCheck($e->getMessage() === 'paid_report_legacy_store_unavailable', 'unavailable legacy store fails explicitly');
    }
    \Bitrix\Main\Loader::$available = true;

    $snapshotSource = file_get_contents(dirname(__DIR__).'/tools/production_snapshot.php');
    paidReaderCheck(strpos($snapshotSource, 'RuntimeBootstrap::boot($baseDir)') !== false, 'production snapshot boots canonical legacy runtime');
    paidReaderCheck(strpos($snapshotSource, 'LegacyPaidYclidReader::paidChatKeys') !== false, 'production snapshot uses batch legacy reader');
    paidReaderCheck(strpos($snapshotSource, 'current_saved_bitrix_yclid') !== false, 'production attribution basis is explicit');
    echo "LEGACY PAID YCLID READER OK\n";
}
