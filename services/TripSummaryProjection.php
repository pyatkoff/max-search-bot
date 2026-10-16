<?php

/** Display-only snapshot of the summary actually sent, not current search state. */
final class TripSummaryProjection
{
    public static function metadata(array $lines): array
    {
        return ['trip_summary'=>['version'=>1,'text'=>self::plainText(implode("\n",$lines))]];
    }

    /** null means not a recognized summary; an empty snapshot is still valid. */
    public static function fromMessage(array $message): ?string
    {
        $metadata=json_decode((string)($message['metadata_json']??''),true);
        if(is_array($metadata)&&array_key_exists('trip_summary',$metadata)){
            $summary=$metadata['trip_summary'];
            if(!is_array($summary)||($summary['version']??null)!==1||!is_string($summary['text']??null))return null;
            return self::normalizeWhitespace($summary['text']);
        }
        $text=(string)($message['text']??'');
        // Compatibility with untagged historical messages only. New snapshots
        // never infer their identity/body from presentation copy or markup.
        if(!preg_match('/Готово! Проверьте параметры/ui',$text))return null;
        $text=html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=preg_replace('/^\s*✅\s*Готово!\s*Проверьте параметры\s*/ui','',$text)??$text;
        $text=preg_replace('/\s*Что удобнее дальше\?\s*$/ui','',$text)??$text;
        return self::normalizeWhitespace($text);
    }

    private static function plainText(string $text): string
    {
        $text=html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return self::normalizeWhitespace($text);
    }

    private static function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u',' ',$text)??$text);
    }
}
