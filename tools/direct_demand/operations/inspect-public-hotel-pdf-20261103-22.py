import hashlib,json,subprocess,tempfile,urllib.request,shutil
from pathlib import Path
url='https://partner.jazhotels.com/Images/uploads/gallery/Downloads/solymarivorysuites/JAZ%20NEO%20Ivory_factsheet_08052026.pdf'
out={'operation':'commercial-batch22-neo-ivory-pdf-20261103-read2','hotel_id':60589,'source_url':url,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0}
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_pdf_redirect')
try:
 assert shutil.which('pdftotext'),'pdf_text_tool_missing_before_request'
 with urllib.request.build_opener(NoRedirect()).open(url,timeout=30) as r:
  raw=r.read(3000001)
  assert r.status==200 and 100<len(raw)<=3000000 and raw.startswith(b'%PDF')
 assert hashlib.sha256(raw).hexdigest()=='ab07f2c92b1ecd4a6116469bf6ec7dd664ffec054e74255b2ec9f37f13279a90'
 out.update(bytes=len(raw),sha256=hashlib.sha256(raw).hexdigest())
 with tempfile.TemporaryDirectory() as temp:
  p=Path(temp)/'primary.pdf';p.write_bytes(raw)
  t=subprocess.run(['pdftotext','-layout',str(p),'-'],capture_output=True,text=True,timeout=30,check=True).stdout
  out.update(state='PRIMARY_TEXT_READ',text=t[:10000],text_char_count=len(t))
except Exception as e:
 out.update(state='DOCUMENT_NOT_READ',error=type(e).__name__)
Path('primary-pdf-text.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(out,ensure_ascii=False),flush=True)
