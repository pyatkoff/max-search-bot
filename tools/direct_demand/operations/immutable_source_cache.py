"""Persist ONLY immutable full-commit GitHub contents across this finite job's steps.
Mutable API paths never read/write this cache. Callers retain their independent SHA pins.
"""
import base64,hashlib,json,os,pathlib,re
PATTERN=re.compile(r'contents/[^?]+\?ref=[a-f0-9]{40}\Z')
def cached_api(fetch,root,repo,path,raw=False):
 if raw or PATTERN.fullmatch(path) is None:return fetch(repo,path,raw)
 key=hashlib.sha256((repo+'\n'+path).encode()).hexdigest();root.mkdir(mode=0o700,parents=True,exist_ok=True);file=root/(key+'.json')
 if file.exists():
  if file.is_symlink():raise ValueError('immutable_cache_symlink')
  record=json.loads(file.read_bytes())
  if record.get('repository')!=repo or record.get('path')!=path:raise ValueError('immutable_cache_identity')
  body=record['body'];b=base64.b64decode(body['content'],validate=True)
  if hashlib.sha256(b).hexdigest()!=record['content_sha256'] or hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()!=body['sha']:raise ValueError('immutable_cache_corrupt')
  return body
 body=fetch(repo,path,False)
 if body.get('encoding')!='base64' or body.get('type','file')!='file':raise ValueError('immutable_contents_not_file')
 b=base64.b64decode(body['content']);blob=hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
 if body.get('sha',blob)!=blob:raise ValueError('immutable_source_blob_mismatch')
 result={'encoding':'base64','type':'file','content':base64.b64encode(b).decode(),'sha':blob}
 record={'repository':repo,'path':path,'body':result,'content_sha256':hashlib.sha256(b).hexdigest()}
 with file.open('x') as f:json.dump(record,f,separators=(',',':'));f.flush();os.fsync(f.fileno())
 return result
