import collections,datetime,hashlib,json,os,pathlib,urllib.request,xml.etree.ElementTree as ET
temp=pathlib.Path(os.environ['RUNNER_TEMP']);url='http://77.246.158.183:8081/exports/moscow_any.yml'
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
opener=urllib.request.build_opener(NoRedirect)
start=datetime.datetime.now(datetime.timezone.utc).isoformat()
req=urllib.request.Request(url,headers={'Accept':'application/xml,text/xml,text/plain;q=0.8','User-Agent':'AnyTourAuthorizedFeedInspection/1.0'})
out={'operation':'commercial-price-source-20261104-moscow-yml-inspection1','source_url':url,'state':'NOT_FETCHED','max_bytes':26214400,'source_get_attempts':1,'automatic_retry':False,'provider_search_calls':0,'gateway_calls':0,'db_calls':0,'post_edits':0,'request_started_utc':start}
try:
 with opener.open(req,timeout=50) as response:
  out['http_status']=response.status;out['content_type']=response.headers.get('Content-Type','');out['declared_content_length']=response.headers.get('Content-Length');out['last_modified']=response.headers.get('Last-Modified')
  data=response.read(26214401)
 out['received_at_utc']=datetime.datetime.now(datetime.timezone.utc).isoformat();out['received_bytes']=len(data)
 assert len(data)<=26214400,'BOUNDED_SOURCE_TOO_LARGE_NO_RETRY'
 (temp/'moscow_any.source.xml').write_bytes(data)
 out['sha256']=hashlib.sha256(data).hexdigest()
 assert b'<!DOCTYPE' not in data.upper() and b'<!ENTITY' not in data.upper(),'XML_DECLARED_ENTITIES_NOT_ACCEPTED'
 root=ET.fromstring(data);out['root_tag']=root.tag;out['catalog_date_raw_unverified_timezone']=root.attrib.get('date')
 offers=root.findall('.//offer');out['offer_count']=len(offers)
 out['offer_tag_counts']=dict(collections.Counter(c.tag for offer in offers for c in offer))
 params=collections.Counter(p.attrib.get('name','') for offer in offers for p in offer.findall('param'));out['parameter_name_counts']=dict(params)
 out['offer_attribute_names']=sorted({k for offer in offers for k in offer.attrib})
 out['currency_counts']=dict(collections.Counter(offer.findtext('currencyId','') for offer in offers))
 out['offers_with_price']=sum(bool(offer.findtext('price','').strip()) for offer in offers)
 out['offers_with_url']=sum(bool(offer.findtext('url','').strip()) for offer in offers)
 out['offers_with_picture']=sum(bool(offer.findtext('picture','').strip()) for offer in offers)
 out['top_level_tags']=[c.tag for c in root]
 out['state']='ONE_BOUNDED_PUBLIC_SOURCE_RETAINED_SCHEMA_INSPECTED_NOT_PRICE_ACCEPTANCE'
except Exception as exc:
 out['state']='SOURCE_INSPECTION_STOPPED_NO_RETRY';out['exception_type']=type(exc).__name__
 if isinstance(exc,urllib.error.HTTPError):out['http_status']=exc.code
 out['error_code']=str(exc) if str(exc).startswith(('BOUNDED_','XML_')) else 'SOURCE_TRANSPORT_OR_XML_NOT_ACCEPTED'
(temp/'moscow-yml-inspection-summary.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(out,ensure_ascii=False));assert out['state']=='ONE_BOUNDED_PUBLIC_SOURCE_RETAINED_SCHEMA_INSPECTED_NOT_PRICE_ACCEPTANCE'
