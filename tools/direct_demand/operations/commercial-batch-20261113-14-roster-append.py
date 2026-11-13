import copy,gzip,json
import contract14 as c
old_path=c.Path('/workspace/scratch/715224df0ce7/batch13/closure/roster562/roster562.json.gz')
compressed=old_path.read_bytes()
c.need(c.sha(compressed)=='70eaaf3c8af3ebdc0f770a2a9e5464b74d5fe0838c97d5cc0d6e96bb8fe97c7d','actual556_pin')
old_raw=gzip.decompress(compressed);old=json.loads(old_raw)
c.need(len(old['rows'])==562 and old['schema_version']==1,'old562_scope')
proof=c.proof();final=c.read(c.ROOT/'execution/final-registry.json');cards={h['id']:h for h in final['hotels']}
rows=[]
slot='💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]'
terms='[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]'
for source in proof['manifest']['rows']:
    hid=source['hotel_id'];h=cards[c.SITE_IDS[hid]];text=source['text']
    c.need(h['status']=='published' and h['revision']==3 and h['acceptedRevision']==2 and h['maxText']==h['telegramText']==text and not h['queued'],'site_not_final')
    body,sep,ending=text.rpartition('\n\n')
    c.need(sep and ending=='Подберём тур на ваши даты. Нажмите кнопку под постом.','canonical_actual_footer')
    template=body+'\n\n'+slot+'\n'+terms+'\n\nУзнать стоимость тура'
    c.need(template.count(slot)==template.count(terms)==template.count('Узнать стоимость тура')==1,'template_slots')
    posts={ch:proof['posts'][hid,ch]['post_url'] for ch in c.CHANNELS}
    c.need(all(h['published'][ch]['url']==posts[ch] for ch in c.CHANNELS),'site_receipt')
    rows.append({'site_hotel_id':h['id'],'site_revision':3,'tourvisor_hotel_id':hid,'hotel_id':hid,'logical_id':source['logical_id'],'key':source['logical_id'],'original_publication_operation':c.OPERATION,'original_text_sha256':source['text_sha256'],'cover_sha256':source['photo_sha256'],'posts':posts,'expected_text_sha256':{ch:source['text_sha256'] for ch in c.CHANNELS},'templates':{ch:template for ch in c.CHANNELS},'feed_binding':copy.deepcopy(proof['feedBindings'][hid])})
for key in ['hotel_id','tourvisor_hotel_id','site_hotel_id','logical_id','key']:
    c.need(len({r[key] for r in old['rows']+rows})==564,'roster_duplicate_'+key)
c.need(len({u for r in old['rows']+rows for u in r['posts'].values()})==1128,'duplicate_post_urls')
suffix=b'\n  ]\n}\n';c.need(old_raw.endswith(suffix),'serialization')
appended=b',\n'.join(('\n'.join('    '+line for line in json.dumps(r,ensure_ascii=False,indent=2).splitlines())).encode() for r in rows)
raw=old_raw[:-len(suffix)]+b',\n'+appended+suffix
c.need(json.loads(raw)=={**old,'rows':old['rows']+rows} and raw.startswith(old_raw[:-len(suffix)]),'old562_bytes_changed')
gz=gzip.compress(raw,mtime=0)
out=c.ROOT/'roster564';out.mkdir()
(out/'roster564.json').write_bytes(raw);(out/'roster564.json.gz').write_bytes(gz)
receipt={'ok':True,'operation':c.OPERATION+'-roster-append2','previous_rows':562,'new_rows':2,'total_rows':564,'new_hotel_ids':c.IDS,'old562_rows_identical':True,'old562_bytes_identical':True,'previous_gzip_sha256':c.sha(compressed),'output_raw_sha256':c.sha(raw),'output_gzip_sha256':c.sha(gz),'actual_site_final_version':final['version'],'existing_monitor_baselines_preserved':True,'new_baselines_added':0,'price_processes_started':0,'source_price_provider_calls':0,'publication_summary_sha256':proof['publicationSummarySha256'],'feed_summary_sha256':proof['feedSummarySha256']}
c.save_new(out/'receipt.json',receipt);print(receipt)
