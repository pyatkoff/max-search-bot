(()=>{
  if(document.getElementById('anytour-consultant-host'))return;
  const current=document.currentScript;
  const source=current&&current.src?current.src:location.href;
  const canonical=new URL('../web-consultant/widget.js',source).toString();
  if(document.querySelector('script[data-anytour-web-consultant-canonical]'))return;
  const script=document.createElement('script');
  script.src=canonical;
  script.async=true;
  script.setAttribute('data-anytour-web-consultant-canonical','1');
  document.head.appendChild(script);
})();
