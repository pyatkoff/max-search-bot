"""Offline preparation for the existing channel publisher. No network or provider calls."""
import argparse,base64,hashlib,json,os,re
from pathlib import Path
from urllib.parse import urlsplit

MAX_BATCH=10
TEMPLATE_PATH='tools/direct_demand/operations/commercial-batch-template-20261102.php'
TEMPLATE_SHA='6ceb2f6848eb3412c500563da4634d495b64c4284e151308b866128a9053dbcb'
TEMPLATE_OPERATION='commercial-hotel-three-20261102-8-v1'
PREFIX="<?php\ndeclare(strict_types=1);\n"
COUNT_ANCHOR="count($payload['rows']??[])===3"
SCOPE_ANCHOR="in_array((int)$row['hotel_id'],[416,12112,478],true)"
MODE_ANCHOR="'mode'=>'publish_approved_commercial_next_three'"
READY='REAL_OFFICIAL_PHOTOGRAPHY_BRANDED_COVER_VISUALLY_VERIFIED'
HEX64=re.compile(r'[a-f0-9]{64}\Z')
OPERATION=re.compile(r'commercial-hotel-batch-([0-9]{8})-([1-9][0-9]*)-v[1-9][0-9]*')
# Already-approved operations retain their exact input and validation policy.
CAPTION_FORMAT_CUTOVER=(20261107,24)
SHORT_HEADING_CUTOVER=(20261107,39)
CAPTION_TITLE=re.compile(r'✈️ Туры в \S(?:.*\S)? с перелётом')
CAPTION_SHORT_TITLE=re.compile(r'✈️ Туры в \S(?:.*\S)?')
FLIGHT_SUFFIX=re.compile(r' с перел[её]том\Z')
CAPTION_LOCATION=re.compile(r'📍 \S(?:.*\S)?, [^\W\d_]+(?:[ -][^\W\d_]+)*\.')
CAPTION_DASH=re.compile(r'[-\u2010-\u2015\u2212]')
HEADING_WORD_HYPHEN=re.compile(r'(?<=[^\W_])-(?=[^\W_])')
PHP_DASH_ANCHOR=r"preg_match('/[-\x{2010}-\x{2015}\x{2212}]/u',$row['text'])===0"
PHP_HEADING_HYPHENS=r"""
function commercialCaptionPunctuationIsValid(string $text):bool
{
    $lines=explode("\n",$text,3);
    $heading=$lines[0]."\n".($lines[1]??'');
    $heading=preg_replace('/(?<=[\p{L}\p{N}])-(?=[\p{L}\p{N}])/u','',$heading);
    return is_string($heading) && preg_match('/[-\x{2010}-\x{2015}\x{2212}]/u',$heading."\n".($lines[2]??''))===0;
}
"""

def require(ok,reason):
 if not ok:raise ValueError(reason)

