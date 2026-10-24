/* Arva SEO CSS delivery: shared by inline and external library settings. */
(function () {
  if (window.__arvaseoCssLoader) return;
  window.__arvaseoCssLoader = true;
  function reveal(link) {
    link.media = link.getAttribute('data-arvaseo-media') || 'all';
    link.removeAttribute('data-arvaseo-async');
  }
  document.querySelectorAll('link[data-arvaseo-async]').forEach(function (link) {
    link.addEventListener('load', function () { reveal(link); }, { once: true });
    // The stylesheet may finish loading before this footer library runs.
    if (link.sheet) reveal(link);
  });
  var done = false;
  var events = ['pointerdown', 'keydown', 'touchstart', 'scroll', 'wheel', 'mousemove'];
  function go() {
    if (done) return;
    done = true;
    events.forEach(function (event) { removeEventListener(event, go); });
    document.querySelectorAll('link[data-arvaseo-href]').forEach(function (link) {
      link.href = link.getAttribute('data-arvaseo-href');
      link.media = link.getAttribute('data-arvaseo-media') || 'all';
    });
  }
  if (document.querySelector('link[data-arvaseo-href]')) {
    events.forEach(function (event) { addEventListener(event, go, { passive: true }); });
  }
})();
