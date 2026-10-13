(function(){
  const zone='Europe/Moscow';
  const formatter=new Intl.DateTimeFormat('en-GB',{timeZone:zone,calendar:'gregory',numberingSystem:'latn',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'});
  function parse(value){
    if(value instanceof Date)return Number.isNaN(value.getTime())?null:new Date(value.getTime());
    const raw=String(value||'').trim(),m=raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,3}))?)?(Z|[+-]\d{2}:?\d{2})?$/);
    if(!m)return null;
    // Database timestamps are UTC; explicit offsets already identify an instant.
    const civil=new Date(`${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6]||'00'}Z`);
    if(Number.isNaN(civil.getTime())||civil.getUTCFullYear()!==+m[1]||civil.getUTCMonth()+1!==+m[2]||civil.getUTCDate()!==+m[3]||civil.getUTCHours()!==+m[4]||civil.getUTCMinutes()!==+m[5]||civil.getUTCSeconds()!==+(m[6]||0))return null;
    const date=new Date(raw.replace(' ','T')+(m[8]?'':'Z'));
    return Number.isNaN(date.getTime())?null:date;
  }
  function parts(value){const date=parse(value);if(!date)return null;return Object.fromEntries(formatter.formatToParts(date).filter(p=>p.type!=='literal').map(p=>[p.type,p.value]));}
  function format(value,{separator=' ',year=false}={}){
    const p=parts(value);if(!p)return String(value||'').trim();
    return `${p.day}.${p.month}${year?'.'+p.year:''}${separator}${p.hour}:${p.minute}`;
  }
  function toInput(value){const p=parts(value);return p?`${p.year.padStart(4,'0')}-${p.month}-${p.day}T${p.hour}:${p.minute}`:'';}
  function fromInput(value){
    const raw=String(value||'').trim();if(!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/.test(raw))return null;
    const wall=parse(raw+'Z');if(!wall)return null;
    let date=wall;
    // Resolve the Moscow wall clock through IANA rules, independent of the device.
    for(let i=0;i<3;i++){
      const p=parts(date),projected=new Date(`${p.year.padStart(4,'0')}-${p.month}-${p.day}T${p.hour}:${p.minute}:${p.second}Z`);
      const delta=wall.getTime()-projected.getTime();if(!delta)break;date=new Date(date.getTime()+delta);
    }
    return toInput(date)===raw.slice(0,16)?date.toISOString():null;
  }
  function wallDate(value){const p=parts(value);return p?new Date(`${p.year.padStart(4,'0')}-${p.month}-${p.day}T${p.hour}:${p.minute}:${p.second}Z`):null;}
  function fromWallDate(date){const iso=fromInput(date.toISOString().slice(0,19));return iso?new Date(iso):null;}
  window.ManagerTime={zone,label:'МСК',parse,format,toInput,fromInput,wallDate,fromWallDate};
})();
