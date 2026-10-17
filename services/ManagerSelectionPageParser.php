<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerAiTextRedactor.php';

/** Bounded, non-executing reader for the observed Qui-Quo card template. */
final class ManagerSelectionPageParser
{
    public const MAX_BYTES = 1048576;
    public const MAX_OFFERS = 12;
    private array $nodes = [];
    private bool $truncated = false;

    public static function parse(string $html): array
    {
        $empty = ['state'=>'unread', 'offer_count'=>0, 'offers'=>[], 'truncated'=>false];
        if ($html === '' || strlen($html) > self::MAX_BYTES || preg_match('//u', $html) !== 1) return $empty;
        $p = new self();
        if (!$p->tokenize($html)) return $empty;
        $offers = []; $count = 0;
        foreach ($p->find(0, 'tour') as $card) {
            $variants = $p->find($card, 'details');
            if (!$variants) $variants = [$card];
            $count += count($variants);
            foreach ($variants as $variant) {
                if (count($offers) >= self::MAX_OFFERS) { $p->truncated = true; continue; }
                $hotel = $p->hotel($card);
                if ($hotel === '') { $p->truncated = true; continue; }
                $price = $p->field($variant, 'price', 180);
                $offer = ['hotel'=>$hotel, 'location'=>$p->field($card, 'country', 140),
                    'departure_city'=>$p->field($variant, 'depcity', 140),
                    'dates_text'=>$p->field($variant, 'dates', 180), 'nights_text'=>$p->field($variant, 'nights', 100),
                    'meal'=>$p->field($variant, 'board', 140), 'room_and_travellers'=>$p->field($variant, 'room', 240),
                    'price_text'=>$price, 'price_amount'=>null, 'currency'=>null, 'price_basis'=>null,
                    'operator_text'=>$p->field($variant, 'touroperator', 140), 'flights'=>[]];
                if (preg_match('/\A([0-9][0-9 ]*(?:[.,][0-9]{1,2})?)\s*(RUB|EUR|USD|руб\.?|₽|€)\z/iu', $price, $m)) {
                    $offer['price_amount'] = str_replace([' ', ','], ['', '.'], trim($m[1]));
                    $offer['currency'] = preg_match('/\A(?:RUB|руб\.?|₽)\z/iu',$m[2]) ? 'RUB'
                        : (preg_match('/\A(?:EUR|€)\z/iu',$m[2]) ? 'EUR' : 'USD');
                }
                if ($offer['operator_text']==='') {
                    foreach ($p->find($variant,'touroperator') as $operator) foreach ($p->tags($operator,'img') as $img) {
                        $label=$p->nodes[$img]['label'];
                        if ($label!=='') { $offer['operator_text']=$p->clean($label,140); break 2; }
                    }
                }
                foreach (array_slice($p->find($variant, 'flight-direction'), 0, 2) as $flight) $offer['flights'][] = $p->clean($p->text($flight), 240);
                $offers[] = $offer;
            }
        }
        $state = $offers ? 'observed' : 'unread';
        if (!$offers && preg_match('/(?:подборк[аиу].{0,70}(?:скрыт|недоступн)|не найден|не существует|ошибка 404)/iu', $p->text(0))) $state = 'unavailable';
        return ['state'=>$state, 'offer_count'=>$count, 'offers'=>$offers, 'truncated'=>$p->truncated];
    }

