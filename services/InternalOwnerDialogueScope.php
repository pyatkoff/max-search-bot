<?php

declare(strict_types=1);

/** Fixed, owner-confirmed demo dialogues; not a name, text or account filter. */
final class InternalOwnerDialogueScope
{
    // The same project/channel/conversation coordinates used by the existing
    // fixed owner preview targets. No native contact identity is stored here.
    private const SCOPES = [['anytour','telegram',1], ['anytour','max',2]];

    public static function conversationSql(string $alias): string
    {
        self::alias($alias);
        $parts=[];
        foreach (self::SCOPES as [$project,$channel,$id]) {
            $parts[]="($alias.project_key='$project' AND $alias.channel='$channel' AND $alias.id=$id)";
        }
        return '('.implode(' OR ',$parts).')';
    }

    /** Only the bound private Telegram peer, never its whole archive account. */
    public static function archiveSql(string $alias): string
    {
        self::alias($alias);
        return 'EXISTS (SELECT 1 FROM conversations internal_owner '
            .'JOIN customer_channels internal_channel ON internal_channel.id=internal_owner.customer_channel_id '
            .'AND internal_channel.customer_id=internal_owner.customer_id '
            .'AND internal_channel.project_key=internal_owner.project_key AND internal_channel.channel=internal_owner.channel '
            .'WHERE '.self::conversationSql('internal_owner')." AND internal_owner.channel='telegram' "
            ."AND $alias.project_key=internal_owner.project_key "
            // HEX keeps exact identity equality independent of connection/column collations.
            ."AND (HEX(CAST($alias.chat_id AS CHAR))=HEX(internal_channel.external_user_id) "
            ."OR HEX(CAST($alias.chat_id AS CHAR))=HEX(internal_channel.external_chat_id)))";
    }

    private static function alias(string $alias): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/D',$alias)!==1) throw new InvalidArgumentException('invalid_internal_scope_alias');
    }
}
