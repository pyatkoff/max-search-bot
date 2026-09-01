(function(){
  let bound=false;
  const $=id=>document.getElementById(id);
  function clean(text){return String(text||'').replace(/\s+/g,' ').trim()}
  function summaryText(){
    const card=$('leadCard');
    if(!card?.querySelector('.leadHero'))return '';
    const lines=[];
    const push=(text)=>{text=clean(text);if(text)lines.push(text)};
    push(card.querySelector('.leadHeroName')?.textContent);
    push(card.querySelector('.leadHeroSource')?.textContent);
    push(card.querySelector('.leadContactActions')?.textContent);
    push(card.querySelector('.leadRouteMain')?.textContent);
    push(card.querySelector('.leadHandoffMain')?.textContent);
    const details=[...card.querySelectorAll('.leadTripDetails .leadDetailRow')].map(row=>{
      const label=clean(row.querySelector('.label')?.textContent),value=clean(row.querySelector('.value')?.textContent);
      return label&&value?`${label}: ${value}`:'';
    }).filter(Boolean);
    if(details.length)lines.push(details.join('\n'));
    return lines.join('\n');
  }
  async function writeClipboard(text){
    if(navigator.clipboard?.writeText){await navigator.clipboard.writeText(text);return true}
    const area=document.createElement('textarea');
    area.value=text;area.setAttribute('readonly','');area.style.position='fixed';area.style.opacity='0';
    document.body.appendChild(area);area.select();
    let ok=false;try{ok=document.execCommand('copy')}finally{area.remove()}
    if(!ok)throw new Error('copy_failed');
    return true;
  }
  function bind(){
    if(bound)return;bound=true;
    const button=$('copyLeadSummary'),card=$('leadCard');
    if(!button||!card)return;
    const update=()=>{button.disabled=!card.querySelector('.leadHero')};
    button.onclick=async()=>{
      const text=summaryText();if(!text)return;
      const original='⧉ Сводка';button.disabled=true;button.textContent='Копируем…';
      try{await writeClipboard(text);button.textContent='✓ Скопировано'}catch(e){button.textContent='Не удалось'}
      setTimeout(()=>{button.textContent=original;update()},1400);
    };
    new MutationObserver(update).observe(card,{childList:true,subtree:false});
    update();
  }
  window.WorkspaceV2LeadSummaryCopy={bind,summaryText,writeClipboard};
})();
