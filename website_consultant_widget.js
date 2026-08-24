(function(){
  if (window.AnyTourConsultantWidget) return;
  var script = document.currentScript;
  var api = (script && script.dataset && script.dataset.api) || '/max-search/website_consultant_api.php';
  var title = (script && script.dataset && script.dataset.title) || 'Подбор тура';
  var started = false;

  function el(tag, attrs, text){
    var node=document.createElement(tag); attrs=attrs||{};
    Object.keys(attrs).forEach(function(k){ if(k==='class') node.className=attrs[k]; else node.setAttribute(k,attrs[k]); });
    if(text!=null) node.textContent=text; return node;
  }

  var style=el('style');
  style.textContent='\
  .atc-launch{position:fixed;right:20px;bottom:20px;z-index:2147483000;border:0;border-radius:999px;padding:14px 18px;background:#111;color:#fff;font:600 15px/1.2 Arial,sans-serif;box-shadow:0 8px 28px rgba(0,0,0,.2);cursor:pointer}\
  .atc-panel{position:fixed;right:20px;bottom:78px;z-index:2147483000;width:min(380px,calc(100vw - 24px));height:min(620px,calc(100vh - 110px));background:#fff;border-radius:18px;box-shadow:0 16px 48px rgba(0,0,0,.25);display:none;overflow:hidden;font:14px/1.4 Arial,sans-serif}\
  .atc-panel.open{display:flex;flex-direction:column}.atc-head{padding:15px 16px;border-bottom:1px solid #eee;font-weight:700}.atc-msgs{flex:1;overflow:auto;padding:14px;background:#f7f7f8}.atc-msg{max-width:86%;margin:0 0 10px;padding:10px 12px;border-radius:14px;white-space:pre-wrap}.atc-bot{background:#fff}.atc-user{background:#111;color:#fff;margin-left:auto}.atc-buttons{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}.atc-buttons button{border:1px solid #ddd;background:#fff;border-radius:10px;padding:8px 10px;cursor:pointer}.atc-compose{display:flex;gap:8px;padding:10px;border-top:1px solid #eee}.atc-compose input{flex:1;border:1px solid #ddd;border-radius:10px;padding:10px}.atc-compose button{border:0;border-radius:10px;padding:0 14px;background:#111;color:#fff;cursor:pointer}.atc-error{font-size:12px;color:#a00;margin:6px 2px}\
  ';
  document.head.appendChild(style);

  var launch=el('button',{class:'atc-launch',type:'button'},'Онлайн-консультант');
  var panel=el('div',{class:'atc-panel'});
  var head=el('div',{class:'atc-head'},title);
  var msgs=el('div',{class:'atc-msgs'});
  var form=el('form',{class:'atc-compose'});
  var input=el('input',{type:'text',placeholder:'Напишите пожелания по туру…',autocomplete:'off'});
  var sendBtn=el('button',{type:'submit'},'→');
  form.appendChild(input); form.appendChild(sendBtn);
  panel.appendChild(head); panel.appendChild(msgs); panel.appendChild(form);
  document.body.appendChild(launch); document.body.appendChild(panel);

  function addUser(text){ var m=el('div',{class:'atc-msg atc-user'},text); msgs.appendChild(m); scroll(); }
  function addBot(text, buttons){
    var m=el('div',{class:'atc-msg atc-bot'},String(text||'').replace(/<\/?b>/g,'')); msgs.appendChild(m);
    if(Array.isArray(buttons)&&buttons.length){
      var wrap=el('div',{class:'atc-buttons'});
      buttons.forEach(function(row){ (row||[]).forEach(function(b){
        if(!b||!b.text) return;
        var btn=el('button',{type:'button'},b.text);
        btn.onclick=function(){
          if(b.url){ window.open(b.url,'_blank','noopener'); return; }
          if(b.callback_data){ request({action:'callback',data:b.callback_data}); }
        };
        wrap.appendChild(btn);
      }); });
      msgs.appendChild(wrap);
    }
    scroll();
  }
  function scroll(){ msgs.scrollTop=msgs.scrollHeight; }
  function setBusy(v){ input.disabled=v; sendBtn.disabled=v; }
  function error(text){ msgs.appendChild(el('div',{class:'atc-error'},text)); scroll(); }

  function request(payload){
    setBusy(true);
    return fetch(api,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},credentials:'same-origin',body:JSON.stringify(payload)})
      .then(function(r){ return r.json().then(function(j){ if(!r.ok||!j.ok) throw new Error(j.error||('HTTP '+r.status)); return j; }); })
      .then(function(j){ (j.messages||[]).forEach(function(m){ addBot(m.text,m.buttons); }); return j; })
      .catch(function(){ error('Не получилось отправить сообщение. Попробуйте ещё раз.'); })
      .finally(function(){ setBusy(false); input.focus(); });
  }

  launch.onclick=function(){
    panel.classList.toggle('open');
    if(panel.classList.contains('open')&&!started){ started=true; request({action:'start'}); }
    if(panel.classList.contains('open')) input.focus();
  };
  form.onsubmit=function(e){
    e.preventDefault(); var text=input.value.trim(); if(!text) return;
    input.value=''; addUser(text); request({action:'message',text:text});
  };

  window.AnyTourConsultantWidget={open:function(){ if(!panel.classList.contains('open')) launch.click(); }};
})();
