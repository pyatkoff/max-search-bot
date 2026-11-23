"""Compare approved public MAX HTML with fresh native captions; never edit."""
import argparse
import hashlib
import html
from html.parser import HTMLParser
import json
import re
import time
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, build_opener, HTTPRedirectHandler

TARGETS = {
    2335: 'AaHrdoapUS4', 2603: 'AaHrhpRoZFA', 2602: 'AaHrhsP3ZH0',
    11751: 'AaHrhs2cFoI', 11775: 'AaHrhtZ3c8U', 64918: 'AaHryMfSWo0',
    11791: 'AaHryNICXpg', 1703: 'AaHryO4tMO0', 2721: 'AaHryQEifWQ',
    33531: 'AaHryf1LGsY', 11847: 'AaHryhBQBrA', 17608: 'AaHryiUrDVM',
    236: 'AaHsBQznQBg',
}


def digest(value):
    return hashlib.sha256(value.encode('utf-8')).hexdigest()


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Page(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.metadata = []
        self.ignored = 0
        self.visible = []

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if tag in ('script', 'style', 'noscript'):
            self.ignored += 1
        if tag == 'meta':
            key = values.get('property', values.get('name', ''))
            if key in ('description', 'og:description', 'twitter:description', 'og:title', 'twitter:title'):
                self.metadata.append((key, values.get('content', '')))

    def handle_endtag(self, tag):
        if tag in ('script', 'style', 'noscript') and self.ignored:
            self.ignored -= 1

    def handle_data(self, data):
        if not self.ignored:
            self.visible.append(data)


def inspect_page(body, native):
    page = Page()
    page.feed(body)
    page.close()
    boot = re.findall(r'message:\{text:("(?:[^"\\]|\\.)*")', body)
    bootstrap = json.loads(boot[0]) if len(boot) == 1 else None
    visible = ''.join(page.visible)
    metadata = []
    for key, text in page.metadata:
        metadata.append({
            'key': key, 'decoded_text_sha256': digest(text),
            'literal_amp_entity_count_after_html_parse': text.count('&amp;'),
            'equals_native': text == native,
            'equals_native_after_extra_unescape': html.unescape(text) == native,
            'decoded_ampersand_count': text.count('&'),
        })
    excerpts = [visible[max(0, m.start()-60):m.end()+60] for m in re.finditer('&amp;', visible)]
    return {
        'html_sha256': digest(body), 'bootstrap_message_count': len(boot),
        'bootstrap_text_sha256': digest(bootstrap) if isinstance(bootstrap, str) else None,
        'bootstrap_equals_native': bootstrap == native,
        'bootstrap_equals_native_after_html_unescape': html.unescape(bootstrap) == native if isinstance(bootstrap, str) else None,
        'bootstrap_literal_amp_entity_count': bootstrap.count('&amp;') if isinstance(bootstrap, str) else None,
        'metadata': metadata,
        'server_rendered_text_literal_amp_entity_count': visible.count('&amp;'),
        'server_rendered_text_error_excerpts': excerpts[:3],
        'browser_javascript_render_observed': False,
    }


def fetch(url):
    with build_opener(NoRedirect()).open(Request(url, headers={'User-Agent': 'Mozilla/5.0', 'Accept': 'text/html'}), timeout=15) as r:
        data = r.read(4_000_001)
        if r.status != 200 or r.geturl() != url or not 0 < len(data) <= 4_000_000:
            raise ValueError('public_response_scope')
        return data.decode('utf-8')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--native', type=Path, required=True)
    parser.add_argument('--out', type=Path, required=True)
    args = parser.parse_args()
    native = json.loads(args.native.read_bytes())
    posts = native.get('posts')
    if native.get('ok') is not True or not isinstance(posts, list) or len(posts) != 13 or {r.get('hotel_id') for r in posts} != set(TARGETS):
        raise ValueError('fresh_native_scope')
    rows = []
    for post in posts:
        hotel = post['hotel_id']
        url = 'https://max.ru/channel_anytour/' + TARGETS[hotel]
        caption = post['caption']
        if post['post_url'] != url or post.get('platform') != 'max' or not isinstance(caption, str) or digest(caption) != post['caption_sha256']:
            raise ValueError('native_binding_mismatch')
        row = {'hotel_id': hotel, 'post_url': url, 'native_caption_sha256': digest(caption), 'native_literal_amp_entity_count': caption.count('&amp;')}
        try:
            row.update(inspect_page(fetch(url), caption), http_status=200, observed=True)
        except Exception as error:
            row.update(observed=False, http_status=error.code if isinstance(error, HTTPError) else None, error=type(error).__name__)
        row['observed_at_utc'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
        rows.append(row)
    result = {
        'schema': 1, 'kind': 'MAX13_PUBLIC_HTML_OBSERVATION',
        'complete': all(r['observed'] for r in rows), 'expected_count': 13,
        'observed_count': sum(r['observed'] for r in rows),
        'provider_edits': 0, 'db_writes': 0, 'xml_writes': 0,
        'browser_javascript_render_observed': False, 'posts': rows,
    }
    args.out.write_text(json.dumps(result, ensure_ascii=False, indent=2)+'\n')
    print(json.dumps({k: v for k, v in result.items() if k != 'posts'}))


if __name__ == '__main__':
    main()
