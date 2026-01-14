/* Minimal HTMX-like ajax helper to support dashboard partial swaps when HTMX isn't present. */
(function(){
  if (window.htmx && typeof window.htmx.ajax === 'function') return;
  window.htmx = window.htmx || {};
  window.htmx.ajax = function(method, url, opts) {
    opts = opts || {};
    var targetSel = opts.target || null;
    var swap = (opts.swap || 'innerHTML');
    var vals = opts.values || {};
    return new Promise(function(resolve){
      var xhr = new XMLHttpRequest();
      xhr.open(method || 'GET', url, true);
      xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
      xhr.onreadystatechange = function(){
        if (xhr.readyState === 4) {
          try {
            if (targetSel) {
              var target = document.querySelector(targetSel);
              if (target && xhr.status >= 200 && xhr.status < 300) {
                if (swap === 'outerHTML') { target.outerHTML = xhr.responseText; }
                else { target.innerHTML = xhr.responseText; }
              }
            }
          } catch(e){}
          resolve(xhr);
        }
      };
      var body = Object.keys(vals).map(function(k){ return encodeURIComponent(k)+'='+encodeURIComponent(vals[k]); }).join('&');
      xhr.send(body);
    });
  }
})();

