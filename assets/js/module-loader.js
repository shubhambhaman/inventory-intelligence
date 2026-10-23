/* global window, document, jQuery */
(function (window, document, $) {
  'use strict';

  var cfg = window.STOCKMIND_DASHBOARD || {};
  var modules = cfg.modules || {};
  var loadedCss = {};
  var loadedJs = {};
  var cachedDashboardHtml = null;
  var activeModule = cfg.initialModule || 'dashboard';

  function hasModule(name) {
    return Object.prototype.hasOwnProperty.call(modules, name);
  }

  function contentRoot() {
    return document.querySelector('[data-module-content]');
  }

  function setActiveNav(name) {
    $('[data-module-link]').removeClass('is-active').removeAttr('aria-current');
    $('[data-module-link][data-module="' + name + '"]').addClass('is-active').attr('aria-current', 'page');
  }

  function setTitle(name) {
    var title = modules[name] && modules[name].title ? modules[name].title : 'Dashboard';
    document.title = title === 'Dashboard' ? 'Inventory Platform' : title + ' · Inventory Platform';
  }

  function loadStyles(name) {
    var list = modules[name] && modules[name].css ? modules[name].css : [];
    list.forEach(function (href) {
      if (loadedCss[href]) {
        return;
      }
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.setAttribute('data-module-style', name);
      document.head.appendChild(link);
      loadedCss[href] = true;
    });
  }

  function loadScript(src) {
    if (loadedJs[src]) {
      return $.Deferred().resolve().promise();
    }

    return $.ajax({
      url: src,
      dataType: 'script',
      cache: true
    }).then(function () {
      loadedJs[src] = true;
    });
  }

  function loadScripts(name) {
    var list = modules[name] && modules[name].js ? modules[name].js : [];
    var chain = $.Deferred().resolve().promise();

    list.forEach(function (src) {
      chain = chain.then(function () {
        return loadScript(src);
      });
    });

    return chain;
  }

function mount(name) {
  var root = contentRoot();
  var registry = window.StockMindModules || {};

  activeModule = name;

  if (root) {
    root.setAttribute('data-current-module', name);
  }

  setActiveNav(name);
  setTitle(name);

  if (name === 'dashboard' && window.StockMindDashboard) {
    if (typeof window.StockMindDashboard.mountModule === 'function') {
      window.StockMindDashboard.mountModule();
    } else if (window.StockMindDashboard.Charts) {
      window.StockMindDashboard.Charts.init();
    }
  }

  if (registry[name] && typeof registry[name].mount === 'function') {
    registry[name].mount(
      root ? root.querySelector('[data-module-view="' + name + '"]') : null
    );
  }

  // Reinitialize Units whenever its HTML is mounted.
  if (name === 'units' && typeof window.StockMindUnitsInit === 'function') {
    window.StockMindUnitsInit(
      root ? root.querySelector('#units-module') : null
    );
  }
}

  function renderError(message) {
    var root = contentRoot();
    if (!root) {
      return;
    }

    root.innerHTML = '<section class="module-error" role="alert"><h1>Unable to load module</h1><p>' +
      (window.StockMindApp ? window.StockMindApp.escapeHtml(message) : message) +
      '</p></section>';
  }

  function fetchModule(name) {
    return $.ajax({
      url: cfg.moduleEndpoint,
      method: 'GET',
      dataType: 'json',
      data: { module: name }
    });
  }

  function load(name, options) {
    options = options || {};

    if (!hasModule(name)) {
      renderError('The requested module is not available.');
      return $.Deferred().reject().promise();
    }

    var root = contentRoot();
    if (!root) {
      return $.Deferred().reject().promise();
    }

    if (name === activeModule && !options.force) {
      return $.Deferred().resolve().promise();
    }

    root.classList.add('is-loading');

    if (name === 'dashboard' && cachedDashboardHtml !== null) {
      root.innerHTML = cachedDashboardHtml;
      loadStyles(name);
      return loadScripts(name).then(function () {
        mount(name);
        root.classList.remove('is-loading');
      });
    }

    return fetchModule(name)
      .then(function (response) {
        root.innerHTML = response.html || '';
        if (name === 'dashboard') {
          cachedDashboardHtml = root.innerHTML;
        }
        loadStyles(name);
        return loadScripts(name);
      })
      .then(function () {
        mount(name);
      })
      .fail(function (xhr) {
        var message = 'Please try again.';
        if (xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) {
          message = xhr.responseJSON.error.message;
        }
        renderError(message);
        if (window.StockMindApp) {
          window.StockMindApp.toast(message, 'bi-exclamation-circle-fill');
        }
      })
      .always(function () {
        root.classList.remove('is-loading');
      });
  }

  function init() {
    var root = contentRoot();
    if (root) {
      cachedDashboardHtml = activeModule === 'dashboard' ? root.innerHTML : null;
    }

    $('link[data-module-style]').each(function () {
      var href = $(this).attr('href');
      if (href) {
        loadedCss[href] = true;
      }
    });

    setActiveNav(activeModule);
    loadStyles(activeModule);
    loadScripts(activeModule).then(function () {
      mount(activeModule);
    });

    $(document).on('click', '[data-module-link]', function (event) {
      var name = $(this).data('module');

      if (!hasModule(name)) {
        return;
      }

      event.preventDefault();
      load(name);

      if (window.history && window.history.pushState) {
        var url = name === 'dashboard' ? 'dashboard.php' : 'dashboard.php?module=' + encodeURIComponent(name);
        window.history.pushState({ module: name }, '', url);
      }
    });

    window.addEventListener('popstate', function (event) {
      var name = event.state && event.state.module ? event.state.module : 'dashboard';
      load(hasModule(name) ? name : 'dashboard');
    });
  }

  $(init);

  window.StockMindModuleLoader = {
    load: load,
    mount: mount
  };
})(window, document, jQuery);
