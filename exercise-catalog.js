(function (global) {
  var STORAGE_KEY = 'schaatskompas-exercise-catalog-v1';
  var CATEGORY_PREFIX = 'custom-category-';
  var EXERCISE_PREFIX = 'custom-exercise-';

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
    });
  }

  function createId(prefix) {
    return prefix + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  }

  function validateMediaUrl(value) {
    var trimmed = String(value || '').trim();
    if (!trimmed) return '';
    if (trimmed.length > 2048) throw new Error('Een video- of animatielink mag maximaal 2048 tekens bevatten.');
    var url;
    try {
      url = new URL(trimmed);
    } catch (error) {
      throw new Error('Gebruik een volledige https-link voor video of animatie.');
    }
    if (url.protocol !== 'https:' || url.username || url.password) {
      throw new Error('Video- en animatielinks moeten een veilige https-link zijn.');
    }
    return url.href;
  }

  function load() {
    var stored = localStorage.getItem(STORAGE_KEY);
    if (!stored) return { version: 1, categories: [], exercises: [] };
    var catalog;
    try {
      catalog = JSON.parse(stored);
    } catch (error) {
      throw new Error('De oefeningencatalogus kon niet worden gelezen. De browseropslag bevat ongeldige gegevens.');
    }
    if (!catalog || catalog.version !== 1 || !Array.isArray(catalog.categories) || !Array.isArray(catalog.exercises)) {
      throw new Error('De opgeslagen oefeningencatalogus heeft een onbekend formaat.');
    }
    catalog.categories.forEach(function (category) {
      if (!category || typeof category.id !== 'string' || category.id.indexOf(CATEGORY_PREFIX) !== 0 ||
          typeof category.name !== 'string' || !['blue', 'orange'].includes(category.tone)) {
        throw new Error('Een opgeslagen oefeningencategorie is ongeldig.');
      }
    });
    catalog.exercises.forEach(function (exercise) {
      if (!exercise || typeof exercise.id !== 'string' || exercise.id.indexOf(EXERCISE_PREFIX) !== 0 ||
          typeof exercise.name !== 'string' || typeof exercise.categoryId !== 'string' ||
          typeof exercise.explanation !== 'string' || !Number.isInteger(exercise.minutes) ||
          exercise.minutes < 1 || exercise.minutes > 240) {
        throw new Error('Een opgeslagen oefening is ongeldig.');
      }
      exercise.videoUrl = validateMediaUrl(exercise.videoUrl);
      exercise.animationUrl = validateMediaUrl(exercise.animationUrl);
    });
    return catalog;
  }

  function save(catalog) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(catalog));
  }

  function colors(tone) {
    return tone === 'orange' ?
      { color: '#C2410C', background: '#FFF4E8', accent: '#F97316' } :
      { color: '#1E3A8A', background: '#EAF3FF', accent: '#1E40AF' };
  }

  function mediaMarkup(value, label) {
    var url = validateMediaUrl(value);
    if (!url) return '';
    var parsed = new URL(url);
    var host = parsed.hostname.toLowerCase();
    var title = escapeHtml(label);
    var escapedUrl = escapeHtml(url);
    var videoId = '';
    if (host === 'youtu.be') {
      videoId = parsed.pathname.slice(1);
    } else if (['youtube.com', 'www.youtube.com', 'm.youtube.com'].indexOf(host) >= 0) {
      var youtubeEmbed = parsed.pathname.match(/^\/embed\/([A-Za-z0-9_-]+)/);
      videoId = parsed.searchParams.get('v') || (youtubeEmbed ? youtubeEmbed[1] : '');
    } else if (host === 'youtube-nocookie.com' || host === 'www.youtube-nocookie.com') {
      var noCookieEmbed = parsed.pathname.match(/^\/embed\/([A-Za-z0-9_-]+)/);
      videoId = noCookieEmbed ? noCookieEmbed[1] : '';
    }
    if (videoId && /^[A-Za-z0-9_-]{6,20}$/.test(videoId)) {
      return '<iframe loading="lazy" src="https://www.youtube-nocookie.com/embed/' + encodeURIComponent(videoId) +
        '" title="' + title + '" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>';
    }
    if (host === 'vimeo.com' || host === 'www.vimeo.com') {
      var vimeoId = parsed.pathname.match(/^\/(\d+)/);
      if (vimeoId) {
        return '<iframe loading="lazy" src="https://player.vimeo.com/video/' + vimeoId[1] +
          '" title="' + title + '" allow="autoplay; fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>';
      }
    }
    if (/\.(mp4|webm|ogg)(?:$)/i.test(parsed.pathname)) {
      return '<video controls preload="metadata" playsinline aria-label="' + title + '" src="' + escapedUrl + '"></video>';
    }
    if (/\.gif(?:$)/i.test(parsed.pathname)) {
      return '<img loading="lazy" alt="' + title + '" src="' + escapedUrl + '">';
    }
    return '<a href="' + escapedUrl + '" target="_blank" rel="noopener noreferrer">' + title + ' openen</a>';
  }

  function renderExerciseCard(exercise, categoryName) {
    var colorsForCategory = colors(exercise.tone);
    var builderHref = global.location.pathname.toLowerCase().indexOf('/oefeningen/') >= 0 ?
      '../Builder.dc.html' : 'Builder.dc.html';
    return '<article class="card custom-exercise-card" style="border-left:4px solid ' + colorsForCategory.accent + '">' +
      '<h3>' + escapeHtml(exercise.name) + '</h3>' +
      '<p>' + escapeHtml(exercise.explanation) + '</p>' +
      '<div class="exercise-meta"><span style="color:' + colorsForCategory.color + ';background:' + colorsForCategory.background + '">' +
      escapeHtml(categoryName) + '</span><span>' + exercise.minutes + ' min</span></div>' +
      (exercise.videoUrl ? '<div class="exercise-media"><strong>Uitlegvideo</strong>' + mediaMarkup(exercise.videoUrl, 'Uitlegvideo: ' + exercise.name) + '</div>' : '') +
      (exercise.animationUrl ? '<div class="exercise-media"><strong>Animatie</strong>' + mediaMarkup(exercise.animationUrl, 'Animatie: ' + exercise.name) + '</div>' : '') +
      '<a href="' + builderHref + '">Open trainingbouwer</a></article>';
  }

  function initializeCategoryLists() {
    var mounts = document.querySelectorAll('[data-catalog-category]');
    if (!mounts.length) return;
    function render() {
      mounts.forEach(function (mount) {
        var list = mount.querySelector('[data-exercise-list]');
        var status = mount.querySelector('[data-exercise-status]');
        try {
          var catalog = load();
          var categoryId = mount.getAttribute('data-catalog-category');
          var categories = {
            plezier: 'Plezier & spel',
            houding: 'Houding',
            techniek: 'Techniek',
            snelheid: 'Snelheid'
          };
          var exercises = catalog.exercises.filter(function (exercise) { return exercise.categoryId === categoryId; });
          list.innerHTML = exercises.map(function (exercise) {
            return renderExerciseCard(exercise, categories[categoryId]);
          }).join('');
          mount.hidden = exercises.length === 0;
          status.textContent = '';
        } catch (error) {
          mount.hidden = false;
          status.textContent = error.message || 'Eigen oefeningen konden niet worden geladen.';
        }
      });
    }
    render();
    global.addEventListener('storage', function (event) {
      if (event.key === STORAGE_KEY) render();
    });
  }

  var serverReady = global.SchaatskompasSync ? global.SchaatskompasSync.ready : Promise.resolve();
  serverReady.then(function () {
    if (global.document.readyState === 'loading') {
      global.document.addEventListener('DOMContentLoaded', initializeCategoryLists);
    } else {
      initializeCategoryLists();
    }
  });

  global.SchaatskompasExerciseCatalog = {
    storageKey: STORAGE_KEY,
    categoryPrefix: CATEGORY_PREFIX,
    exercisePrefix: EXERCISE_PREFIX,
    createId: createId,
    colors: colors,
    escapeHtml: escapeHtml,
    load: load,
    mediaMarkup: mediaMarkup,
    renderExerciseCard: renderExerciseCard,
    save: save,
    validateMediaUrl: validateMediaUrl
  };
}(window));
