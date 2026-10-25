const {setup:workspaceSetup,root}=require('./internal-notes');
const clone=value=>JSON.parse(JSON.stringify(value));
function items(total=37){
 const base=[
  {key:'event:9',kind:'event',created_at:'2026-10-14 12:00:00',actor:'Менеджер',title:'Менеджер принял диалог',details:[]},
  {key:'name:1',kind:'name',created_at:'2026-10-14 11:00:00',actor:'Вы',title:'Изменено рабочее имя клиента',details:[{label:'Было',value:'Исходное имя'},{label:'Стало',value:'Анна Морозова'}]},
  {key:'stage:2',kind:'stage',created_at:'2026-10-14 10:00:00',actor:'Менеджер',title:'Изменён этап работы',details:[{label:'Этап',value:'Новый → Подбор'}]},
  {key:'note:3',kind:'note',created_at:'2026-10-14 09:00:00',actor:'Менеджер',title:'Добавлена внутренняя заметка',current_record:true,updated_at:'2026-10-14 12:30:00',details:[{label:'Текущее содержание',value:'Предпочитает тихий корпус. <b>Текст заметки</b>'}]},
  {key:'task:4',kind:'task',created_at:'2026-10-14 08:00:00',actor:'Вы',title:'Создана задача',current_record:true,updated_at:'2026-10-14 12:00:00',due_at:'2026-10-20 09:00:00',details:[{label:'Текущая задача',value:'Подготовить варианты у моря'},{label:'Сейчас',value:'Открыта'}]},
 ];
 return Array.from({length:total},(_,i)=>i<base.length?base[i]:{key:'event:'+(100+i),kind:'event',created_at:'2026-10-13 09:00:00',actor:'Клиент',title:'Клиент запросил менеджера',details:[]});
}
async function setup(page,options={}){
 const s=await workspaceSetup(page,options),calls=[],held=[];
 let hold=!!options.hold,error=options.error||'',current=options.items||items(options.total??37);
 await page.route('**/manager/client-events-api.php',async route=>{
  const d=route.request().postDataJSON();calls.push(clone(d));
  if(d.action!=='list'||d.csrf!=='fixture-csrf'||![11,12].includes(d.conversation_id)||d.page_size!==30)throw new Error('Unexpected client events request');
  const capturedError=error,offset=d.cursor?30:0,selected=clone(current.slice(offset,offset+30)),more=current.length>offset+selected.length;
  const response={ok:true,conversation_id:d.conversation_id,items:selected,history:{has_more:more,cursor:more?'synthetic-next-page':'',page_size:30}};
  if(hold)await new Promise(resolve=>held.push(resolve));
  return capturedError?route.fulfill({status:capturedError==='unauthorized'?401:capturedError==='forbidden'?403:503,contentType:'application/json',body:JSON.stringify({ok:false,error:capturedError})}):route.fulfill({contentType:'application/json',body:JSON.stringify(response)});
 });
 return {...s,eventCalls:calls,setHold:value=>hold=value,setError:value=>error=value,setItems:value=>current=value,release:()=>held.splice(0).forEach(resolve=>resolve())};
}
module.exports={setup,root,items};
