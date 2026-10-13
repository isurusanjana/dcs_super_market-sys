(function(){
  // Bootstrap client-side validation
  document.querySelectorAll('form.needs-validation').forEach(function(f){
    f.addEventListener('submit',function(ev){ if(!f.checkValidity()){ev.preventDefault();ev.stopPropagation();} f.classList.add('was-validated'); },false);
  });
  // Confirm dialogs
  document.addEventListener('submit',function(ev){ var m=ev.target.getAttribute('data-confirm'); if(m && !confirm(m)){ev.preventDefault();} });
  // Prevent double submit
  document.addEventListener('submit',function(ev){ if(ev.defaultPrevented) return; var b=ev.target.querySelector('button[type=submit].once,button.once'); if(b){setTimeout(function(){b.disabled=true;},0);} });
  // Auto-submit selects
  document.querySelectorAll('select[data-autosubmit]').forEach(function(s){ s.addEventListener('change',function(){ s.form.submit(); }); });
})();
window.postJSON=function(route,data){
  return fetch(window.APP.base+'?r='+route,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.APP.csrf,'X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(data||{})}).then(function(r){return r.json();});
};
window.getJSON=function(route,params){
  var q=new URLSearchParams(params||{}).toString();
  return fetch(window.APP.base+'?r='+route+(q?'&'+q:''),{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();});
};
