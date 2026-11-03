
const vm=require('node:vm');
const input=JSON.parse(require('node:fs').readFileSync(0,'utf8'));
let checked=0;
for(const row of input.rows){
 const fields={};
 for(const name of ['from','country','region','hotel'])fields[name]={tagName:'SELECT',value:name==='from'?'1':'',options:[{value:''}],appendChild(o){this.options.push(o);}};
 const url='?country=1&region='+row.region_id+'&hotel='+row.hotel_id;
 const context={URLSearchParams,window:{location:{search:url}},form:{elements:fields,querySelectorAll(){return[];}},document:{createElement(){return{};}},catalogs:{renderChildAges(){},updateServiceCount(){}},FormData:class{constructor(f){this.f=f;}get(n){return this.f.elements[n]?.value||'';}getAll(){return[];}}};
 vm.createContext(context);
 vm.runInContext(input.functions+'\nresult={hydrated:hydrateUrlState(),params:params()};',context);
 const result=context.result;
 if(result.hydrated.auto!==false||String(result.params.countryId)!=='1'||String(result.params.regionIds[0])!==String(row.region_id)||String(result.params.hotelIds[0])!==String(row.hotel_id))throw Error('hotel destination contract changed');
 checked++;
}
process.stdout.write(JSON.stringify({checked,automatic_search:false,selected_hotel_preserved:true,network_calls:0}));
