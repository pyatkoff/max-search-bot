<?php

declare(strict_types=1);

require_once __DIR__ . '/ConversationDb.php';

final class TestConversationProvenance
{
    public static function mark(int $conversationId,string $source,string $reason=''): bool
    {
        if($conversationId<=0)return false;
        $source=trim($source);
        $reason=trim($reason);
        if($source==='')return false;
        $source=substr($source,0,64);
        $reason=$reason!==''?substr($reason,0,255):'';
        $q=ConversationDb::connection()->prepare('UPDATE conversations SET is_test=1,test_source=?,test_reason=? WHERE id=?');
        $q->execute([$source,$reason!==''?$reason:null,$conversationId]);
        return $q->rowCount()>0;
    }

    public static function clear(int $conversationId): bool
    {
        if($conversationId<=0)return false;
        $q=ConversationDb::connection()->prepare('UPDATE conversations SET is_test=0,test_source=NULL,test_reason=NULL WHERE id=?');
        $q->execute([$conversationId]);
        return $q->rowCount()>0;
    }
}
