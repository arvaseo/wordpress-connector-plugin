/* Arva SEO CSS delivery: shared by inline and external library settings. */
(function () {
  if (window.__arvaseoCssLoader) return;
  window.__arvaseoCssLoader = true;
  // The @keyframes names that the styles in use define (not those still waiting, or for print).
  function keyframes() {
    var names = {};
    function walk(rules) {
      for (var i = 0; i < rules.length; i++) {
        if (rules[i].type === 7) names[rules[i].name] = true;
        else if (rules[i].cssRules) walk(rules[i].cssRules);
      }
    }
    var sheets = document.styleSheets || [];
    for (var i = 0; i < sheets.length; i++) {
      try {
        var media = sheets[i].media && sheets[i].media.mediaText;
        if (sheets[i].disabled || (media && window.matchMedia && !window.matchMedia(media).matches)) continue;
        walk(sheets[i].cssRules || []);
      } catch (e) {} // Another site's stylesheet can't be read.
    }
    return names;
  }
  // The page's scripts may have started entrance animations before the full styles arrived
  // (Elementor adds "animated fadeInUp" to what's on screen), and the used CSS couldn't play them.
  // Animations that start only because those styles arrived would play over content already
  // shown, so they end at once; endless ones (spinners) and those already running are left alone.
  function settle(before) {
    if (!document.getAnimations) return;
    document.getAnimations().forEach(function (animation) {
      try {
        var name = animation.animationName;
        var time = animation.currentTime;
        if (name && !before[name] && isFinite(animation.effect.getComputedTiming().endTime) && (time === null || time < 50)) {
          animation.finish();
        }
      } catch (e) {}
    });
  }
  function reveal(link) {
    var before = keyframes();
    link.media = link.getAttribute('data-arvaseo-media') || 'all';
    link.removeAttribute('data-arvaseo-async');
    settle(before);
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
    var before = keyframes();
    document.querySelectorAll('link[data-arvaseo-href]').forEach(function (link) {
      link.addEventListener('load', function () {
        settle(before);
        before = keyframes();
      }, { once: true });
      link.href = link.getAttribute('data-arvaseo-href');
      link.media = link.getAttribute('data-arvaseo-media') || 'all';
    });
  }
  if (document.querySelector('link[data-arvaseo-href]')) {
    events.forEach(function (event) { addEventListener(event, go, { passive: true }); });
  }
})();
