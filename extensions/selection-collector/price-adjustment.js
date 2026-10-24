(function(root,factory){
'use strict';const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;else root.AnyTourPriceAdjustment=api;
})(globalThis,function(){
'use strict';
const max=99999999999n;
function units(value){
 if(typeof value!=='string')throw new Error('Введите сумму цифрами.');
 const s=value.trim().replace(/[\s\u00a0\u202f]/g,'').replace(',','.');
 if(!/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/.test(s))throw new Error('Нужна сумма с точностью до двух знаков.');
 const [whole,part='']=s.split('.');return BigInt(whole)*100n+BigInt(part.padEnd(2,'0'));
}
function format(cents){
 if(cents<=0n||cents>max)throw new Error('Итоговая цена должна быть больше нуля и меньше миллиарда.');
 const whole=String(cents/100n),part=String(cents%100n).padStart(2,'0');return part==='00'?whole:whole+'.'+part;
}
return Object.freeze({
 total:value=>format(units(value)),
 adjust(base,action,value,basis,round='none'){
  const start=units(base),change=units(value);if(start<=0n||start>max)throw new Error('Исходная цена недоступна.');
  if(!['discount','markup'].includes(action)||!['percent','amount'].includes(basis))throw new Error('Выберите способ изменения цены.');
  if(basis==='percent'&&action==='discount'&&change>10000n)throw new Error('Скидка не может превышать 100 процентов.');
  const delta=basis==='percent'?(start*change+5000n)/10000n:change;
  let result=action==='discount'?start-delta:start+delta;
  const steps={none:1n,unit:100n,ten:1000n,hundred:10000n,thousand:100000n};
  if(!Object.hasOwn(steps,round))throw new Error('Выберите округление.');
  const step=steps[round];if(step>1n)result=((result+step/2n)/step)*step;
  return format(result);
 }
});
});
