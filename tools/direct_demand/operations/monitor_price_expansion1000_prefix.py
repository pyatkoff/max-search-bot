import copy,gzip,json,hashlib,pathlib
PROOF_SHA='ff4aaa11ce77ce015e82b40acabc19db3dd15a676b5826aefade62091bf8cb31'
# The immutable expansion proof remains the historical source. This is one exact
# adoption of the already completed caption correction, not runtime admission.
# Native edit: run 45096362078/job 137436923923 made the two edits; its later
# attachment-hash verifier failed. FEED-only run 45100365367/job 137449585038
# completed CONTENT66198_FEED_NATIVE_PUBLIC_VERIFIED without repeating captions.
# Receipt: https://github.com/pyatkoff/max-search-bot/issues/1566#issuecomment-6961029169
# Artifact 14785057737 SHA256 ad02df60b466d7cd26db5bf0560c643ab0f64ff6212844d9dd95a170e960dc4c
CORRECTION_GZIP_SHA='b671558af880a7ab186364c35e3750ee1ffdd500931d6f868d1f0f142a550abf'
ORIGINAL_RAW_SHA='ee0b1019a09c07cea2413723e972d045244707be39a38f573fd86f1a4c879b6f'
CAPTION_SHA='bf5978467d0dab5e19bf072a7478381ff88a0803b8b6718d8769ae9126237676'
CAPTION_OPERATION='commercial-waldorf66198-caption-correction-20261123-v1'
canonical=lambda value:json.dumps(value,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()
def _row_token(row):
 return '\n    '.join(json.dumps(row,ensure_ascii=False,indent=2).splitlines()).encode()
def _restore_waldorf66198(full,historical877,p):
 current=json.loads(full)['rows'];original=json.loads(historical877)['rows'];index=663
 assert len(current)==1000 and len(original)==877,'waldorf66198_scope_changed'
 assert len({r['tourvisor_hotel_id'] for r in current})==1000,'waldorf66198_scope_changed'
 assert original[index]['tourvisor_hotel_id']==current[index]['tourvisor_hotel_id']==66198,'waldorf66198_scope_changed'
 expected=copy.deepcopy(original[index])
 assert 'caption_correction_baseline' not in expected,'waldorf66198_original_changed'
 for channel in ['max','telegram']:
  assert expected['templates'][channel].count('Одиннадцать ресторанов')==1,'waldorf66198_original_changed'
  expected['templates'][channel]=expected['templates'][channel].replace('Одиннадцать ресторанов','Десять ресторанов, баров и лаунжей')
  # expected_text_sha256 is the accepted publication snapshot, not the template.
  expected['expected_text_sha256'][channel]=CAPTION_SHA
 expected['caption_correction_baseline']={
  'max':{'publication_id':1510,'version_id':14281,'text_sha256':CAPTION_SHA,'operation':CAPTION_OPERATION},
  'telegram':{'publication_id':1500,'version_id':14280,'text_sha256':CAPTION_SHA,'operation':CAPTION_OPERATION}}
 assert canonical(current[index])==canonical(expected),'waldorf66198_row_changed'
 assert canonical(current[:index]+current[index+1:877])==canonical(original[:index]+original[index+1:]),'other876_changed'
 assert hashlib.sha256(canonical(current[877:])).hexdigest()==p['append123_canonical_sha256'],'append123_changed'
 token=_row_token(expected);assert full.count(token)==1,'waldorf66198_raw_changed'
 restored=full.replace(token,_row_token(original[index]),1)
 # This also protects every byte outside the one row, including top-level data.
 assert hashlib.sha256(restored).hexdigest()==ORIGINAL_RAW_SHA,'original1000_raw_changed'
 return restored
def _accepted(packed,root):
 raw=(pathlib.Path(root)/'monitor-price-expansion1000-proof-20261118.json').read_bytes()
 assert hashlib.sha256(raw).hexdigest()==PROOF_SHA,'expansion1000_proof_changed'
 p=json.loads(raw);packed_sha=hashlib.sha256(packed).hexdigest()
 assert packed_sha in [p['roster_gzip_sha256'],CORRECTION_GZIP_SHA],'expansion1000_packed_changed'
 # Preserve original gzip bytes: recompression headers/deflate vary across Python/zlib.
 packed877=(pathlib.Path(root)/'monitor-price-expansion1000-baseline877.json.gz').read_bytes()
 assert hashlib.sha256(packed877).hexdigest()==p['baseline_roster_gzip_sha256'],'baseline877_packed_changed'
 historical877=gzip.decompress(packed877)
 assert hashlib.sha256(historical877).hexdigest()==p['baseline_roster_raw_sha256'],'baseline877_changed'
 full=gzip.decompress(packed)
 if packed_sha==CORRECTION_GZIP_SHA:full=_restore_waldorf66198(full,historical877,p)
 assert hashlib.sha256(full).hexdigest()==ORIGINAL_RAW_SHA,'original1000_raw_changed'
 rows=json.loads(full)['rows'];assert len(rows)==1000
 assert [r['tourvisor_hotel_id'] for r in rows[877:]]==[r['tourvisor_hotel_id'] for r in p['append123']]
 prefix=full[:p['baseline_roster_raw_bytes']-7]+b'\n  ]\n}\n'
 assert hashlib.sha256(prefix).hexdigest()==p['baseline_roster_raw_sha256'],'baseline877_changed'
 assert historical877==prefix,'baseline877_packed_raw_changed'
 return full,packed877
def accepted1000(packed,root):
 """Verify the current bytes and return the exact historical expansion source."""
 return _accepted(packed,root)[0]
def accepted877(packed,root):
 return _accepted(packed,root)[1]
