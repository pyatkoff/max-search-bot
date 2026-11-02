import io,json,hashlib,urllib.request,urllib.parse
from pathlib import Path
from pypdf import PdfReader
operation="commercial-batch15-new-factsheets-20261103-read1"
rows=[{"hotel_id":264,"url":"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/jazdahabeya/JAZ%20Dahabeya%20Factsheet.pdf"},{"hotel_id":13869,"url":"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/jazmakadisarayapalms/JAZ%20Neo%20Saraya%20Palms%20Factsheet.pdf"},{"hotel_id":245,"url":"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/SharmDreamsResort/JAZ%20Sharm%20Dreams%20Factsheet.pdf"},{"hotel_id":12114,"url":"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/IberotelPalace/JAZ%20Elite%20Palace%20Factsheet.pdf"},{"hotel_id":1983,"url":"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/solymarsharksbay/JAZ%20Neo%20Sharks%20Bay%20Factsheet.pdf"},{"hotel_id":267,"url":"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/iberotelmakadibeach/Iberotel%20Makadi%20Beach%20by%20JAZ%20Factsheet.pdf%20%281%29.pdf"},{"hotel_id":111801,"url":"https://partner.jazhotels.com/Images/uploads/gallery/Downloads/MagicWorldSerenaBeachClub/JAZ_Elite_Asteria_Factsheet_27042026.pdf"}]
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError("redirect_forbidden")
opener=urllib.request.build_opener(NoRedirect())
out={"operation":operation,"boundary":"Seven newly resolved exact public primary PDFs only. No server, DB, customers or tour searches.","documents":[]}
for row in rows:
 item={"hotel_id":row["hotel_id"],"source_url":row["url"]}
 try:
  p=urllib.parse.urlsplit(row["url"]);assert p.scheme=="https" and p.hostname=="partner.jazhotels.com" and p.path.startswith("/Images/uploads/gallery/Downloads/") and not p.query
  with opener.open(urllib.request.Request(row["url"],headers={"User-Agent":"AnyTour-public-source-check/1.0"}),timeout=20) as r:
   data=r.read(8000001);assert len(data)<=8000000 and data.startswith(b"%PDF")
  reader=PdfReader(io.BytesIO(data));assert 1<=len(reader.pages)<=16
  item.update(bytes=len(data),sha256=hashlib.sha256(data).hexdigest(),page_count=len(reader.pages),text=[(page.extract_text() or "")[:18000] for page in reader.pages[:10]],state="PUBLIC_PDF_READ")
 except Exception as e:item.update(state="DOCUMENT_UNAVAILABLE",reason=type(e).__name__)
 out["documents"].append(item)
Path("public-media-sources.json").write_text(json.dumps(out,ensure_ascii=False,indent=2)+"\n")
print(json.dumps(out,ensure_ascii=False))
