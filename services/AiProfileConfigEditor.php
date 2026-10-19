<?php

declare(strict_types=1);

/** Only the four owner-approved model/profile literals may change. No config evaluation. */
final class AiProfileConfigEditor
{
    public const TARGETS = [
        'OPENAI_MODEL'=>'gpt-6.1-sol',
        'MANAGER_AI_DRAFT_MODEL'=>'gpt-6.1-sol',
        'MANAGER_AI_DRAFT_REASONING'=>'low',
        'MANAGER_AI_DRAFT_SERVICE_TIER'=>'default',
    ];

    public static function rewrite(string $source, ?array $appendKeys=null): string
    {
        return self::plan($source,$appendKeys??array_keys(self::TARGETS))['candidate'];
    }

    public static function definedKeys(string $source): array
    {
        return self::plan($source,[])['keys'];
    }

    private static function plan(string $source,array $appendKeys): array
    {
        $tokens=token_get_all($source, TOKEN_PARSE);$offset=0;$code=[];$close=null;
        foreach ($tokens as $token) {
            $text=is_array($token)?$token[1]:$token;$id=is_array($token)?$token[0]:null;
            if (in_array($id,[T_FUNCTION,T_FN,T_CLASS,T_TRAIT,T_INTERFACE,T_NAMESPACE],true)) throw new RuntimeException('unsupported_config');
            if ($id===T_CLOSE_TAG) {
                if ($close!==null) throw new RuntimeException('unsupported_config');
                $close=$offset;
            }
            if ($id===T_INLINE_HTML && trim($text)!=='') throw new RuntimeException('unsupported_config');
            if (!in_array($id,[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $code[]=['id'=>$id,'text'=>$text,'offset'=>$offset];
            $offset+=strlen($text);
        }
        if (($code[0]['id']??null)!==T_OPEN_TAG) throw new RuntimeException('unsupported_config');
        $found=[];$edits=[];
        foreach ($code as $i=>$token) {
            $name=null;$value=null;
            if ($token['id']===T_STRING && strtolower($token['text'])==='define'
                && ($code[$i+1]['text']??null)==='(' && ($code[$i+2]['id']??null)===T_CONSTANT_ENCAPSED_STRING) {
                $literal=$code[$i+2]['text'];
                foreach (self::TARGETS as $key=>$_) if ($literal==="'".$key."'" || $literal==='"'.$key.'"') $name=$key;
                if ($name!==null) {
                    if (($code[$i+3]['text']??null)!==',' || ($code[$i+5]['text']??null)!==')'
                        || ($code[$i+6]['text']??null)!==';') throw new RuntimeException('unsupported_profile_definition');
                    $value=$code[$i+4]??null;
                }
            } elseif ($token['id']===T_CONST && isset(self::TARGETS[$code[$i+1]['text']??''])) {
                $name=$code[$i+1]['text'];
                if (($code[$i+2]['text']??null)!=='=' || ($code[$i+4]['text']??null)!==';') throw new RuntimeException('unsupported_profile_definition');
                $value=$code[$i+3]??null;
            }
            if ($name===null) continue;
            if (isset($found[$name]) || ($value['id']??null)!==T_CONSTANT_ENCAPSED_STRING) throw new RuntimeException('unsupported_profile_definition');
            $found[$name]=true;
            $edits[]=[$value['offset'],strlen($value['text']),var_export(self::TARGETS[$name],true)];
        }
        $append='';
        foreach ($appendKeys as $name) {
            if (!array_key_exists($name,self::TARGETS)) throw new RuntimeException('unsupported_profile_key');
            if (!isset($found[$name])) $append.="define('".$name."', ".var_export(self::TARGETS[$name],true).");\n";
        }
        if ($append!=='') $edits[]=[$close??strlen($source),0,"\n".$append];
        usort($edits,static fn(array $a,array $b):int=>$b[0]<=>$a[0]);
        foreach ($edits as [$at,$length,$replacement]) $source=substr_replace($source,$replacement,$at,$length);
        token_get_all($source,TOKEN_PARSE);
        return ['candidate'=>$source,'keys'=>array_keys($found)];
    }
}
