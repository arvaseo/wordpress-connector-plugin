/* Arva SEO CSS delivery: shared by inline and external library settings. */
(function () {
  if (window.__arvaseoCssLoader) return;
  window.__arvaseoCssLoader = true;
  function rules(sheet) {
    try {
      return (sheet && sheet.cssRules) || [];
    } catch (e) {
      return []; // Another site's stylesheet can't be read.
    }
  }
  // The @keyframes names some rules define, inside @media and @supports too.
  function names(list, into) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].type === 7) into[list[i].name] = true;
      else if (list[i].cssRules) names(list[i].cssRules, into);
    }
    return into;
  }
  // The @keyframes names the styles in use define (not those still waiting, or for print),
  // worked out once; each stylesheet that arrives adds its own.
  var defined = null;
  function inUse() {
    if (defined) return defined;
    defined = {};
    var sheets = document.styleSheets || [];
    for (var i = 0; i < sheets.length; i++) {
      try {
        var media = sheets[i].media && sheets[i].media.mediaText;
        if (sheets[i].disabled || (media && window.matchMedia && !window.matchMedia(media).matches)) continue;
        names(rules(sheets[i]), defined);
      } catch (e) {}
    }
    return defined;
  }
  // The page's scripts may have started entrance animations before the full styles arrived
  // (Elementor adds "animated fadeInUp" to what's on screen), and the used CSS couldn't play them.
  // Animations that start only because a stylesheet arrived with their keyframes would play over
  // content already shown, so they end at once. Endless ones (spinners), those already running,
  // and those whose keyframes were there before (or came from elsewhere) are left alone.
  function settle(link) {
    var before = inUse();
    var arrived = names(rules(link.sheet), {});
    var fresh = {};
    var any = false;
    for (var name in arrived) {
      if (!before[name]) {
        fresh[name] = true;
        any = true;
      }
      before[name] = true;
    }
    if (!any || !document.getAnimations) return;
    document.getAnimations().forEach(function (animation) {
      try {
        var time = animation.currentTime;
        if (fresh[animation.animationName] && isFinite(animation.effect.getComputedTiming().endTime) && (time === null || time < 50)) {
          animation.finish();
        }
      } catch (e) {}
    });
  }
  function reveal(link) {
    inUse(); // Before this stylesheet is in use.
    link.media = link.getAttribute('data-arvaseo-media') || 'all';
    link.removeAttribute('data-arvaseo-async');
    settle(link);
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
    inUse(); // Before any full stylesheet is in use.
    document.querySelectorAll('link[data-arvaseo-href]').forEach(function (link) {
      link.addEventListener('load', function () { settle(link); }, { once: true });
      link.href = link.getAttribute('data-arvaseo-href');
      link.media = link.getAttribute('data-arvaseo-media') || 'all';
    });
  }
  if (document.querySelector('link[data-arvaseo-href]')) {
    events.forEach(function (event) { addEventListener(event, go, { passive: true }); });
  }
})();
