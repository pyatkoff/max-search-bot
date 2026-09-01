(function(){
  function leadSummaryText(){
    const card=document.getElementById('leadCard');
    if(!card||!card.querySelector('.leadHero'))return '';
    const hero=card.querySelector('.leadHero')?.innerText?.trim()||'';
    const trip=card.querySelector('.leadTripDetails')?.innerText?.trim()||'';
    return [hero,trip].filter(Boolean).join('\n\n');
  }
  async function copyLeadSummary(button){
    const text=leadSummaryText();
    if(!text){button.textContent='Выберите лид';setTimeout(()=>button.textContent='⧉ Копировать сводку',1200);return}
    try{
      if(navigator.clipboard?.writeText)await navigator.clipboard.writeText(text);
      else{const area=document.createElement('textarea');area.value=text;area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();document.execCommand('copy');area.remove()}
      button.textContent='✓ Скопировано';
    }catch(e){button.textContent='Не удалось скопировать'}
    setTimeout(()=>button.textContent='⧉ Копировать сводку',1400);
  }
  function installLeadSummaryCopy(){
    const actions=document.querySelector('.leadHeaderActions');
    if(!actions||document.getElementById('copyLeadSummary'))return;
    const button=document.createElement('button');button.id='copyLeadSummary';button.className='actionBtn';button.type='button';button.textContent='⧉ Копировать сводку';button.onclick=()=>copyLeadSummary(button);actions.prepend(button);
  }
  const start=()=>{window.WorkspaceV2?.boot();installLeadSummaryCopy()};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
