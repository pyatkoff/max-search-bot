(()=>{
  const host=document.getElementById('anytour-consultant-host');
  if(!host||!host.shadowRoot)return;
  const root=host.shadowRoot;
  const messages=root.querySelector('.messages');
  if(!messages)return;

  const compactWelcome=()=>{
    if(!messages.querySelector('.msg'))return;
    const welcome=messages.querySelector('.welcome');
    if(welcome)welcome.remove();
    const hint=root.querySelector('.composer-hint');
    if(hint)hint.textContent='Продолжите диалог или уточните пожелания';
  };

  compactWelcome();
  const observer=new MutationObserver(compactWelcome);
  observer.observe(messages,{childList:true,subtree:false});
})();
