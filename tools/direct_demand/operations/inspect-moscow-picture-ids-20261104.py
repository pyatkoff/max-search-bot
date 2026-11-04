import collections,json,os,pathlib,re,urllib.parse,xml.etree.ElementTree as ET
temp=pathlib.Path(os.environ['RUNNER_TEMP'])
def one(folder,name):
 p=list((temp/folder).rglob(name));assert len(p)==1;return p[0]
raw=one('moscow-source','moscow_any.source.xml').read_bytes();assert __import__('hashlib').sha256(raw).hexdigest()=='21ca10ff39422e7509a8fc1835b2046f68243e0c1103b1660aac6e6cf36a5996'
d=json.loads(one('retained-read9','price-readback.json').read_text().splitlines()[-1]);assert len(d['hotels'])==157
native={int(h['local_tv_id']):h for h in d['hotels']};native.update({17480:{},1100:{}})
hosts=collections.Counter();forms=collections.Counter();samples=[];numeric_matches=collections.Counter()
for offer in ET.fromstring(raw).findall('.//offer'):
 pic=urllib.parse.urlsplit(offer.findtext('picture',''));hosts[pic.hostname or '']+=1
 shape=re.sub(r'\d+','{number}',pic.path);forms[(pic.hostname or '')+shape]+=1
 nums={int(x) for x in re.findall(r'(?<![A-Za-z])\d+(?![A-Za-z])',pic.path)}
 hit=sorted(nums&set(native))
 for hid in hit:numeric_matches[hid]+=1
 if len(samples)<10:samples.append({'offer_name':offer.findtext('name','')[:140],'picture_host':pic.hostname,'picture_path':pic.path,'query_present':bool(pic.query),'numeric_segments_matching_retained_native_ids':hit})
report={'operation':'commercial-price-source-20261104-moscow-picture-id-inspection1','source_gets':0,'photo_gets':0,'provider_calls':0,'db_calls':0,'edits':0,'picture_hosts':dict(hosts),'picture_path_shapes':dict(forms),'first10_public_picture_path_samples':samples,'numeric_segment_hit_counts_not_identity_proof':dict(numeric_matches),'arbitrary_number_is_not_accepted_native_binding':True}
(temp/'moscow-picture-id-summary.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n');print(json.dumps(report,ensure_ascii=False))
