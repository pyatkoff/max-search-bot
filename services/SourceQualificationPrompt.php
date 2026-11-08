<?php
declare(strict_types=1);

require_once __DIR__.'/MissingFieldQuestionService.php';

/** Presentation from the current server-resolved post, never an offer or trip default. */
final class SourceQualificationPrompt
{
    public static function publicationContext(?array $post): array
    {
        if (!$post || ($post['project_key'] ?? '') !== 'anytour'
            || !in_array($post['platform'] ?? '', ['max', 'telegram'], true)
            || (int)($post['publication_id'] ?? 0) < 1 || (int)($post['source_id'] ?? 0) < 1
            || (int)($post['version_id'] ?? 0) < 1 || empty($post['source_key'])) return [];
        return [
            'publication_id'=>(int)$post['publication_id'], 'source_id'=>(int)$post['source_id'],
            'version_id'=>(int)$post['version_id'],
            'source_key'=>(string)$post['source_key'], 'platform'=>(string)$post['platform'],
            'hotel_name'=>self::hotelName((array)($post['snapshot'] ?? [])),
        ];
    }

    private static function hotelName(array $snapshot): string
    {
        $first = preg_split('/\\R/u', (string)($snapshot['text'] ?? ''), 2)[0] ?? '';
        foreach ([(string)($snapshot['title'] ?? ''), $first] as $heading) {
            $name = trim(html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $name = preg_replace('/\\A[^\\p{L}\\p{N}]+/u', '', $name) ?? '';
            $name = preg_replace('/\\AТуры в (.+?)(?: с перел[её]том)?\\z/u', '$1', $name) ?? '';
            // New hotel headings may append a destination after a separator.
            $name = preg_replace('/\\s*[1-5]\\s*[★⭐*]\\s*[|｜]\\s*[^\\r\\n]+\\z/u', '', $name) ?? '';
            $name = preg_replace('/\\s*[1-5]\\s*[★⭐*](?:[^\\p{L}\\p{N}]*)\\z/u', '', $name) ?? '';
            $name = preg_replace('/\\s*[\\x{1F1E6}-\\x{1F1FF}]{2}\\s*\\z/u', '', $name) ?? '';
            $name = trim($name);
            // Owned hotel-post headings use a Latin property name. An ordinary
            // caption, CTA or absent heading must not become a guessed hotel.
            if (preg_match("~\\A[A-Za-z0-9][\\p{L}\\p{N} &'+().,/-]{1,179}\\z~u", $name) === 1
                && preg_match('/[A-Za-z]/', $name) === 1) return $name;
        }
        return '';
    }

    public static function choiceText(string $hotelName, bool $greet = true): string
    {
        $name = htmlspecialchars(trim(str_replace(["\r", "\n"], ' ', $hotelName)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return ($greet ? "Здравствуйте! 👋\n\n" : '')
            .'Вас интересует тур в '.$name.' или подобрать другой отель?';
    }

    public static function text(array $missing, string $hotelName = '', bool $greet = true): string
    {
        $missing = array_fill_keys(array_intersect(
            ['date','adults','children','child_ages','nights','city','country'], $missing
        ), true);
        // A hotel heading is not a canonical country value. Keep a missing
        // destination actionable, including after a button-only hotel choice.
        $questions = [];
        if (isset($missing['date'])) {
            $questions[] = isset($missing['adults'])
                ? 'Когда планируете отдых? Можно указать примерные даты.'
                : 'Когда планируете отдых? Можно указать примерные даты.';
        }
        if (isset($missing['adults'], $missing['children'])) {
            $questions[] = 'Сколько взрослых и детей поедет? Если будут дети, укажите их возраст.';
            unset($missing['child_ages']);
        } elseif (isset($missing['adults'])) {
            $questions[] = MissingFieldQuestionService::question('adults');
        } elseif (isset($missing['children'])) {
            $questions[] = 'Будут дети? Если да, укажите их количество и возраст.';
            unset($missing['child_ages']);
        }
        foreach (['child_ages','nights','city','country'] as $field) {
            if (isset($missing[$field])) $questions[] = $field === 'country' && $hotelName !== ''
                ? 'Уточните страну поездки, пожалуйста.' : MissingFieldQuestionService::question($field);
        }
        $hotelName = trim(str_replace(["\r", "\n"], ' ', $hotelName));
        $hotel = $hotelName === '' ? '' : htmlspecialchars($hotelName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $intro = $greet ? "Здравствуйте! 👋\n" : '';
        if ($hotel !== '') $intro .= 'Помогу подобрать тур в '.$hotel.".\n\n";
        $question = $questions
            ? $questions[0]
            : ($hotel !== '' ? 'Что хотите уточнить по этому отелю?' : 'Что хотите уточнить по этому варианту?');
        return $intro.$question;
    }
}
