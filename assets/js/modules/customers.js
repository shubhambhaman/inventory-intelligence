/* global window, document, jQuery */
(function (window, document, $) {
  'use strict';

  window.StockMindModules = window.StockMindModules || {};

  function mount(root) {
    if (!root) {
      return;
    }

    var apiBase = root.getAttribute('data-customers-api') || '';
    var state = {
      page: 1,
      perPage: 20,
      search: '',
      status: '',
      items: [],
      total: 0,
      totalPages: 0,
      deactivateId: null,
      deactivateName: ''
    };

    var byId = function (id) {
      return root.querySelector('#' + id);
    };
    var esc = window.StockMindApp ? window.StockMindApp.escapeHtml : function (value) {
      return String(value == null ? '' : value);
    };

    function request(url, options) {
      if (window.StockMindAPI) {
        return window.StockMindAPI.request(url, options);
      }

      throw new Error('Shared API helper is not available.');
    }

    function showNotice(message, type) {
      var el = byId('notice');
      el.className = 'notice show ' + (type || 'error');
      el.textContent = message;
    }

    function hideNotice() {
      var el = byId('notice');
      el.className = 'notice';
      el.textContent = '';
    }

    function renderRows() {
      if (!state.items.length) {
        byId('rows').innerHTML = '<tr><td colspan="6"><div class="empty"><i class="bi bi-people"></i><strong>No customers found</strong>Try another search/filter or add a customer.</div></td></tr>';
        return;
      }

      byId('rows').innerHTML = state.items.map(function (customer) {
        var id = Number(customer.id);
        var active = customer.status === 'active';
        var code = customer.code ? 'Code: ' + esc(customer.code) : 'No code';
        var deactivate = active
          ? '<button class="icon-btn danger" data-action="deactivate" data-id="' + id + '" aria-label="Deactivate customer"><i class="bi bi-person-dash"></i></button>'
          : '';

        return '<tr>' +
          '<td><div class="name">' + esc(customer.name) + '</div><div class="small">' + code + ' · ID ' + id + '</div></td>' +
          '<td>' + esc(customer.contact_name || '-') + '</td>' +
          '<td><div>' + esc(customer.email || 'No email') + '</div><div class="small">' + esc(customer.phone || 'No phone') + '</div></td>' +
          '<td><span class="status ' + (active ? 'active' : 'inactive') + '">' + (active ? 'Active' : 'Inactive') + '</span></td>' +
          '<td>' + esc(customer.updated_at || '-') + '</td>' +
          '<td><div class="actions"><button class="icon-btn" data-action="edit" data-id="' + id + '" aria-label="Edit customer"><i class="bi bi-pencil"></i></button>' + deactivate + '</div></td>' +
          '</tr>';
      }).join('');
    }

    function renderPages() {
      var start = state.total ? (state.page - 1) * state.perPage + 1 : 0;
      var end = Math.min(state.page * state.perPage, state.total);
      var max = Math.max(1, state.totalPages);
      var nums = [1, max, state.page - 1, state.page, state.page + 1]
        .filter(function (value, index, arr) {
          return value >= 1 && value <= max && arr.indexOf(value) === index;
        })
        .sort(function (a, b) { return a - b; });

      byId('rangeLabel').textContent = 'Showing ' + start + '-' + end + ' of ' + state.total + ' customers';
      byId('pages').innerHTML =
        '<button data-page="' + (state.page - 1) + '" ' + (state.page <= 1 ? 'disabled' : '') + ' aria-label="Previous page">‹</button>' +
        nums.map(function (n) {
          return '<button data-page="' + n + '" class="' + (n === state.page ? 'current' : '') + '" ' + (n === state.page ? 'aria-current="page"' : '') + '>' + n + '</button>';
        }).join('') +
        '<button data-page="' + (state.page + 1) + '" ' + (state.page >= max ? 'disabled' : '') + ' aria-label="Next page">›</button>';
    }

    function loadCounts() {
      return Promise.all([
        request(apiBase + '/index.php?status=active&per_page=100'),
        request(apiBase + '/index.php?status=inactive&per_page=100')
      ]).then(function (results) {
        var active = Number(results[0].pagination && results[0].pagination.total || 0);
        var inactive = Number(results[1].pagination && results[1].pagination.total || 0);
        byId('activeCount').textContent = active.toLocaleString();
        byId('inactiveCount').textContent = inactive.toLocaleString();
        byId('totalCount').textContent = (active + inactive).toLocaleString();
      });
    }

    function load() {
      hideNotice();
      byId('rows').innerHTML = '<tr><td colspan="6"><div class="empty">Loading customers...</div></td></tr>';

      var params = new URLSearchParams({
        page: String(state.page),
        per_page: String(state.perPage)
      });

      if (state.search) {
        params.set('search', state.search);
      }
      if (state.status) {
        params.set('status', state.status);
      }

      return request(apiBase + '/index.php?' + params.toString())
        .then(function (data) {
          state.items = Array.isArray(data.items) ? data.items : [];
          state.total = Number(data.pagination && data.pagination.total || 0);
          state.totalPages = Number(data.pagination && data.pagination.total_pages || 0);
          renderRows();
          renderPages();
          return loadCounts();
        })
        .catch(function (error) {
          byId('rows').innerHTML = '<tr><td colspan="6"><div class="empty"><strong>Could not load customers</strong>' + esc(error.message) + '</div></td></tr>';
          byId('rangeLabel').textContent = 'Unable to load records';
          byId('pages').innerHTML = '';
          showNotice(error.message);
        });
    }

    function openForm(customer) {
      var form = byId('customerForm');
      form.reset();
      byId('formNotice').className = 'notice error';
      byId('formNotice').textContent = '';
      ['nameError', 'codeError', 'emailError'].forEach(function (id) {
        byId(id).textContent = '';
      });

      byId('customerId').value = customer ? customer.id : '';
      byId('formTitle').textContent = customer ? 'Edit customer' : 'Add customer';
      byId('name').value = customer ? customer.name || '' : '';
      byId('code').value = customer ? customer.code || '' : '';
      byId('contact').value = customer ? customer.contact_name || '' : '';
      byId('phone').value = customer ? customer.phone || '' : '';
      byId('email').value = customer ? customer.email || '' : '';
      byId('status').value = customer ? customer.status || 'active' : 'active';
      byId('formBackdrop').classList.add('open');
      window.setTimeout(function () { byId('name').focus(); }, 10);
    }

    function closeForm() {
      byId('formBackdrop').classList.remove('open');
    }

    function openDeactivate(customer) {
      state.deactivateId = Number(customer.id);
      state.deactivateName = customer.name;
      byId('deactivateName').textContent = customer.name;
      byId('deactivateNotice').className = 'notice error';
      byId('deactivateNotice').textContent = '';
      byId('deactivateBackdrop').classList.add('open');
    }

    function closeDeactivate() {
      byId('deactivateBackdrop').classList.remove('open');
    }

    byId('addBtn').addEventListener('click', function () { openForm(null); });
    byId('refreshBtn').addEventListener('click', load);

    var searchTimer = null;
    byId('searchInput').addEventListener('input', function (event) {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(function () {
        state.search = event.target.value.trim();
        state.page = 1;
        load();
      }, 300);
    });

    byId('statusFilter').addEventListener('change', function (event) {
      state.status = event.target.value;
      state.page = 1;
      load();
    });

    byId('perPage').addEventListener('change', function (event) {
      state.perPage = Number(event.target.value);
      state.page = 1;
      load();
    });

    byId('pages').addEventListener('click', function (event) {
      var button = event.target.closest('button[data-page]');
      if (!button || button.disabled) {
        return;
      }
      var page = Number(button.getAttribute('data-page'));
      if (page > 0 && page !== state.page) {
        state.page = page;
        load();
      }
    });

    byId('rows').addEventListener('click', function (event) {
      var button = event.target.closest('button[data-action]');
      if (!button) {
        return;
      }
      var customer = state.items.find(function (item) {
        return Number(item.id) === Number(button.getAttribute('data-id'));
      });
      if (!customer) {
        return;
      }
      if (button.getAttribute('data-action') === 'edit') {
        openForm(customer);
      } else {
        openDeactivate(customer);
      }
    });

    root.querySelectorAll('[data-close-form]').forEach(function (button) {
      button.addEventListener('click', closeForm);
    });
    root.querySelectorAll('[data-close-deactivate]').forEach(function (button) {
      button.addEventListener('click', closeDeactivate);
    });

    byId('formBackdrop').addEventListener('click', function (event) {
      if (event.target === byId('formBackdrop')) {
        closeForm();
      }
    });
    byId('deactivateBackdrop').addEventListener('click', function (event) {
      if (event.target === byId('deactivateBackdrop')) {
        closeDeactivate();
      }
    });

    $(document).off('keydown.stockmindCustomers').on('keydown.stockmindCustomers', function (event) {
      if (event.key === 'Escape') {
        closeForm();
        closeDeactivate();
      }
    });

    byId('customerForm').addEventListener('submit', function (event) {
      event.preventDefault();
      ['nameError', 'codeError', 'emailError'].forEach(function (id) {
        byId(id).textContent = '';
      });
      byId('formNotice').className = 'notice error';
      byId('formNotice').textContent = '';

      if (!byId('name').value.trim()) {
        byId('nameError').textContent = 'Customer name is required.';
        byId('name').focus();
        return;
      }

      if (byId('email').value.trim() && !byId('email').checkValidity()) {
        byId('emailError').textContent = 'Enter a valid email address.';
        byId('email').focus();
        return;
      }

      var id = byId('customerId').value;
      var payload = {
        name: byId('name').value.trim(),
        code: byId('code').value.trim(),
        contact_name: byId('contact').value.trim(),
        phone: byId('phone').value.trim(),
        email: byId('email').value.trim(),
        status: byId('status').value
      };
      var button = byId('saveBtn');
      button.disabled = true;
      button.textContent = 'Saving...';

      var action = id
        ? request(apiBase + '/update.php?id=' + encodeURIComponent(id), { method: 'PATCH', body: payload })
        : request(apiBase + '/index.php', { method: 'POST', body: payload });

      action
        .then(function () {
          showNotice(id ? 'Customer updated successfully.' : 'Customer created successfully.', 'success');
          if (!id) {
            state.page = 1;
          }
          closeForm();
          return load();
        })
        .catch(function (error) {
          var message = error.message.toLowerCase();
          if (message.indexOf('name') !== -1) {
            byId('nameError').textContent = error.message;
          } else if (message.indexOf('code') !== -1) {
            byId('codeError').textContent = error.message;
          } else if (message.indexOf('email') !== -1) {
            byId('emailError').textContent = error.message;
          } else {
            byId('formNotice').textContent = error.message;
            byId('formNotice').classList.add('show');
          }
        })
        .finally(function () {
          button.disabled = false;
          button.textContent = 'Save customer';
        });
    });

    byId('confirmDeactivate').addEventListener('click', function () {
      if (!state.deactivateId) {
        return;
      }

      var button = byId('confirmDeactivate');
      button.disabled = true;
      button.textContent = 'Deactivating...';

      request(apiBase + '/deactivate.php?id=' + encodeURIComponent(state.deactivateId), { method: 'POST' })
        .then(function () {
          closeDeactivate();
          showNotice(state.deactivateName + ' has been deactivated.', 'success');
          return load();
        })
        .catch(function (error) {
          byId('deactivateNotice').textContent = error.message;
          byId('deactivateNotice').classList.add('show');
        })
        .finally(function () {
          button.disabled = false;
          button.innerHTML = '<i class="bi bi-person-dash"></i> Deactivate';
        });
    });

    load();
  }

  window.StockMindModules.customers = {
    mount: mount
  };
})(window, document, jQuery);
