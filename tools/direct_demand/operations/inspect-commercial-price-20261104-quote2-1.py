import hashlib,json,os,pathlib
root=pathlib.Path(os.environ['QUOTE2_PATH']);p=list(root.rglob('channel-preflight.json'));assert len(p)==1
q=json.loads(p[0].read_text().splitlines()[-1]);assert q['operation']=='commercial-price-monitor-20261104-quote2' and q['ok'] and q['catalog_id']==9432 and q['gateway_requests_attempted']==1
assert q['response_sha256']=='135bb236f2c90750fcaa20a4021a03107cc1c4193a33f5aa2b7d804f4547cda9'
g=q['gateway_response']
def shape(x,depth=0):
 if depth>7:return {'type':type(x).__name__}
 if isinstance(x,dict):return {k:shape(v,depth+1) for k,v in x.items()}
 if isinstance(x,list):return {'type':'list','count':len(x),'first':shape(x[0],depth+1) if x else None}
 return {'type':type(x).__name__}
public={'operation':'commercial-price-monitor-20261104-quote2-contract-inspection1','catalog_id':9432,'response_sha256':q['response_sha256'],'response_epoch_utc':q['response_received_epoch_utc'],'gateway_shape':shape(g),'server_connections':0,'provider_calls':0,'message_edits':0}
print(json.dumps(public,ensure_ascii=False,sort_keys=True))
pathlib.Path(os.environ['RUNNER_TEMP'],'quote2-contract-structure.json').write_text(json.dumps(public,ensure_ascii=False))
