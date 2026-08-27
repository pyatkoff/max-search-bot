(function(){
  function esc(value){
    const div=document.createElement('div');
    div.textContent=value??'';
    return div.innerHTML;
  }

  function reasonText(reason){
    return {
      healthy_subscription:'Уведомления включены',
      no_subscription:'Уведомления не подключены',
      subscription_unhealthy:'Уведомления требуют внимания',
      subscription_table_missing:'Уведомления недоступны',
      manager_inactive:'Профиль менеджера неактивен',
      managers_table_missing:'Статус уведомлений недоступен',
      health_check_failed:'Не удалось проверить уведомления'
    }[reason]||'Статус уведомлений неизвестен';
  }

  function render(root,status){
    if(!root)return;
    const usable=!!status?.notification_path_usable;
    const count=Number(status?.healthy_subscription_count||0);
    const reason=String(status?.notification_path_reason||'');
    const working=!!status?.is_working;
    const label=reasonText(reason);
    const suffix=usable&&count>0?` · ${count} ${count===1?'устройство':'устройства'}`:'';
    const shift=working?'Смена включена':'Вне смены';
    root.className='notificationHealth '+(usable?'ok':'warn');
    root.innerHTML=`<span class="notificationDot" aria-hidden="true"></span><span class="notificationText"><strong>${esc(label)}</strong><small>${esc(shift+suffix)}</small></span>${usable?'':`<a class="notificationAction" href="push-enable.php">Настроить</a>`}`;
  }

  async function refresh(){
    const root=document.getElementById('notificationStatus');
    if(!root)return;
    try{
      const response=await fetch('push-status.php',{headers:{Accept:'application/json'},cache:'no-store'});
      const data=await response.json().catch(()=>null);
      if(response.status===401){location.href='index.php';return;}
      if(!response.ok||!data?.ok){throw new Error('push_status_failed');}
      render(root,data.push_status||{});
    }catch(error){
      render(root,{notification_path_usable:false,notification_path_reason:'health_check_failed',is_working:false});
    }
  }

  window.WorkspaceV2Notifications={refresh};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refresh,{once:true});else refresh();
})();
