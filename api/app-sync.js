(function (global) {
  'use strict';

  var GROUPS_KEY = 'schaatskompas-groepen-v1';
  var CATALOG_KEY = 'schaatskompas-exercise-catalog-v1';
  var OWNER_KEY = 'schaatskompas-server-owner-v1';
  var AUTH_EVENT_KEY = 'schaatskompas-server-auth-event-v1';
  var REVISION_PREFIX = 'schaatskompas-server-revision-v1-';
  var LOCAL_UPDATED_PREFIX = 'schaatskompas-server-local-updated-v1-';
  var BACKUP_PREFIX = 'schaatskompas-local-backup-v1-';
  var APP_KEYS = [GROUPS_KEY, CATALOG_KEY];
  var user = null;
  var csrf = '';
  var serverAvailable = false;
  var applyingServerData = false;
  var saveTimer = null;
  var syncQueue = Promise.resolve();
  var revision = null;
  var statusElement = null;
  var originalSetItem = Storage.prototype.setItem;
  var originalRemoveItem = Storage.prototype.removeItem;
  var localStore = global.localStorage;
  var path = global.location.pathname;
  var exerciseIndex = path.toLowerCase().indexOf('/oefeningen/');
  var siteRoot = exerciseIndex >= 0 ? path.slice(0, exerciseIndex + 1) : path.slice(0, path.lastIndexOf('/') + 1);
  var apiBase = siteRoot + 'api/';

  function setStatus(message, isError) {
    if (!global.document || !global.document.body) return;
    if (!statusElement) {
      statusElement = global.document.createElement('div');
      statusElement.setAttribute('role', 'status');
      statusElement.setAttribute('aria-live', 'polite');
      statusElement.style.cssText = 'position:fixed;z-index:10000;right:16px;bottom:16px;max-width:min(420px,calc(100vw - 32px));padding:11px 14px;border:1px solid #334155;border-radius:10px;background:#0F1E2E;color:#fff;font:500 13px/1.45 system-ui,sans-serif;box-shadow:0 8px 24px #0003';
      global.document.body.appendChild(statusElement);
    }
    statusElement.textContent = message;
    statusElement.style.borderColor = isError ? '#F97316' : '#334155';
    statusElement.hidden = !message;
  }

  function apiRequest(endpoint, options) {
    options = options || {};
    var controller = typeof global.AbortController === 'function' ? new global.AbortController() : null;
    var timeout = controller ? global.setTimeout(function () { controller.abort(); }, 10000) : null;
    var headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
    var requestOptions = Object.assign({}, options, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: headers
    });
    if (controller) requestOptions.signal = controller.signal;
    return global.fetch(apiBase + endpoint, requestOptions).then(function (response) {
      return response.text().then(function (text) {
        var result;
        try {
          result = JSON.parse(text);
        } catch (error) {
          throw new Error('De server gaf geen geldige JSON terug. Controleer de Apache/PHP API.');
        }
        if (!response.ok) {
          var requestError = new Error(result.error || 'De server heeft het verzoek geweigerd.');
          requestError.status = response.status;
          requestError.revision = result.revision;
          throw requestError;
        }
        return result;
      });
    }).then(function (result) {
      if (timeout !== null) global.clearTimeout(timeout);
      return result;
    }, function (error) {
      if (timeout !== null) global.clearTimeout(timeout);
      throw error;
    });
  }

  function rawSnapshot() {
    var entries = {};
    APP_KEYS.forEach(function (key) {
      var value = localStore.getItem(key);
      if (value !== null) entries[key] = value;
    });
    return { version: 1, entries: entries };
  }

  function hasLocalData() {
    return APP_KEYS.some(function (key) {
      var value = localStore.getItem(key);
      return value !== null && value !== '';
    });
  }

  function validateSnapshot(snapshot) {
    if (!snapshot || snapshot.version !== 1 || !snapshot.entries ||
        typeof snapshot.entries !== 'object') {
      throw new Error('De accountgegevens hebben een onbekend formaat.');
    }
    if (Array.isArray(snapshot.entries) && snapshot.entries.length === 0) snapshot.entries = {};
    if (Array.isArray(snapshot.entries)) throw new Error('De accountgegevens zijn ongeldig.');
    APP_KEYS.forEach(function (key) {
      if (Object.prototype.hasOwnProperty.call(snapshot.entries, key)) {
        if (typeof snapshot.entries[key] !== 'string') throw new Error('Een opgeslagen vermelding is ongeldig.');
        JSON.parse(snapshot.entries[key]);
      }
    });
    return snapshot;
  }

  function snapshotsEqual(left, right) {
    return APP_KEYS.every(function (key) {
      return left.entries[key] === right.entries[key];
    });
  }

  function revisionKey(userId) {
    return REVISION_PREFIX + userId;
  }

  function localUpdatedKey(userId) {
    return LOCAL_UPDATED_PREFIX + userId;
  }

  function saveLocalBackup(ownerId) {
    if (!hasLocalData()) return '';
    var timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    var key = BACKUP_PREFIX + ownerId + '-' + timestamp;
    originalSetItem.call(localStore, key, JSON.stringify(rawSnapshot()));
    return key;
  }

  function clearAppData() {
    applyingServerData = true;
    try {
      APP_KEYS.forEach(function (key) { originalRemoveItem.call(localStore, key); });
    } finally {
      applyingServerData = false;
    }
  }

  function applySnapshot(snapshot) {
    validateSnapshot(snapshot);
    applyingServerData = true;
    try {
      APP_KEYS.forEach(function (key) {
        if (Object.prototype.hasOwnProperty.call(snapshot.entries, key)) {
          originalSetItem.call(localStore, key, snapshot.entries[key]);
        } else {
          originalRemoveItem.call(localStore, key);
        }
      });
    } finally {
      applyingServerData = false;
    }
  }

  function saveSnapshot(expectedRevision) {
    if (!user) return Promise.reject(new Error('Log in om gegevens te synchroniseren.'));
    var ownerId = String(user.id);
    var localMarker = localStore.getItem(localUpdatedKey(ownerId));
    var payload = { data: rawSnapshot(), revision: expectedRevision };
    return apiRequest('data.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify(payload)
    }).then(function (result) {
      revision = result.revision;
      originalSetItem.call(localStore, OWNER_KEY, ownerId);
      originalSetItem.call(localStore, revisionKey(ownerId), String(revision));
      if (localStore.getItem(localUpdatedKey(ownerId)) === localMarker) {
        originalRemoveItem.call(localStore, localUpdatedKey(ownerId));
      } else {
        queueSnapshotSave();
      }
      setStatus('Je gegevens zijn opgeslagen op je eigen server en blijven ook lokaal beschikbaar.', false);
    });
  }

  function syncAccount(options) {
    options = options || {};
    if (!user) return Promise.reject(new Error('Log in om gegevens te synchroniseren.'));
    var expectedUserId = String(user.id);
    return loadSession().then(function (session) {
      if (!session.user || String(session.user.id) !== expectedUserId) {
        revision = null;
        throw new Error('Het actieve account is gewijzigd. Vernieuw de pagina voordat je verdergaat.');
      }
      return apiRequest('data.php');
    }).then(function (remote) {
      var ownerId = String(user.id);
      var local = rawSnapshot();
      var localHasData = hasLocalData();
      var localOwner = localStore.getItem(OWNER_KEY);
      var localMarker = localStore.getItem(localUpdatedKey(ownerId));

      if (remote.data !== null) {
        var serverSnapshot = validateSnapshot(remote.data);
        var knownRevision = Number(localStore.getItem(revisionKey(ownerId)) || 0);
        revision = Number(remote.revision);

        if (localOwner === ownerId && localHasData && localMarker) {
          if (knownRevision === revision) return saveSnapshot(revision);
          if (options.askBeforeImport) {
            var overwriteServer = global.confirm(
              'De servergegevens zijn op een ander apparaat gewijzigd sinds deze browser ze heeft geladen. ' +
              'Kies OK om de gegevens uit deze browser als nieuwe versie te bewaren; kies Annuleren om de serverversie te laden en je lokale versie als back-up te bewaren.'
            );
            if (overwriteServer) return saveSnapshot(revision);
            saveLocalBackup(ownerId);
          } else {
            setStatus('De servergegevens zijn op een ander apparaat gewijzigd. Je lokale wijzigingen zijn behouden; open Account om versies te kiezen.', true);
            return;
          }
        } else if (localHasData && !snapshotsEqual(local, serverSnapshot)) {
          saveLocalBackup(localOwner || ownerId);
        }

        applySnapshot(serverSnapshot);
        originalSetItem.call(localStore, OWNER_KEY, ownerId);
        originalSetItem.call(localStore, revisionKey(ownerId), String(revision));
        originalRemoveItem.call(localStore, localUpdatedKey(ownerId));
        setStatus('De gegevens van je account zijn geladen en klaar voor gebruik.', false);
        return;
      }

      if (localOwner && localOwner !== ownerId) {
        saveLocalBackup(localOwner);
        clearAppData();
        localHasData = false;
      }

      if (localOwner === ownerId && localHasData) return saveSnapshot(null);

      if (localHasData && options.askBeforeImport) {
        var importLocal = global.confirm(
          'Dit account heeft nog geen opgeslagen gegevens. Wil je de gegevens uit deze browser naar dit account uploaden? ' +
          'Kies Annuleren om de browsergegevens als lokale back-up te bewaren zonder ze te uploaden.'
        );
        if (importLocal) return saveSnapshot(null);
        saveLocalBackup(ownerId);
        clearAppData();
      } else if (localHasData) {
        setStatus('Er is nog geen serverkopie. Open Account om te kiezen of je deze browsergegevens wilt importeren.', false);
        return;
      }

      revision = null;
      originalSetItem.call(localStore, OWNER_KEY, ownerId);
      originalSetItem.call(localStore, revisionKey(ownerId), '0');
      setStatus('Je account is gekoppeld. Nieuwe wijzigingen worden automatisch opgeslagen.', false);
    });
  }

  function syncNow(options) {
    var task = syncQueue.then(function () { return syncAccount(options); });
    syncQueue = task.catch(function () {});
    return task.catch(function (error) {
      setStatus(error.message || 'Synchroniseren is niet gelukt. Je lokale gegevens blijven behouden.', true);
      throw error;
    });
  }

  function queueSnapshotSave() {
    if (!serverAvailable || !user || applyingServerData) return;
    try {
      originalSetItem.call(localStore, localUpdatedKey(String(user.id)), String(Date.now()));
    } catch (error) {
      setStatus('Lokale gegevens zijn opgeslagen, maar er is onvoldoende browseropslag om ze voor synchronisatie te markeren.', true);
      return;
    }
    global.clearTimeout(saveTimer);
    saveTimer = global.setTimeout(function () {
      syncNow({ askBeforeImport: false }).catch(function () {});
    }, 500);
  }

  Storage.prototype.setItem = function (key, value) {
    originalSetItem.call(this, key, value);
    if (this === localStore && APP_KEYS.indexOf(String(key)) >= 0) queueSnapshotSave();
  };

  Storage.prototype.removeItem = function (key) {
    originalRemoveItem.call(this, key);
    if (this === localStore && APP_KEYS.indexOf(String(key)) >= 0) queueSnapshotSave();
  };

  function broadcastAuthChange() {
    try {
      originalSetItem.call(localStore, AUTH_EVENT_KEY, Date.now() + '-' + Math.random().toString(36).slice(2));
    } catch (error) {
      setStatus('De sessiewijziging kon niet aan andere tabbladen worden doorgegeven. Vernieuw die tabbladen handmatig.', true);
    }
  }

  global.addEventListener('storage', function (event) {
    if (event.storageArea !== localStore) return;
    if (event.key === AUTH_EVENT_KEY) {
      var previousUserId = user ? String(user.id) : '';
      loadSession().then(function (session) {
        var nextUserId = session.user ? String(session.user.id) : '';
        if (nextUserId === previousUserId) return;
        revision = null;
        if (session.user) {
          syncNow({ askBeforeImport: false }).catch(function () {});
        } else {
          setStatus('Je bent uitgelogd. De lokale browsergegevens zijn niet gewijzigd.', false);
        }
      }).catch(function (error) {
        setStatus('De accountstatus kon niet worden gecontroleerd. ' + error.message, true);
      });
      return;
    }
    if (APP_KEYS.indexOf(event.key) >= 0) queueSnapshotSave();
  });

  function loadSession() {
    return apiRequest('auth.php').then(function (result) {
      serverAvailable = true;
      csrf = result.csrf;
      user = result.user;
      return result;
    });
  }

  function initialize() {
    if (global.location.protocol !== 'http:' && global.location.protocol !== 'https:') {
      return Promise.resolve({
        configured: false,
        error: new Error('Inloggen en serversynchronisatie werken via Apache; open de website via je server, niet via een file://-adres.')
      });
    }
    return loadSession().then(function (session) {
      if (!user) return { configured: true, user: null };
      return syncNow({ askBeforeImport: false }).then(function () {
        return { configured: true, user: user };
      }, function (error) {
        return { configured: true, user: user, error: error };
      });
    }).catch(function (error) {
      setStatus('De accountserver is niet bereikbaar. De website blijft lokaal bruikbaar. ' + error.message, true);
      return { configured: false, error: error };
    });
  }

  function ensureServer() {
    if (serverAvailable && csrf) return Promise.resolve();
    return loadSession().then(function () {});
  }

  function authAction(action, values) {
    return ensureServer().then(function () {
      return apiRequest('auth.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf
        },
        body: JSON.stringify(Object.assign({ action: action }, values || {}))
      });
    }).then(function (result) {
      csrf = result.csrf || '';
      user = result.user || null;
      broadcastAuthChange();
      return result;
    });
  }

  function signIn(email, password) {
    return authAction('login', { email: email, password: password });
  }

  function signUp(email, password, name) {
    return authAction('register', { email: email, password: password, name: name });
  }

  function signOut() {
    return authAction('logout').then(function () {
      user = null;
      revision = null;
      setStatus('Je bent uitgelogd. De lokale browsergegevens zijn niet gewijzigd.', false);
    });
  }

  function hasLocalBackup() {
    if (!user) return false;
    var prefix = BACKUP_PREFIX + String(user.id) + '-';
    for (var index = 0; index < localStore.length; index += 1) {
      var key = localStore.key(index);
      if (key && key.indexOf(prefix) === 0) return true;
    }
    return false;
  }

  function exportLocalBackup() {
    if (!user) throw new Error('Log eerst in om je lokale back-up te downloaden.');
    var prefix = BACKUP_PREFIX + String(user.id) + '-';
    var backups = [];
    for (var index = 0; index < localStore.length; index += 1) {
      var key = localStore.key(index);
      if (key && key.indexOf(prefix) === 0) {
        backups.push({ createdAt: key.slice(prefix.length), data: localStore.getItem(key) });
      }
    }
    if (!backups.length) throw new Error('Er is geen lokale back-up om te downloaden.');
    var blob = new Blob([JSON.stringify(backups, null, 2)], { type: 'application/json' });
    var url = global.URL.createObjectURL(blob);
    var link = global.document.createElement('a');
    link.href = url;
    link.download = 'schaatskompas-lokale-back-up.json';
    global.document.body.appendChild(link);
    link.click();
    link.remove();
    global.setTimeout(function () { global.URL.revokeObjectURL(url); }, 1000);
  }

  var ready = initialize();
  global.SchaatskompasSync = {
    ready: ready,
    isConfigured: function () { return serverAvailable; },
    getUser: function () { return user; },
    hasLocalData: hasLocalData,
    hasLocalBackup: hasLocalBackup,
    signIn: signIn,
    signUp: signUp,
    signOut: signOut,
    syncNow: syncNow,
    exportLocalBackup: exportLocalBackup
  };
}(window));
