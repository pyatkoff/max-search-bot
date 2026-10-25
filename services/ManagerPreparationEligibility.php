<?php

declare(strict_types=1);

/** Automatic preparation policy only. Manual AI retains its canonical scope. */
final class ManagerPreparationEligibility
{
    public const WINDOW_SECONDS = 72 * 3600;

    public static function customerSql(string $alias, string $driver): string
    {
        $json="CASE WHEN JSON_VALID({$alias}.metadata_json) THEN {$alias}.metadata_json ELSE '{}' END";
        $type=$driver==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT({$json},'$.type'))":"JSON_EXTRACT({$json},'$.type')";
        // No text-presence test: voice, photos and files remain real input.
        return "{$alias}.direction='inbound' AND {$alias}.sender_type='customer' AND COALESCE({$type},'message') NOT IN ('callback','start','bot_started')";
    }

    public static function latestSql(string $conversation, string $driver): string
    {
        return '(SELECT MAX(input_candidate.id) FROM messages input_candidate WHERE input_candidate.conversation_id='.$conversation.' AND '.self::customerSql('input_candidate',$driver).')';
    }

    /** Two UTC parameters: earliest source message, capture time. */
    public static function sql(string $conversation, string $driver): string
    {
        return 'EXISTS (SELECT 1 FROM messages fresh_input WHERE fresh_input.id='.self::latestSql($conversation,$driver)
            ." AND fresh_input.created_at>=? AND fresh_input.created_at<=? AND fresh_input.id>COALESCE((SELECT MAX(human_reply.id) FROM messages human_reply WHERE human_reply.conversation_id={$conversation} AND human_reply.direction='outbound' AND human_reply.sender_type='manager'),0))";
    }

    public static function window(?string $now=null): array
    {
        $now=$now??gmdate('Y-m-d H:i:s');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$now,new DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d H:i:s')!==$now)throw new RuntimeException('invalid_preparation_time');
        return [gmdate('Y-m-d H:i:s',$date->getTimestamp()-self::WINDOW_SECONDS),$now];
    }

    /** One source row, not a full conversation/photo/knowledge snapshot. */
    public static function input(PDO $pdo,int $cid,?string $now=null): ?string
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $q=$pdo->prepare('SELECT mi.id,mi.text,mi.created_at,mi.channel,mi.metadata_json FROM messages mi WHERE mi.conversation_id=? AND mi.id='
            .self::latestSql('mi.conversation_id',$driver).' AND '.self::sql('mi.conversation_id',$driver));
        $q->execute(array_merge([$cid],self::window($now)));
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;
        $row['id']=(int)$row['id'];
        return hash('sha256',json_encode(['prepared_customer_input_v1',$row],JSON_THROW_ON_ERROR));
    }

    /** Completed input stays handled after TTL or reference/instruction changes. */
    public static function handled(PDO $pdo,int $cid,int $actor,string $project,string $input,bool $pending=false): ?string
    {
        $q=$pdo->prepare("SELECT job_id,status,proposal_json,proposal_sha256 FROM manager_prepared_replies WHERE conversation_id=? AND manager_id=? AND project_key=? AND customer_input_revision=? AND (status='ready'"
            .($pending?" OR (status='pending' AND expires_at>?)":'').') ORDER BY created_at DESC,job_id DESC LIMIT 1');
        $args=[$cid,$actor,$project,$input];if($pending)$args[]=gmdate('Y-m-d H:i:s');
        $q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
        if($row['status']==='ready'&&(!is_string($row['proposal_json'])||!is_string($row['proposal_sha256'])
            ||!hash_equals($row['proposal_sha256'],hash('sha256',$row['proposal_json']))))return null;
        return $row['job_id'];
    }
}