def validate_caption(text,standard_headings,short_headings=False):
 require(isinstance(text,str) and 0<len(text.encode('utf-16-le'))//2<=1024,'caption_invalid')
 if not standard_headings:
  require(CAPTION_DASH.search(text) is None,'caption_invalid')
  return
 lines=text.split('\n',2)
 title=CAPTION_SHORT_TITLE if short_headings else CAPTION_TITLE
 require(len(lines)>=2 and title.fullmatch(lines[0]) and CAPTION_LOCATION.fullmatch(lines[1]),'caption_requires_tour_heading_and_resort_country')
 if short_headings:
  require(FLIGHT_SUFFIX.search(lines[0]) is None,'caption_title_must_omit_flight_suffix')
 heading=HEADING_WORD_HYPHEN.sub('','\n'.join(lines[:2]))
 body=lines[2] if len(lines)==3 else ''
 require(CAPTION_DASH.search(heading+'\n'+body) is None,'caption_invalid')

def prepare(template,raw,spec):
 require(hashlib.sha256(template.encode()).hexdigest()==TEMPLATE_SHA,'publisher_template_changed')
 require(isinstance(raw,bytes) and 0<len(raw)<=262144,'manifest_size_invalid')
 require(isinstance(spec,dict) and set(spec)=={'schema_version','operation','expected_installed_sha','hotel_ids','manifest_sha256'},'approval_spec_invalid')
 require(spec['schema_version']==1,'approval_schema_invalid')
 op=spec['operation'];sha=spec['expected_installed_sha'];ids=spec['hotel_ids']
 match=OPERATION.fullmatch(op) if isinstance(op,str) else None
 require(match is not None,'operation_scope_invalid')
 batch=tuple(map(int,match.groups()))
 standard_headings=batch>=CAPTION_FORMAT_CUTOVER
 short_headings=batch>=SHORT_HEADING_CUTOVER
 require(isinstance(sha,str) and re.fullmatch(r'[a-f0-9]{40}',sha),'installed_sha_invalid')
 require(isinstance(ids,list) and 1<=len(ids)<=MAX_BATCH and all(type(i) is int and 0<i<1000000000 for i in ids) and len(set(ids))==len(ids),'hotel_scope_invalid')
 require(spec['manifest_sha256']==hashlib.sha256(raw).hexdigest(),'approved_manifest_changed')
 m=json.loads(raw)
 require(isinstance(m,dict) and m.get('operation')==op and m.get('state')=='PREPARED_NOT_SENT','manifest_not_approved_for_send')
 require(m.get('button_text')=='✈️ Узнать стоимость тура','canonical_button_changed')
 rows=m.get('rows');require(isinstance(rows,list) and len(rows)==len(ids),'row_count_invalid')
 require([r.get('hotel_id') for r in rows if isinstance(r,dict)]==ids,'hotel_scope_changed')
 logical=set();texts=set();photos=set()
 for r in rows:
  lid=r.get('logical_id');text=r.get('text');th=r.get('text_sha256');ph=r.get('photo_sha256');url=r.get('photo_url')
  require(isinstance(lid,str) and re.fullmatch(r'[a-z0-9-]{1,100}',lid) and lid not in logical,'logical_id_invalid_or_duplicate');logical.add(lid)
  validate_caption(text,standard_headings,short_headings)
  require(isinstance(th,str) and HEX64.fullmatch(th) and th==hashlib.sha256(text.encode()).hexdigest() and th not in texts,'text_hash_invalid_or_duplicate');texts.add(th)
  require(isinstance(ph,str) and HEX64.fullmatch(ph) and ph not in photos,'photo_hash_invalid_or_duplicate');photos.add(ph)
  require(isinstance(url,str) and re.fullmatch(r'https://raw\.githubusercontent\.com/pyatkoff/max-search-bot/[a-f0-9]{40}/\.github/traffic-publish/[a-zA-Z0-9/_\.-]+\.png',url),'photo_url_not_immutable_public_cover')
  require(r.get('media_state')==READY,'photo_not_visually_accepted')
  aliases=r.get('aliases');require(isinstance(aliases,list) and 1<=len(aliases)<=12 and all(isinstance(a,str) and 0<len(a)<=160 for a in aliases),'aliases_invalid')
  sources=r.get('sources');require(isinstance(sources,list) and 1<=len(sources)<=12,'sources_missing')
  for source in sources:
   require(isinstance(source,str),'source_invalid');u=urlsplit(source)
   require(u.scheme=='https' and u.hostname and not u.username and not u.password and u.port in (None,443),'source_invalid')
 require(template.startswith(PREFIX),'publisher_prefix_changed')
 for anchor in (COUNT_ANCHOR,SCOPE_ANCHOR,MODE_ANCHOR):
  require(template.count(anchor)==1,'publisher_anchor_changed')
 body=template.replace(TEMPLATE_OPERATION,op).replace(COUNT_ANCHOR,"count($payload['rows']??[])==="+str(len(ids))).replace(SCOPE_ANCHOR,"in_array((int)$row['hotel_id'],"+json.dumps(ids,separators=(',',':'))+",true)").replace(MODE_ANCHOR,"'mode'=>'publish_approved_commercial_batch'")
 header=PREFIX+"const EXPECTED_INSTALLED_SHA='"+sha+"';\nconst MANIFEST_BASE64='"+base64.b64encode(raw).decode()+"';\n"
 if standard_headings:
  require(body.count(PHP_DASH_ANCHOR)==1,'publisher_caption_anchor_changed')
  body=body.replace(PHP_DASH_ANCHOR,"commercialCaptionPunctuationIsValid($row['text'])")
  header+=PHP_HEADING_HYPHENS
 return header+body[len(PREFIX):],{'operation':op,'mode':'prepare_existing_publisher_only','rows':len(ids),'expected_confirmed_posts':2*len(ids),'provider_calls':0,'db_writes':0,'post_write_attempts':0}

def main():
 require(os.environ.get('GITHUB_ACTIONS')=='true','cloud_preparation_only')
 p=argparse.ArgumentParser();p.add_argument('--manifest',required=True);p.add_argument('--approval',required=True);p.add_argument('--output',required=True);a=p.parse_args()
 spec_raw=Path(a.approval).read_bytes();require(len(spec_raw)<=8192,'approval_size_invalid')
 script,report=prepare(Path(TEMPLATE_PATH).read_text(),Path(a.manifest).read_bytes(),json.loads(spec_raw))
 root=Path(os.environ['RUNNER_TEMP']).resolve();out=Path(a.output).resolve()
 require(root in out.parents,'output_not_in_runner_temp')
 out.write_text(script);out.chmod(0o600);print(json.dumps(report,ensure_ascii=False,sort_keys=True))

if __name__=='__main__':main()
