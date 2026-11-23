"""Independent public read of only the two approved existing Waldorf posts."""
import argparse
import hashlib
import html
from html.parser import HTMLParser
import json
import re
import time
from pathlib import Path
from urllib.request import Request, build_opener, HTTPRedirectHandler

URLS = {'telegram':'https://t.me/any_toour/678','max':'https://max.ru/channel_anytour/AaHo8ukNYB8'}
BUTTON = '✈️ Узнать стоимость тура'

def need(ok, why):
    if not ok:
        raise ValueError(why)

def sha(value):
    return hashlib.sha256(value.encode()).hexdigest()

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def fetch(url):
    need(url in [URLS['max'],'https://t.me/any_toour/678?embed=1&mode=tme','https://t.me/s/any_toour/678'], 'public_url_scope')
    with build_opener(NoRedirect()).open(Request(url,headers={'User-Agent':'Mozilla/5.0','Accept':'text/html'}),timeout=15) as response:
        data = response.read(4_000_001)
        need(response.status == 200 and response.geturl() == url and 0 < len(data) <= 4_000_000, 'public_http_unavailable')
        return data.decode('utf-8')

class Telegram(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.depth = 0
        self.root = None
        self.capture = None
        self.roots = 0
        self.texts = 0
        self.parts = []
        self.buttons = []
        self.button = None
        self.photos = 0

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        classes = attrs.get('class','').split()
        if tag == 'div':
            self.depth += 1
            if attrs.get('data-post') == 'any_toour/678':
                self.roots += 1
                self.root = self.depth
            if self.root is not None and 'tgme_widget_message_text' in classes:
                self.texts += 1
                self.capture = self.depth
        elif tag == 'br' and self.capture is not None:
            self.parts.append('\n')
        if self.root is not None and tag == 'a':
            if 'tgme_widget_message_inline_button' in classes:
                need(self.button is None, 'nested_public_button')
                self.button = {'url':attrs.get('href',''),'text':''}
            if 'tgme_widget_message_photo_wrap' in classes:
                self.photos += 1

    def handle_endtag(self, tag):
        if tag == 'a' and self.button is not None:
            self.button['text'] = self.button['text'].strip()
            self.buttons.append(self.button)
            self.button = None
        if tag == 'div':
            if self.capture == self.depth:
                self.capture = None
            if self.root == self.depth:
                self.root = None
            self.depth -= 1

    def handle_data(self, value):
        if self.capture is not None:
            self.parts.append(value)
        if self.button is not None:
            self.button['text'] += value

def telegram_caption(body):
    parser = Telegram()
    parser.feed(body)
    parser.close()
    need(parser.roots == parser.texts == 1 and parser.capture is None and parser.root is None, 'exact_public_telegram_post_unavailable')
    need(parser.photos == 1, 'public_telegram_photo_unavailable')
    return ''.join(parser.parts), parser.buttons

def max_caption(body):
    values = re.findall(r'message:\{text:("(?:[^"\\]|\\.)*")',body)
    need(len(values) == 1, 'exact_public_max_post_unavailable')
    text = json.loads(values[0])
    need(isinstance(text,str), 'public_max_text_invalid')
    return html.unescape(text)

def targets(data, phase):
    rows = data.get('posts') if phase == 'before' else data.get('rows')
    need(isinstance(rows,list) and len(rows) == 2 and {x['platform'] for x in rows} == set(URLS), 'public_target_count')
    result = {}
    for item in rows:
        platform = item['platform']
        need(item['hotel_id'] == 66198 and item['post_url'] == URLS[platform], 'public_target_scope')
        text = item['caption'] if phase == 'before' else item['new_text']
        expected = item['caption_sha256'] if phase == 'before' else item['new_text_sha256']
        need(sha(text) == expected, 'public_expected_caption_hash')
        result[platform] = {'caption_sha256':expected,'button_url_sha256':item['button_url_sha256']}
    return result

def observe(targets):
    posts = []
    for platform in ['telegram','max']:
        urls = ['https://t.me/any_toour/678?embed=1&mode=tme','https://t.me/s/any_toour/678'] if platform == 'telegram' else [URLS['max']]
        failures = []
        for url in urls:
            try:
                body = fetch(url)
                if platform == 'telegram':
                    caption, buttons = telegram_caption(body)
                    need(len(buttons) == 1 and buttons[0]['text'] == BUTTON
                         and sha(buttons[0]['url']) == targets[platform]['button_url_sha256'], 'public_telegram_button_mismatch')
                else:
                    caption = max_caption(body)
                need(sha(caption) == targets[platform]['caption_sha256'], 'public_caption_mismatch')
                posts.append({'platform':platform,'post_url':URLS[platform],'public_read_url':url,
                              'caption_sha256':sha(caption),'button_verified':platform == 'telegram',
                              'http_status':200,'html_sha256':sha(body)})
                break
            except Exception as error:
                failures.append(type(error).__name__+':'+str(error)[:120])
        else:
            raise ValueError(platform+':'+','.join(failures))
    return posts

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--phase',choices=['before','after'],required=True)
    parser.add_argument('--input',type=Path,required=True)
    parser.add_argument('--out',type=Path,required=True)
    args = parser.parse_args()
    expected = targets(json.loads(args.input.read_bytes()),args.phase)
    result = {'operation':'commercial-waldorf66198-caption-correction-20261123-v1',
              'phase':args.phase,'ok':False,'post_write_attempts':0,'provider_edits':0,'checks':[]}
    for attempt in range(1,4 if args.phase == 'after' else 2):
        try:
            result['posts'] = observe(expected)
            result['ok'] = True
            break
        except Exception as error:
            result['checks'].append({'attempt':attempt,'error':str(error)[:400]})
            if args.phase == 'after' and attempt < 3:
                time.sleep(3)
    result['observed_at_utc'] = time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())
    args.out.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))
    raise SystemExit(0 if result['ok'] else 2)

if __name__ == '__main__':
    main()
