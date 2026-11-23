"""No credentials/network/native actions. Verify reviewed closure before SSH setup."""
import ast,hashlib,json,os,pathlib,re,sys
root=pathlib.Path.cwd().resolve();path=pathlib.Path(sys.argv[1]);raw=path.read_bytes();cfg=json.loads(raw)
sha=lambda b:hashlib.sha256(b).hexdigest()
h=os.environ.get('REVIEWED_FEED123_CONFIG_SHA256','')
assert re.fullmatch('[a-f0-9]{64}',h) and h==sha(raw),'closed_or_changed_config'
assert cfg['claim_id']==6967624483 and cfg['permit_comment_id']==6972314498,'wrong_scope_owner'
assert cfg['parent_sha']=='8fb8ef8c9cca236066728ec8d17d6f65dadf2584','wrong_parent'
assert cfg['execution_mode']=='WRITE_NEXT123','wrong_execution_mode'
assert cfg['source_sha256'],'missing_source_closure'
for rel,h in cfg['source_sha256'].items():
 p=pathlib.PurePosixPath(rel)
 assert not p.is_absolute() and '..' not in p.parts and re.fullmatch('[a-f0-9]{64}',h),'unsafe_pin'
 f=root/rel;cursor=root
 for part in p.parts:
  cursor=cursor/part;assert not cursor.is_symlink(),'symlink_source'
 assert f.is_file() and sha(f.read_bytes())==h,'source_changed:'+rel
 if f.suffix=='.py':ast.parse(f.read_bytes(),filename=rel)
readers=sorted(path.parent.glob('tail123/p*/read.php'))
assert [p.parent.name for p in readers]==['p'+str(b) for b in range(28,42)],'reader_scope'
assert all(str(p.resolve().relative_to(root)) in cfg['source_sha256'] for p in readers),'reader_missing_source_pin'
print(json.dumps({'ok':True,'source_pins':len(cfg['source_sha256']),'readers':len(readers),'network_calls':0,'native_actions':0}))
