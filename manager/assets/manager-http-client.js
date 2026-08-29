window.ManagerHttpClient={
  async request(action,data={},csrf=''){
    try{
      const response=await fetch('api.php',{
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action,csrf,...data})
      });
      const payload=await response.json();
      return payload&&typeof payload==='object'?payload:{ok:false,error:'invalid_response'};
    }catch(e){
      return{ok:false,error:'network_error'};
    }
  }
};
