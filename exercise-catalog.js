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
      url = new URL(trimmed, global.location.href);
    } catch (error) {
      throw new Error('Gebruik een volledige https-link voor video of animatie.');
    }
    if (global.SchaatskompasSync && global.SchaatskompasSync.isMediaUrl(url.href)) return url.href;
    if (url.protocol !== 'https:' || url.username || url.password) {
      throw new Error('Video- en animatielinks moeten een veilige https-link zijn.');
    }
    return url.href;
  }

  function validateMediaId(value) {
    var id = String(value || '');
    if (!id) return '';
    if (!/^[a-f0-9]{32}$/i.test(id)) throw new Error('Een geüpload mediabestand heeft een ongeldig ID.');
    return id.toLowerCase();
  }

  function validateUploadedMediaType(value, purpose) {
    var mimeType = String(value || '');
    if (!mimeType) return '';
    var videoTypes = ['video/mp4', 'video/webm'];
    var animationTypes = videoTypes.concat(['image/gif', 'image/webp', 'image/png', 'image/jpeg']);
    if ((purpose === 'video' && videoTypes.indexOf(mimeType) < 0) ||
        (purpose === 'animation' && animationTypes.indexOf(mimeType) < 0)) {
      throw new Error('Een geüpload bestand heeft een niet-ondersteund formaat.');
    }
    return mimeType;
  }

  function mediaUrl(mediaId) {
    if (!mediaId || !global.SchaatskompasSync) return '';
    return global.SchaatskompasSync.mediaUrl(mediaId);
  }

  function maxMediaBytesForFile(file) {
    var fileName = String(file && file.name || '').toLowerCase();
    return file && (file.type === 'image/gif' || /\.gif$/.test(fileName)) ? 104857600 : 524288000;
  }

  function createdAtFor(item, prefix) {
    var existing = Date.parse(String(item.createdAt || ''));
    if (Number.isFinite(existing)) return new Date(existing).toISOString();
    var id = String(item.id || '');
    if (id.indexOf(prefix) === 0) {
      var timestamp = parseInt(id.slice(prefix.length, -6), 36);
      if (Number.isFinite(timestamp) && timestamp > 0) return new Date(timestamp).toISOString();
    }
    return '';
  }

  function sortItems(items, order) {
    var direction = order === 'date-asc' || order === 'name-asc' ? 1 : -1;
    var byDate = order === 'date-asc' || order === 'date-desc';
    return items.map(function (item, index) {
      return { item: item, index: index };
    }).sort(function (left, right) {
      var comparison;
      if (byDate) {
        comparison = (Date.parse(left.item.createdAt || '') || 0) -
          (Date.parse(right.item.createdAt || '') || 0);
      } else {
        comparison = String(left.item.name || '').localeCompare(
          String(right.item.name || ''), 'nl', { sensitivity: 'base' }
        );
      }
      return comparison ? comparison * direction : left.index - right.index;
    }).map(function (entry) {
      return entry.item;
    });
  }

  function sortElements(container, order) {
    var entries = Array.prototype.slice.call(container.children).map(function (element) {
      var heading = element.querySelector('h2,h3');
      return {
        element: element,
        name: element.getAttribute('data-sort-name') || (heading ? heading.textContent : ''),
        createdAt: element.getAttribute('data-sort-date') || '2024-01-01T00:00:00.000Z'
      };
    });
    sortItems(entries, order).forEach(function (entry) {
      container.appendChild(entry.element);
    });
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
      category.createdAt = createdAtFor(category, CATEGORY_PREFIX);
    });
    catalog.exercises.forEach(function (exercise) {
      if (!exercise || typeof exercise.id !== 'string' || exercise.id.indexOf(EXERCISE_PREFIX) !== 0 ||
          typeof exercise.name !== 'string' || typeof exercise.categoryId !== 'string' ||
          (typeof exercise.explanation !== 'string' && exercise.explanation !== undefined) ||
          !Number.isInteger(exercise.minutes) ||
          exercise.minutes < 1 || exercise.minutes > 240) {
        throw new Error('Een opgeslagen oefening is ongeldig.');
      }
      if (typeof exercise.explanation !== 'string') exercise.explanation = '';
      exercise.videoUrl = validateMediaUrl(exercise.videoUrl);
      exercise.animationUrl = validateMediaUrl(exercise.animationUrl);
      exercise.videoMediaId = validateMediaId(exercise.videoMediaId);
      exercise.animationMediaId = validateMediaId(exercise.animationMediaId);
      exercise.videoMimeType = validateUploadedMediaType(exercise.videoMimeType, 'video');
      exercise.animationMimeType = validateUploadedMediaType(exercise.animationMimeType, 'animation');
      exercise.createdAt = createdAtFor(exercise, EXERCISE_PREFIX);
      if ((exercise.videoMediaId && !exercise.videoMimeType) ||
          (exercise.animationMediaId && !exercise.animationMimeType)) {
        throw new Error('Een geüpload mediabestand mist het bestandstype.');
      }
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

  function mediaMarkup(value, label, mimeType) {
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
    if (mimeType && /^video\//.test(mimeType)) {
      return '<video controls preload="metadata" playsinline aria-label="' + title + '" src="' + escapedUrl + '"></video>';
    }
    if (mimeType && /^image\//.test(mimeType)) {
      return '<img loading="lazy" alt="' + title + '" src="' + escapedUrl + '">';
    }
    if (/\.(mp4|webm|ogg)(?:$)/i.test(parsed.pathname)) {
      return '<video controls preload="metadata" playsinline aria-label="' + title + '" src="' + escapedUrl + '"></video>';
    }
    if (/\.(gif|webp|png|jpe?g)(?:$)/i.test(parsed.pathname)) {
      return '<img loading="lazy" alt="' + title + '" src="' + escapedUrl + '">';
    }
    return '<a href="' + escapedUrl + '" target="_blank" rel="noopener noreferrer">' + title + ' openen</a>';
  }

  function renderExerciseCard(exercise, categoryName) {
    var colorsForCategory = colors(exercise.tone);
    var videoUrl = mediaUrl(exercise.videoMediaId) || exercise.videoUrl;
    var animationUrl = mediaUrl(exercise.animationMediaId) || exercise.animationUrl;
    var explanation = String(exercise.explanation || '').trim();
    return '<article id="exercise-' + escapeHtml(exercise.id) + '" class="card custom-exercise-card" data-custom-exercise="true" data-sort-name="' +
      escapeHtml(exercise.name) + '" data-sort-date="' + escapeHtml(createdAtFor(exercise, EXERCISE_PREFIX)) +
      '" style="border-left:4px solid #F97316">' +
      '<h3>' + escapeHtml(exercise.name) + '</h3>' +
      '<p>' + (explanation ? escapeHtml(explanation) : 'Er is nog geen uitleg toegevoegd.') + '</p>' +
      '<div class="exercise-meta"><span style="color:' + colorsForCategory.color + ';background:' + colorsForCategory.background + '">' +
      escapeHtml(categoryName) + '</span><span>' + exercise.minutes + ' min</span></div>' +
      (videoUrl ? '<div class="exercise-media"><strong>Uitlegvideo</strong>' + mediaMarkup(videoUrl, 'Uitlegvideo: ' + exercise.name, exercise.videoMimeType) + '</div>' : '') +
      (animationUrl ? '<div class="exercise-media"><strong>Animatie</strong>' + mediaMarkup(animationUrl, 'Animatie: ' + exercise.name, exercise.animationMimeType) + '</div>' : '') +
      (!videoUrl && !animationUrl ? '<p class="exercise-unavailable" style="margin:0;color:#64748B;font-size:13px">Er is nog geen uitlegvideo of animatie toegevoegd.</p>' : '') +
      '</article>';
  }

  function initializeCategoryLists() {
    var mounts = document.querySelectorAll('[data-catalog-category]');
    if (!mounts.length) return;
    var sortControl = document.querySelector('[data-exercise-sort]');
    function render() {
      mounts.forEach(function (mount) {
        var list = mount.querySelector('[data-exercise-list]') || mount.querySelector('.grid') || mount;
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
          list.querySelectorAll('[data-custom-exercise]').forEach(function (card) { card.remove(); });
          var exercises = catalog.exercises.filter(function (exercise) { return exercise.categoryId === categoryId; });
          list.insertAdjacentHTML('beforeend', exercises.map(function (exercise) {
            return renderExerciseCard(exercise, categories[categoryId] || '');
          }).join(''));
          sortElements(list, sortControl ? sortControl.value : 'date-desc');
          if (status) status.textContent = '';
        } catch (error) {
          if (status) status.textContent = error.message || 'Eigen oefeningen konden niet worden geladen.';
        }
      });
    }
    render();
    if (sortControl) sortControl.addEventListener('change', render);
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
    mediaUrl: mediaUrl,
    maxMediaBytes: 524288000,
    maxMediaBytesForFile: maxMediaBytesForFile,
    sortItems: sortItems,
    sortElements: sortElements,
    renderExerciseCard: renderExerciseCard,
    save: save,
    validateMediaUrl: validateMediaUrl
  };
}(window));
