FIX8B: если yclid/traffic meta отсутствуют, buildChannelMiniappUrl() теперь
возвращает URL бота с ?startapp=0 вместо прямого static::$chanelUrl.
Остальной FIX8 не менялся.