    private function tokenize(string $html): bool
    {
        $html = preg_replace('~<(script|style|svg|template|noscript|textarea)\b[^>]*>.*?</\1\s*>~is', '', $html);
        if (!is_string($html)) return false;
        $pattern = '~<!--.*?-->|<![^>]*>|</?[a-zA-Z][a-zA-Z0-9:-]*(?:"[^"]*"|\x27[^\x27]*\x27|[^\x27">])*>|[^<]+|<~s';
        if (preg_match_all($pattern, $html, $tokens) === false) return false;
        $this->nodes = [['tag'=>'root','class'=>[], 'children'=>[], 'hidden'=>false]]; $stack = [0];
        $void = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];
        foreach ($tokens[0] as $token) {
            $parent = end($stack);
            if (str_starts_with($token, '<!')) continue;
            if (preg_match('~\A</([a-zA-Z0-9:-]+)~', $token, $m)) {
                for ($i=count($stack)-1; $i>0; --$i) if ($this->nodes[$stack[$i]]['tag'] === strtolower($m[1])) { $stack = array_slice($stack, 0, $i); break; }
            } elseif (preg_match('~\A<([a-zA-Z0-9:-]+)\b~', $token, $m)) {
                if (count($this->nodes) >= 20000 || count($stack) >= 64) return false;
                $tag = strtolower($m[1]); $attrs = [];
                preg_match_all('~([a-zA-Z_:][a-zA-Z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s"\x27=<>`]+)))?~', substr($token,strlen($m[0])), $attributes, PREG_SET_ORDER|PREG_UNMATCHED_AS_NULL);
                foreach ($attributes as $a) {
                    $key = strtolower($a[1]);
                    if (!array_key_exists($key,$attrs)) $attrs[$key]=html_entity_decode($a[2]??$a[3]??$a[4]??'', ENT_QUOTES|ENT_HTML5, 'UTF-8');
                }
                $classes = preg_split('/\s+/', trim($attrs['class']??'')) ?: [];
                $hidden = $this->nodes[$parent]['hidden'] || isset($attrs['hidden']) || ($attrs['aria-hidden']??'')==='true'
                    || (bool)array_intersect($classes,['hidden','d-none','hide'])
                    || preg_match('/(?:display\s*:\s*none|visibility\s*:\s*hidden)/i',$attrs['style']??'')===1;
                $id = count($this->nodes); $this->nodes[] = ['tag'=>$tag, 'class'=>$classes, 'children'=>[], 'hidden'=>$hidden,
                    'rel'=>preg_split('/\s+/',trim($attrs['rel']??''))?:[], 'label'=>$attrs['alt']??$attrs['title']??''];
                $this->nodes[$parent]['children'][] = $id;
                if (!in_array($tag, $void, true) && !str_ends_with($token, '/>')) $stack[] = $id;
            } else $this->nodes[$parent]['children'][] = html_entity_decode($token, ENT_QUOTES|ENT_HTML5, 'UTF-8');
        }
        return true;
    }

    private function find(int $node, string $class): array
    {
        if ($this->nodes[$node]['hidden']) return [];
        $out = [];
        foreach ($this->nodes[$node]['children'] as $child) if (is_int($child)) {
            if (!$this->nodes[$child]['hidden'] && in_array($class, $this->nodes[$child]['class'], true)) $out[] = $child;
            array_push($out, ...$this->find($child, $class));
        }
        return $out;
    }

    private function text(int $node): string
    {
        if ($this->nodes[$node]['hidden'] || in_array($this->nodes[$node]['tag'], ['script','style','svg','template','noscript','textarea'], true)) return '';
        $parts = [];
        foreach ($this->nodes[$node]['children'] as $child) $parts[] = is_int($child) ? $this->text($child) : $child;
        return preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', implode(' ', $parts)) ?? '';
    }

    private function field(int $node, string $class, int $limit): string
    {
        $found = $this->find($node, $class);
        return $found ? $this->clean($this->text($found[0]), $limit) : '';
    }

    private function tags(int $node, string $tag): array
    {
        if ($this->nodes[$node]['hidden']) return [];
        $out=[];
        foreach ($this->nodes[$node]['children'] as $child) if (is_int($child) && !$this->nodes[$child]['hidden']) {
            if ($this->nodes[$child]['tag']===$tag) $out[]=$child;
            array_push($out,...$this->tags($child,$tag));
        }
        return $out;
    }

    private function hotel(int $card): string
    {
        $names=$this->find($card,'name');
        if (!$names) return '';
        $hotel=null;
        foreach ($this->tags($names[0],'a') as $a) if (in_array('nofollow',$this->nodes[$a]['rel'],true)) $hotel=$this->text($a);
        return $this->clean($hotel??$this->text($names[0]),180);
    }

    private function clean(string $text, int $limit): string
    {
        $link = false; $redacted = false;
        $text = ManagerAiTextRedactor::redact(trim($text), $link, $redacted);
        $text = preg_replace('/[\x00-\x1f\x7f]/', '', $text) ?? '';
        if (preg_match_all('/./us',$text) > $limit) { $this->truncated = true; return implode('',array_slice(preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[],0,$limit)); }
        return $text;
    }
}
