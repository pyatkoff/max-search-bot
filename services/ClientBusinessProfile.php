<?php
declare(strict_types=1);

/** Finite onboarding choices; no inherited company, publication or AI settings. */
final class ClientBusinessProfile
{
    public static function choices(): array
    {
        return [
            'device_repair'=>['kind'=>'device_repair','label'=>'Ремонт техники','description'=>'Обращения к менеджеру с уточнением модели устройства. Свои цены и услуги можно добавить в материалы для сотрудников.'],
            'manager_inbox'=>['kind'=>'manager_inbox','label'=>'Обращения к менеджеру','description'=>'Для компаний любой тематики: общий вопрос и ответ сотрудника. Отраслевой опрос, автоматический расчёт цен и публикации не включены.'],
        ];
    }

    public static function describe($kind): ?array
    {
        return is_string($kind)?(self::choices()[$kind]??null):null;
    }

    public static function settings(string $kind,string $key,string $name): array
    {
        if(self::describe($kind)===null)throw new InvalidArgumentException('unsupported_business_kind');
        $business=['kind'=>$kind];
        if($kind==='device_repair')$business['repair']=['entries'=>[]];
        return ['id'=>$key,'brand'=>['name'=>$name],'business'=>$business,
            'routing'=>['source_key'=>'max:'.$key.'-main'],
            'messenger'=>['max'=>['webhook_url'=>'https://app.anytoour.ru/project_max_webhook.php?project='.$key]]];
    }
}
