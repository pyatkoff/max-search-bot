<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$tasks=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.js');
$lead=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.css');
$passed=0;$failed=0;
function tmCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

tmCheck('task row mutations share one async guard',strpos($tasks,'async function runMutation')!==false&&strpos($tasks,'if(control.disabled)return')!==false&&strpos($tasks,"row?.classList.add('saving')")!==false);
tmCheck('completion mutation awaits backend and reverts checkbox on failure',strpos($tasks,'runMutation(el,row,()=>onToggle')!==false&&strpos($tasks,'el.checked=!wanted')!==false);
tmCheck('pin mutation awaits backend before trusting refreshed row',strpos($tasks,'runMutation(el,row,()=>onPin')!==false&&strpos($tasks,"el.dataset.pinned!=='1'")!==false);
tmCheck('failed row mutation is visibly marked and controls recover',strpos($tasks,"row?.classList.add('saveError')")!==false&&strpos($tasks,'control.disabled=false')!==false&&strpos($css,'.taskRow.saveError')!==false);
tmCheck('busy row mutation is visibly distinct',strpos($css,'.taskRow.saving')!==false&&strpos($css,'.taskPinBtn:disabled')!==false);
tmCheck('task module returns explicit success or failure for completion',strpos($tasks,"async function toggleTask")!==false&&strpos($tasks,"W.pipe('set_task_completed'")!==false&&strpos($tasks,'if(!j.ok)return false')!==false&&substr_count($tasks,'return refreshLead(target)')>=4);
tmCheck('task module returns explicit success or failure for pinning',strpos($tasks,"async function pinTask")!==false&&strpos($tasks,"W.pipe('set_task_pinned'")!==false&&strpos($tasks,'if(!j.ok)return false')!==false);
tmCheck('lead card delegates task mutation ownership',strpos($lead,'WorkspaceV2Tasks.render(root,{tasks,canEdit,conversationId})')!==false&&strpos($lead,"pipe('set_task_completed'")===false&&strpos($lead,"pipe('set_task_pinned'")===false);
tmCheck('task editor starts with save disabled',strpos($tasks,'data-task-edit-save="${id}" disabled')!==false);
tmCheck('task editor exposes dirty-state comparison for title and deadline',strpos($tasks,"const syncDirty=()=>")!==false&&strpos($tasks,"String(titleEl?.value||'').trim()!==initialTitle")!==false&&strpos($tasks,"String(dueEl?.value||'')!==initialDue")!==false);
tmCheck('task editor clearly signals unsaved edits',strpos($tasks,'Есть несохранённые изменения')!==false&&strpos($tasks,"dirty?'dirty':'")!==false&&strpos($css,'.taskEditStatus.dirty')!==false);
tmCheck('task editor cancel restores persisted values before closing',strpos($tasks,'form._taskEditReset=()=>')!==false&&strpos($tasks,'form?._taskEditReset?.()')!==false);
tmCheck('task save refuses unchanged payloads',strpos($tasks,'!form._taskEditSyncDirty?.()')!==false);
tmCheck('dirty task editor cannot be hidden by its edit toggle',strpos($tasks,'if(form._taskEditIsDirty?.())')!==false&&strpos($tasks,"form._taskEditWarn?.()")!==false&&strpos($tasks,"Сохраните или отмените изменения")!==false);
tmCheck('opening another task editor cannot discard an existing dirty draft',strpos($tasks,"const otherDirty=[...root.querySelectorAll('.taskEditForm:not(.hidden)')]")!==false&&strpos($tasks,'x!==form&&x._taskEditIsDirty?.()')!==false&&strpos($tasks,'otherDirty._taskEditWarn?.()')!==false);
tmCheck('task dirty state is retained as an explicit form-owned draft flag',strpos($tasks,'form._taskEditDirty=dirty')!==false&&strpos($tasks,'form._taskEditIsDirty=()=>!!form._taskEditDirty')!==false);
tmCheck('task completion cannot discard an unsaved editor draft',strpos($tasks,'const blockForDirtyDraft=()=>')!==false&&strpos($tasks,'if(blockForDirtyDraft()){el.checked=!wanted;return}')!==false);
tmCheck('task pinning cannot discard an unsaved editor draft',strpos($tasks,"root.querySelectorAll('[data-task-pin]')")!==false&&strpos($tasks,'if(blockForDirtyDraft())return;const wanted=el.dataset.pinned')!==false);
tmCheck('dirty mutation guard reuses the task editor warning and focus path',strpos($tasks,'form._taskEditWarn?.();form.querySelector(\'[data-task-edit-title]\')?.focus();return true')!==false);
tmCheck('task mutation UX does not alter technical conversation state',strpos($tasks,'set_status')===false&&strpos($lead,"pipe('set_status'")===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
