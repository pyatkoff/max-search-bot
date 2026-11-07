import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
from urllib.request import Request, urlopen
from urllib.parse import urlsplit

POST='any_toour/66'
class Caption(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.depth=0
        self.message=None
        self.capture=None
        self.fragments=[]
        self.done=False

    def handle_starttag(self,tag,attrs):
        attrs=dict(attrs)
        if tag=='div':
            self.depth+=1
            if attrs.get('data-post')==POST:
                self.message=self.depth
            if self.message is not None and 'tgme_widget_message_text' in attrs.get('class','').split():
                self.capture=self.depth
        elif tag=='br' and self.capture is not None:
            self.fragments.append('\n')

    def handle_endtag(self,tag):
        if tag=='div':
            if self.capture==self.depth:
                self.done=True
                self.capture=None
            if self.message==self.depth:
                self.message=None
            self.depth-=1

    def handle_data(self,value):
        if self.capture is not None:
            self.fragments.append(value)

sources=(
    'https://t.me/any_toour/66?embed=1&mode=tme',
    'https://t.me/s/any_toour/66',
    'https://t.me/s/any_toour?before=67',
)
out={'post_url':'https://t.me/any_toour/66','ok':False,'write_attempts':0,'checks':[]}
for url in sources:
    try:
        request=Request(url,headers={'User-Agent':'Mozilla/5.0','Accept':'text/html'})
        with urlopen(request,timeout=10) as response:
            if urlsplit(response.geturl()).hostname!='t.me':
                out['checks'].append('unexpected_redirect')
                continue
            body=response.read(2_000_000).decode('utf-8')
        parser=Caption()
        parser.feed(body)
        if not parser.done:
            out['checks'].append('exact_post_caption_unavailable')
            continue
        text=''.join(parser.fragments)
        if not text.startswith('✈️ Rixos Premium Seagate') and not text.startswith('✈️ Туры в Rixos Premium Seagate'):
            out['checks'].append('exact_post_caption_identity_changed')
            continue
        out.update(ok=True,caption=text,caption_sha256=hashlib.sha256(text.encode()).hexdigest())
        break
    except (OSError,UnicodeError):
        out['checks'].append('public_view_unavailable')
Path('rixos-telegram-public-readback.json').write_text(json.dumps(out,ensure_ascii=False))
print(json.dumps({k:v for k,v in out.items() if k!='caption'},ensure_ascii=False))
