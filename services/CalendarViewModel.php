<?php

class CalendarViewModel
{
    public static function build($month, $year, ?DateTimeImmutable $today = null): array
    {
        $month = max(1, min(12, (int)$month));
        $year = (int)$year;
        if ($year < 2000) $year = (int)date('Y');
        $today = $today ?: new DateTimeImmutable('today');

        $requested = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $currentMonth = new DateTimeImmutable($today->format('Y-m-01'));
        $monthStart = $requested < $currentMonth ? $currentMonth : $requested;
        $firstSelectable = $monthStart->format('Y-m') === $today->format('Y-m') ? $today : $monthStart;

        $months = [
            1=>'Январь',2=>'Февраль',3=>'Март',4=>'Апрель',5=>'Май',6=>'Июнь',
            7=>'Июль',8=>'Август',9=>'Сентябрь',10=>'Октябрь',11=>'Ноябрь',12=>'Декабрь',
        ];

        $weeks = [];
        $cursor = $firstSelectable;
        while ($cursor->format('Y-m') === $monthStart->format('Y-m')) {
            $weekIndex = (int)$cursor->format('W');
            if (!isset($weeks[$weekIndex])) $weeks[$weekIndex] = array_fill(1, 7, null);
            $weekday = (int)$cursor->format('N');
            $weeks[$weekIndex][$weekday] = [
                'day'=>(int)$cursor->format('j'),
                'date'=>$cursor->format('d.m.Y'),
                'payload'=>'pick_date_'.$cursor->format('d.m.Y'),
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'title'=>$months[(int)$monthStart->format('n')].' '.$monthStart->format('Y'),
            'month'=>$monthStart->format('m'),
            'year'=>$monthStart->format('Y'),
            'previous'=>$monthStart->modify('-1 month')->format('m.Y'),
            'next'=>$monthStart->modify('+1 month')->format('m.Y'),
            'weeks'=>array_values($weeks),
        ];
    }

    public static function buttons(array $model): array
    {
        $dateButtons = [];
        foreach ((array)($model['weeks'] ?? []) as $week) {
            for ($day = 1; $day <= 7; $day++) {
                $cell = $week[$day] ?? null;
                if (!$cell) continue;
                $date = (string)($cell['date'] ?? '');
                $dateButtons[] = [
                    'text'=>substr($date, 0, 5),
                    'callback_data'=>(string)($cell['payload'] ?? ''),
                ];
            }
        }

        $rows = array_chunk($dateButtons, 4);
        $rows[] = [
            ['text'=>'‹','callback_data'=>'month_change_'.(string)$model['previous']],
            ['text'=>'›','callback_data'=>'month_change_'.(string)$model['next']],
        ];
        $rows[] = [['text'=>'← Назад','callback_data'=>'back_nights']];
        return $rows;
    }
}
