/* ==========================================================================
   StockMind — Dashboard (UI shell)
   Requires: jQuery 3, Chart.js 4.
   Everything is demo data from window.STOCKMIND_DASHBOARD (emitted by PHP).
   Replace the functions in `DataSource` and `Assistant.ask` to go live.
   ========================================================================== */
(function (window, $) {
  'use strict';

  var CFG = window.STOCKMIND_DASHBOARD || { endpoints: {}, health: { items: [] }, trend: {} };
  var CSRF = $('meta[name="csrf-token"]').attr('content') || '';

  /* ------------------------------------------------------------------ *
   * Shared helpers
   * ------------------------------------------------------------------ */
  var Util = {
    toast: function (message, icon) {
      var $t = $('<div class="toast" role="status"></div>')
        .append($('<i class="bi"></i>').addClass(icon || 'bi-info-circle-fill'))
        .append($('<span></span>').text(message));
      $('#toast-stack').append($t);
      setTimeout(function () {
        $t.fadeOut(180, function () { $t.remove(); });
      }, 3200);
    },
    isDesktop: function () {
      return window.matchMedia('(min-width: 1200px)').matches;
    }
  };

  /* ------------------------------------------------------------------ *
   * HTTP — reuses the existing CSRF token. Adjust the header name if your
   * backend expects a different one.
   * ------------------------------------------------------------------ */
  var Http = {
    request: function (url, options) {
      options = options || {};
      return $.ajax({
        url: url,
        method: options.method || 'GET',
        data: options.data,
        dataType: 'json',
        headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' }
      });
    }
  };

  /* ------------------------------------------------------------------ *
   * Data source — swap each method for an API call later, e.g.
   *   return Http.request(CFG.endpoints.health);
   * Each method must return a jQuery/native Promise.
   * ------------------------------------------------------------------ */
  var DataSource = {
    getHealth: function () {
      return $.Deferred().resolve(CFG.health).promise();
    },
    getTrend: function (months) {
      var data = CFG.trend[String(months)] || CFG.trend['6'];
      return $.Deferred().resolve(data).promise();
    }
  };

  /* ------------------------------------------------------------------ *
   * Charts
   * ------------------------------------------------------------------ */
  var Charts = {
    health: null,
    trend: null,

    init: function () {
      if (!window.Chart) { return; }
      Chart.defaults.font.family = 'Inter, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';
      Chart.defaults.font.size = 12;
      Chart.defaults.color = '#697488';

      DataSource.getHealth().then(Charts.renderHealth);
      Charts.loadTrend($('#trend-range').val() || '6');
    },

    renderHealth: function (data) {
      var canvas = document.getElementById('health-chart');
      if (!canvas) { return; }
      var items = data.items || [];
      var config = {
        type: 'doughnut',
        data: {
          labels: items.map(function (i) { return i.label; }),
          datasets: [{
            data: items.map(function (i) { return i.pct; }),
            backgroundColor: items.map(function (i) { return i.color; }),
            borderColor: '#ffffff',
            borderWidth: 3,
            hoverOffset: 4
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          cutout: '72%',
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  var item = items[ctx.dataIndex];
                  return ' ' + ctx.label + ': ' + item.value + ' (' + item.pct + '%)';
                }
              }
            }
          }
        }
      };
      if (Charts.health) { Charts.health.destroy(); }
      Charts.health = new Chart(canvas, config);
    },

    loadTrend: function (months) {
      DataSource.getTrend(months).then(Charts.renderTrend);
    },

    renderTrend: function (data) {
      var canvas = document.getElementById('trend-chart');
      if (!canvas) { return; }

      if (Charts.trend) {
        Charts.trend.data.labels = data.labels;
        Charts.trend.data.datasets[0].data = data.values;
        Charts.trend.data.datasets[1].data = data.values;
        Charts.trend.update();
        return;
      }

      Charts.trend = new Chart(canvas, {
        data: {
          labels: data.labels,
          datasets: [
            {
              type: 'bar',
              label: 'Inventory value',
              data: data.values,
              backgroundColor: '#c3d4fc',
              hoverBackgroundColor: '#a9c0fa',
              borderRadius: 5,
              maxBarThickness: 38,
              order: 2
            },
            {
              type: 'line',
              label: 'Trend',
              data: data.values,
              borderColor: '#2f62f5',
              backgroundColor: '#2f62f5',
              borderWidth: 2,
              tension: 0.35,
              pointRadius: 3.5,
              pointHoverRadius: 5,
              order: 1
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: false },
            tooltip: {
              filter: function (item) { return item.datasetIndex === 0; },
              callbacks: {
                label: function (ctx) { return ' ₹' + ctx.parsed.y.toFixed(2) + ' Cr'; }
              }
            }
          },
          scales: {
            x: { grid: { display: false }, border: { display: false } },
            y: {
              min: 0, max: 4, border: { display: false },
              grid: { color: '#edf0f6' },
              ticks: {
                stepSize: 1,
                callback: function (v) { return v === 0 ? '0' : '₹' + v + 'Cr'; }
              }
            }
          }
        }
      });
    }
  };

  /* ------------------------------------------------------------------ *
   * Layout: sidebar, nav groups, dropdowns, tabs, shortcuts
   * ------------------------------------------------------------------ */
  var Layout = {
    init: function () {
      Layout.bindSidebar();
      Layout.bindNavGroups();
      Layout.bindDropdowns();
      Layout.bindTabs();
      Layout.bindSearch();
      Layout.bindRange();
    },

    bindSidebar: function () {
      var $body = $('body');
      $('[data-sidebar-open]').off('click.stockmindSidebar').on('click.stockmindSidebar', function () { $body.addClass('sidebar-open'); });
      $('[data-sidebar-close]').off('click.stockmindSidebar').on('click.stockmindSidebar', function () { $body.removeClass('sidebar-open'); });
      $('#sidebar').off('click.stockmindSidebar', 'a[href]').on('click.stockmindSidebar', 'a[href]', function () {
        if (!Util.isDesktop()) { $body.removeClass('sidebar-open'); }
      });
      $(window).off('resize.stockmindSidebar').on('resize.stockmindSidebar', function () {
        if (Util.isDesktop()) { $body.removeClass('sidebar-open'); }
      });
    },

    bindNavGroups: function () {
      $('[data-nav-toggle]').off('click.stockmindNav').on('click.stockmindNav', function () {
        var $group = $(this).closest('.nav-group');
        var open = !$group.hasClass('is-open');
        $group.toggleClass('is-open', open);
        $(this).attr('aria-expanded', open ? 'true' : 'false');
      });
      // Placeholder routes: no pages exist yet.
      $('.nav-item--sub').off('click.stockmindNav').on('click.stockmindNav', function (e) {
        if ($(this).is('[data-module-link]')) { return; }
        e.preventDefault();
        Util.toast($(this).text().trim() + ' isn’t available yet.');
      });
    },

    bindDropdowns: function () {
      function closeAll(except) {
        $('.dd.is-open').not(except).each(function () {
          $(this).removeClass('is-open').find('[data-dropdown]').attr('aria-expanded', 'false');
        });
      }
      $('[data-dropdown]').off('click.stockmindDropdown').on('click.stockmindDropdown', function (e) {
        e.stopPropagation();
        var $dd = $(this).closest('.dd');
        var open = !$dd.hasClass('is-open');
        closeAll($dd);
        $dd.toggleClass('is-open', open);
        $(this).attr('aria-expanded', open ? 'true' : 'false');
      });
      $(document).off('click.stockmindDropdown').on('click.stockmindDropdown', function (e) {
        if (!$(e.target).closest('.dd').length) { closeAll(); }
      });
      $(document).off('keydown.stockmindDropdown').on('keydown.stockmindDropdown', function (e) {
        if (e.key === 'Escape') {
          closeAll();
          $('body').removeClass('sidebar-open');
        }
      });
      $('.ds-menu a[href="#"]').off('click.stockmindDropdown').on('click.stockmindDropdown', function (e) { e.preventDefault(); });
    },

    bindTabs: function () {
      $('.tabs').off('click.stockmindTabs').on('click.stockmindTabs', '.tabs__tab', function () {
        var $tab = $(this);
        $tab.siblings().removeClass('is-active').attr('aria-selected', 'false');
        $tab.addClass('is-active').attr('aria-selected', 'true');
        // Hook for future tab content: $(document).on('dashboard:tabchange', ...)
        $(document).trigger('dashboard:tabchange', [$tab.data('tab')]);
      });
    },

    bindSearch: function () {
      var $input = $('#global-search-input');
      $(document).off('keydown.stockmindSearch').on('keydown.stockmindSearch', function (e) {
        if ((e.metaKey || e.ctrlKey) && String(e.key).toLowerCase() === 'k') {
          e.preventDefault();
          $input.trigger('focus').trigger('select');
        }
      });
      $('#global-search').off('submit.stockmindSearch').on('submit.stockmindSearch', function (e) {
        e.preventDefault();
        var q = $.trim($input.val());
        if (!q) { return; }
        // Hook: route to search page or hand the query to the assistant.
        $(document).trigger('dashboard:search', [q]);
        Util.toast('Search isn’t connected yet.');
      });
    },

    bindRange: function () {
      $('#range-select').off('change.stockmindRange').on('change.stockmindRange', function () {
        // Hook: reload KPI/charts for the chosen range.
        $(document).trigger('dashboard:rangechange', [$(this).val()]);
      });
      $('#trend-range').off('change.stockmindRange').on('change.stockmindRange', function () {
        Charts.loadTrend($(this).val());
      });
    }
  };

  /* ------------------------------------------------------------------ *
   * AI assistant — UI only. Plug the API call into Assistant.ask().
   * Must return a Promise resolving to { answer: "text" }.
   * Example once the endpoint exists:
   *   return Http.request(CFG.endpoints.assistant, { method: 'POST', data: { question: q } });
   * ------------------------------------------------------------------ */
  var Assistant = {
    busy: false,

    ask: function (question) {
      var d = $.Deferred();
      setTimeout(function () {
        d.resolve({
          answer: 'The assistant isn’t connected yet. Once it is, answers will come from your own inventory data.'
        });
      }, 500);
      return d.promise();
    },

    append: function (cls, text) {
      var $log = $('#assistant-log');
      var $m = $('<div class="msg"></div>').addClass(cls).text(text);
      $log.append($m);
      $log.scrollTop($log[0].scrollHeight);
      return $m;
    },

    send: function (question) {
      question = $.trim(question);
      if (!question || Assistant.busy) { return; }
      Assistant.busy = true;
      $('.ask__send').prop('disabled', true);

      Assistant.append('msg--user', question);
      $('#assistant-input').val('');
      var $typing = Assistant.append('msg--bot msg--typing', 'Thinking…');

      Assistant.ask(question)
        .done(function (res) {
          $typing.removeClass('msg--typing').text((res && res.answer) || 'No answer was returned.');
        })
        .fail(function () {
          $typing.removeClass('msg--typing').text('Couldn’t reach the assistant. Try again in a moment.');
        })
        .always(function () {
          Assistant.busy = false;
          $('.ask__send').prop('disabled', false);
          var $log = $('#assistant-log');
          $log.scrollTop($log[0].scrollHeight);
        });
    },

    init: function () {
      $('#assistant-form').off('submit.stockmindAssistant').on('submit.stockmindAssistant', function (e) {
        e.preventDefault();
        Assistant.send($('#assistant-input').val());
      });
      $('#assistant-suggest').off('click.stockmindAssistant', '[data-question]').on('click.stockmindAssistant', '[data-question]', function () {
        Assistant.send($(this).data('question'));
      });
    }
  };

  /* ------------------------------------------------------------------ *
   * Quick actions + decision review (placeholders)
   * ------------------------------------------------------------------ */
  var Actions = {
    // Map an action key to a URL when the screen exists, e.g. 'add-product': 'products/new.php'
    routes: {},

    init: function () {
      $('.quick').off('click.stockmindActions', '[data-action]').on('click.stockmindActions', '[data-action]', function () {
        var key = $(this).data('action');
        var title = $(this).find('.quick__title').text();
        if (Actions.routes[key]) {
          window.location.href = Actions.routes[key];
        } else {
          Util.toast(title + ' isn’t available yet.');
        }
      });

      $('.inbox').off('click.stockmindActions', '[data-review]').on('click.stockmindActions', '[data-review]', function () {
        var item = $(this).data('review');
        $(document).trigger('dashboard:review', [item]);
        Util.toast('Review for ' + item + ' isn’t available yet.');
      });
    }
  };

  /* ------------------------------------------------------------------ *
   * Logout — uses the EXISTING endpoint from PHP (CFG.endpoints.logout).
   * ------------------------------------------------------------------ */
  var Auth = {
    init: function () {
      $('#logout-btn').off('click.stockmindAuth').on('click.stockmindAuth', function () {
        var $btn = $(this).prop('disabled', true);
        $.ajax({
          url: CFG.endpoints.logout,
          method: 'DELETE',
          dataType: 'json',
          headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' }
        })
          .done(function () {
            window.location.href = CFG.endpoints.login || '/';
          })
          .fail(function (xhr, status) {
            // A 2xx with an empty/non-JSON body still means the session ended.
            if (status === 'parsererror' && xhr.status >= 200 && xhr.status < 300) {
              window.location.href = CFG.endpoints.login || '/';
              return;
            }
            $btn.prop('disabled', false);
            Util.toast('Couldn’t log out. Please try again.', 'bi-exclamation-circle-fill');
          });
      });
    }
  };

  /* ------------------------------------------------------------------ *
   * Boot
   * ------------------------------------------------------------------ */
  function mountDashboardModule() {
    Layout.bindTabs();
    Layout.bindRange();
    Assistant.init();
    Actions.init();
    Charts.init();
  }

  $(function () {
    Layout.init();
    Auth.init();
    mountDashboardModule();
  });

  // Public surface for future modules.
  window.StockMindDashboard = {
    DataSource: DataSource,
    Charts: Charts,
    Assistant: Assistant,
    Actions: Actions,
    Http: Http,
    mountModule: mountDashboardModule,
    toast: Util.toast
  };
})(window, jQuery);
