import hashlib,io,json,urllib.parse,urllib.request
from pathlib import Path
from pypdf import PdfReader
documents=json.loads("[{\"hotel_id\":67897,\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/new/050346Jaz%20Maraya%20Fact%20Sheet%202024.pdf\"},{\"hotel_id\":75725,\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/IberotelCostaMares/JAZ_CostaMares_Factsheet_14052026.pdf\"},{\"hotel_id\":108090,\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/Amara/JAZ_Elite_Amara_Factsheet_28042026.pdf\"},{\"hotel_id\":73342,\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/SolYMarSomaBeach/JAZ%20Soma%20Beach%20Factsheet_14052026.pdf\"},{\"hotel_id\":278,\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/jazbelvedere/JAZ%20Belvedere%20Factsheet.pdf\"},{\"hotel_id\":281,\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/jazmirabelbeach/JAZ%20Mirabel%20Beach%20Factsheet.pdf\"},{\"hotel_id\":246,\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/FayrouzResort/JAZ%20Fayrouz%20Factsheet.pdf\"},{\"hotel_id\":384,\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/new/080132Solymar%20Reef%20Marsa%20Fact%20Sheet%20Jan%202024.pdf\"}]")
assert len(documents)==8 and {x['hotel_id'] for x in documents}=={67897,75725,108090,73342,278,281,246,384}
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_primary_document_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch12-facts-20261102-read1','mode':'read_exact_public_primary_factsheets_only','server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'documents':[]}
for d in documents:
 row={'hotel_id':d['hotel_id'],'source_url':d['url']}
 try:
  p=urllib.parse.urlsplit(d['url'])
  assert p.scheme=='https' and p.hostname=='partner.jazhotels.com' and p.port in (None,443) and not p.username and not p.password
  assert p.path.startswith('/Images/uploads/gallery/Downloads/') and p.path.lower().endswith('.pdf') and not p.query and not p.fragment
  with opener.open(urllib.request.Request(d['url'],headers={'User-Agent':'Mozilla/5.0'}),timeout=35) as r:
   assert r.status==200
   raw=r.read(8000001)
  assert 1000<len(raw)<=8000000 and raw.startswith(b'%PDF-')
  reader=PdfReader(io.BytesIO(raw))
  assert not reader.is_encrypted and 1<=len(reader.pages)<=20
  texts=[(page.extract_text() or '').strip() for page in reader.pages]
  text='\n'.join(texts)
  row.update({'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'pages':len(texts),'state':'PUBLIC_TEXT_EXTRACTED' if len(text)>100 else 'PUBLIC_PDF_NO_READABLE_TEXT','text':text[:24000]})
 except Exception as e:row['state']='DOCUMENT_UNAVAILABLE';row['error']=type(e).__name__
 out['documents'].append(row)
out['ok']=True
Path('public-hotel-facts.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(out,ensure_ascii=False))
