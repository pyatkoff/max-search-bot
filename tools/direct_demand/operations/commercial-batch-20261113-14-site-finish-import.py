import copy,json,urllib.error
from pathlib import Path
import contract14 as c
import site_source14 as s
m=c.source_client();out=c.ROOT/'execution';original_plan=c.read(c.ROOT/'plan.json');plan=copy.deepcopy(original_plan)
short='Три разные официальные фотографии своего отеля просмотрены. Исходные фото и принятая история сохранены; дата съёмки неизвестна.'
for row in plan['edits']:row['photoSet']['label']=short
# This is a first actual import, after reconciling a rejected read-only preview.
c.need(not (out/'import.attempt.json').exists(),'actual_import_already_attempted')
c.save_new(out/'plan-short-photo-label.json',plan)
class ObservedClient(m.Client):
 def request(self,path,payload=None):
  try:return super().request(path,payload)
  except urllib.error.HTTPError as e:
   c.save_new(out/'import-http-error-body.json',{'status':e.code,'path':path,'body':e.read().decode('utf-8','replace')[:4000]});raise
client=ObservedClient(m.hidden_credentials());current=client.request('/api/registry?view=export');before=c.read(out/'initial-registry.json');s.verify_after_edits(before,current,original_plan)
c.need(current==c.read(out/'before-import-registry.json') and current['version']==343,'source_changed_after_rejected_preview')
c.need(not any(e['id']==s.IMPORT_ID for e in current['events']),'import_event_already_present')
rows=s.import_rows(current,plan);body={'version':current['version'],'operationId':s.IMPORT_ID,'action':{'type':'import','label':'Batch14 · два опубликованных отеля и подтверждённый feed','rows':rows}}
c.save_new(out/'preview-reconciliation.json',{'originalPreview':'HTTP400','originalVersion':343,'freshVersion':current['version'],'importEventAbsent':True,'actualImportAttemptAbsent':True,'sourceEditsNotReplayed':True,'changedFields':['new photoSet.label only, 339 characters -> '+str(len(short))],'operationIdUnchanged':s.IMPORT_ID,'automaticRetry':False})
preview=m.one_post(client,out,'preview-short-photo-label',body,{'version':343,'rowsSha256':c.digest(rows)},preview=True)
c.need(preview.get('version')==343 and preview.get('added')==preview.get('rejected')==0 and preview.get('merged')==2 and preview.get('details')==[],'short_label_preview_rejected')
m.one_post(client,out,'import',body,{'version':343,'rowsSha256':c.digest(rows)})
final=client.request('/api/registry?view=export');c.save_new(out/'final-registry.json',final);s.verify_import(current,final,plan)
proof=c.proof();receipt={'ok':True,'operation':c.OPERATION,'initialVersion':341,'finalVersion':final['version'],'sourceEdits':2,'importOperations':1,'publicationSnapshotsAdded':4,'otherHotelsCompared':664,'otherHotelsUnchanged':True,'acceptedRevisionPreserved':2,'currentRevision':3,'priorContentVersionsPreserved':True,'privateFactsPreserved':True,'monitorPriceSnapshotsPreserved':True,'actualFeedPresenceAdded':2,'automaticRetries':0,'originalPreviewHTTP400Preserved':True,'onlyNewPhotoSetLabelsShortened':True,'sameImportOperationId':s.IMPORT_ID,'actualImportAttempts':1,'publicationSummarySha256':proof['publicationSummarySha256'],'feedSummarySha256':proof['feedSummarySha256'],'initialRegistrySha256':c.digest(before),'finalRegistrySha256':c.digest(final)}
c.save_new(out/'verification.json',receipt);print(receipt)
