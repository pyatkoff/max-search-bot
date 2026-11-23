from pathlib import Path
import re,json,hashlib,difflib
P=Path(__file__).parent;H=lambda b:hashlib.sha256(b).hexdigest();G=lambda b:hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest();oldtree=json.loads(Path('/workspace/shared/runtime-9ba-review/tree.json').read_bytes());newtree=json.loads((P/'tree.json').read_bytes());assert not oldtree['truncated'] and not newtree['truncated'];OT={x['path']:x['sha'] for x in oldtree['tree']};NT={x['path']:x['sha'] for x in newtree['tree']}
reader=Path('/workspace/shared/reader174-candidate/prepared174/p01/read.php').read_text();pins=dict(re.findall(r'"([^"]+)"=>"([a-f0-9]{40})"',reader));assert len(pins)==22;matrix=[{'path':k,'accepted_blob':v,'old_blob':OT[k],'new_blob':NT[k],'unchanged':v==OT[k]==NT[k]} for k,v in pins.items()];changed=[x['path'] for x in matrix if not x['unchanged']];assert set(changed)=={'services/ChannelPostRegistryService.php','services/ChannelPostReconciliationService.php'}
def mask(s):
 out=list(s);i=0
 while i<len(s):
  if s[i:i+2] in ('//','/*') or s[i]=='#':
   end=(s.find('*/',i+2)+2 if s[i:i+2]=='/*' else s.find('\n',i))
   if end<0:end=len(s)
   for j in range(i,end):out[j]=' '
   i=end
  elif s[i] in "'\"`":
   q=s[i];j=i+1
   while j<len(s):
    if s[j]=='\\':j+=2;continue
    if s[j]==q:j+=1;break
    j+=1
   for k in range(i,j):out[k]=' '
   i=j
  else:i+=1
 return ''.join(out)
def methods(s):
 m=mask(s);out={}
 for x in re.finditer(r'\bfunction\s+([A-Za-z_]\w*)\s*\(',m):
  name=x[1];start=m.index('{',x.end());depth=1;i=start+1
  while depth:
   depth+=(m[i]=='{')-(m[i]=='}');i+=1
  assert name not in out;out[name]=s[x.start():i]
 return out
proof={}
for cls in ['ChannelPostRegistryService','ChannelPostReconciliationService']:
 sources=[]
 for suf,tree in [('9ba',OT),('bd8',NT)]:
  f=P/(cls+'-'+suf+'.php');b=f.read_bytes();expected=tree['services/'+cls+'.php']
  if G(b)!=expected and G(b[:-1])==expected:b=b[:-1];f.write_bytes(b)
  assert G(b)==expected,cls;sources.append(b.decode())
 a,b=sources;am,bm=methods(a),methods(b);assert all(bm.get(k)==v for k,v in am.items()),cls
 delta=[(tag,i,j,k,l) for tag,i,j,k,l in difflib.SequenceMatcher(None,a.splitlines(True),b.splitlines(True),autojunk=False).get_opcodes() if tag!='equal'];assert all(d[0]=='insert' for d in delta)
 added=sorted(set(bm)-set(am));proof[cls]={'all_original_methods_byte_identical':True,'original_method_count':len(am),'added_methods':added,'original_method_sha256':{k:H(v.encode()) for k,v in am.items()},'old_sha256':H(a.encode()),'new_sha256':H(b.encode())}
 if cls=='ChannelPostRegistryService':
  roots=['__construct','publication'];seen=set();todo=roots[:]
  while todo:
   n=todo.pop()
   if n in seen:continue
   seen.add(n);assert n in bm,n
   calls=re.findall(r'(?:\$this->|self::)(\w+)\s*\(',bm[n]);todo.extend(x for x in calls if x in bm and x not in seen)
  assert not set(added)&seen;proof[cls]['reader_call_roots']=roots;proof[cls]['reader_method_closure']=sorted(seen);proof[cls]['added_methods_reachable_from_reader']=False
out={'base':'9ba896c12ae9ce0f7d6e5d31564bcf8830ebbeac','target':'bd8e248f7138ea75cb9fda2dcf44beb631c633a5','component_matrix':matrix,'unchanged_components':20,'changed_components':2,'method_proof':proof,'native_run':None,'admission_opened':False};(P/'compatibility-proof.json').write_text(json.dumps(out,indent=2)+'\n');print('PASS',[(k,v['original_method_count'],v['added_methods']) for k,v in proof.items()]);print('closure',proof['ChannelPostRegistryService']['reader_method_closure'])
