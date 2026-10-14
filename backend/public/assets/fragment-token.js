// Moves the one-time token from the URL fragment (#token=…) into the form, then removes
// it from the address bar. Fragments are never sent to servers, so the token stays out
// of access logs and Referer headers.
(function () {
  var m = /(?:^|[#&])token=([A-Za-z0-9]+)/.exec(window.location.hash || '');
  var field = document.getElementById('token');
  if (m && field) {
    field.value = m[1];
    history.replaceState(null, '', window.location.pathname);
  }
})();
