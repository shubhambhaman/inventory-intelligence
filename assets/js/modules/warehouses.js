/* global window, document, jQuery, URLSearchParams */
(function (window, document, $) {
  'use strict';

  window.StockMindModules = window.StockMindModules || {};

  function mount(root) {
    if (!root || root.getAttribute('data-warehouses-mounted') === '1') return;
    root.setAttribute('data-warehouses-mounted', '1');

    var apiBase = root.getAttribute('data-warehouses-api') || '';
    var accessApi = root.getAttribute('data-warehouse-access-api') || '';
    var state = {
      page: 1, perPage: 20, search: '', status: '',
      items: [], allWarehouses: [], total: 0, totalPages: 0
    };

    var byId = function (id) { return root.querySelector('#' + id); };
    var esc = window.StockMindApp && window.StockMindApp.escapeHtml
      ? window.StockMindApp.escapeHtml
      : function (value) {
          return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
          });
        };

    function request(url, options) {
      if (!window.StockMindAPI || typeof window.StockMindAPI.request !== 'function') {
        return Promise.reject(new Error('Shared API helper is not available.'));
      }
      return window.StockMindAPI.request(url, options || {});
    }

    // Accommodate both the shared helper's unwrapped payload and a raw API envelope.
    function dataOf(response) {
      var value = response;
      if (value && value.data && !Array.isArray(value.data)) value = value.data;
      if (value && value.data && value.data.items) value = value.data;
      return value || {};
    }

    function showNotice(id, message, type) {
      var el = byId(id);
      el.className = 'notice show ' + (type || 'error');
      el.textContent = message;
    }

    function hideNotice(id) {
      var el = byId(id);
      el.className = 'notice';
      el.textContent = '';
    }

    function renderRows() {
      var rows = byId('warehouseRows');
      if (!state.items.length) {
        rows.innerHTML = '<tr><td colspan="6"><div class="empty"><i class="bi bi-buildings"></i><strong>No warehouses found</strong>Try another search/filter or add a warehouse.</div></td></tr>';
        return;
      }

      rows.innerHTML = state.items.map(function (item) {
        var id = Number(item.id);
        var status = item.status === 'inactive' ? 'inactive' : 'active';
        var location = [item.city, item.state].filter(Boolean).map(esc).join(', ') || '—';
        var parentName = item.parent_location && item.parent_location.name
          ? item.parent_location.name : '—';
        return '<tr>' +
          '<td><div class="name">' + esc(item.name) + '</div><div class="small">Code: ' + esc(item.warehouse_code) + ' · ID ' + id + '</div></td>' +
          '<td><div>' + location + '</div><div class="small">' + esc(item.address || 'No address') + '</div></td>' +
          '<td>' + esc(parentName) + '</td>' +
          '<td><span class="status ' + status + '">' + (status === 'active' ? 'Active' : 'Inactive') + '</span></td>' +
          '<td>' + esc(item.updated_at || '—') + '</td>' +
          '<td><div class="actions">' +
            '<button class="icon-btn" data-action="edit" data-id="' + id + '" aria-label="Edit warehouse"><i class="bi bi-pencil"></i></button>' +
            '<button class="icon-btn ' + (status === 'active' ? 'danger' : 'success') + '" data-action="toggle-status" data-id="' + id + '" aria-label="' + (status === 'active' ? 'Set inactive' : 'Set active') + '"><i class="bi ' + (status === 'active' ? 'bi-slash-circle' : 'bi-check-circle') + '"></i></button>' +
          '</div></td>' +
        '</tr>';
      }).join('');
    }

    function renderPages() {
      var start = state.total ? ((state.page - 1) * state.perPage + 1) : 0;
      var end = Math.min(state.page * state.perPage, state.total);
      var max = Math.max(1, state.totalPages);
      var nums = [1, max, state.page - 1, state.page, state.page + 1]
        .filter(function (n, i, arr) { return n >= 1 && n <= max && arr.indexOf(n) === i; })
        .sort(function (a, b) { return a - b; });

      byId('warehouseRangeLabel').textContent =
        'Showing ' + start + '–' + end + ' of ' + state.total + ' warehouses';
      byId('warehousePages').innerHTML =
        '<button data-page="' + (state.page - 1) + '" ' + (state.page <= 1 ? 'disabled' : '') + ' aria-label="Previous page">‹</button>' +
        nums.map(function (n) {
          return '<button data-page="' + n + '" class="' + (n === state.page ? 'current' : '') + '" ' +
            (n === state.page ? 'aria-current="page"' : '') + '>' + n + '</button>';
        }).join('') +
        '<button data-page="' + (state.page + 1) + '" ' + (state.page >= max ? 'disabled' : '') + ' aria-label="Next page">›</button>';
    }

    function renderParentOptions(selectedId, editingId) {
      var select = byId('warehouseParent');
      var options = '<option value="">No parent (top-level warehouse)</option>';
      state.allWarehouses.forEach(function (item) {
        if (Number(item.id) === Number(editingId)) return;
        if (item.status !== 'active') return;
        options += '<option value="' + Number(item.id) + '">' +
          esc(item.warehouse_code) + ' — ' + esc(item.name) + '</option>';
      });
      select.innerHTML = options;
      select.value = selectedId ? String(selectedId) : '';
    }

    function renderAccessOptions() {
      var select = byId('accessWarehouseId');
      var options = '<option value="">Select warehouse</option>';
      state.allWarehouses.forEach(function (item) {
        options += '<option value="' + Number(item.id) + '">' +
          esc(item.warehouse_code) + ' — ' + esc(item.name) + ' (' + esc(item.status) + ')</option>';
      });
      select.innerHTML = options;
      byId('accessWarehouseHelp').textContent = state.allWarehouses.length >= 100
        ? 'Showing up to 100 warehouses from the API.'
        : 'Select from the loaded warehouse list.';
    }

    function loadAllWarehouses() {
      return request(apiBase + '/index.php?page=1&per_page=100')
        .then(function (response) {
          var data = dataOf(response);
          state.allWarehouses = Array.isArray(data.items) ? data.items : [];
          renderParentOptions('', '');
          renderAccessOptions();
        })
        .catch(function () {
          state.allWarehouses = state.items.slice();
          renderParentOptions('', '');
          renderAccessOptions();
        });
    }

    function loadCounts() {
      return Promise.all([
        request(apiBase + '/index.php?status=active&per_page=1'),
        request(apiBase + '/index.php?status=inactive&per_page=1'),
        request(apiBase + '/index.php?per_page=1')
      ]).then(function (results) {
        var active = Number(dataOf(results[0]).pagination && dataOf(results[0]).pagination.total || 0);
        var inactive = Number(dataOf(results[1]).pagination && dataOf(results[1]).pagination.total || 0);
        var total = Number(dataOf(results[2]).pagination && dataOf(results[2]).pagination.total || active + inactive);
        byId('activeCount').textContent = active.toLocaleString();
        byId('inactiveCount').textContent = inactive.toLocaleString();
        byId('totalCount').textContent = total.toLocaleString();
      });
    }

    function load() {
      hideNotice('warehouseNotice');
      byId('warehouseRows').innerHTML = '<tr><td colspan="6"><div class="empty">Loading warehouses…</div></td></tr>';
      var params = new URLSearchParams({
        page: String(state.page),
        per_page: String(state.perPage)
      });
      if (state.search) params.set('search', state.search);
      if (state.status) params.set('status', state.status);

      return request(apiBase + '/index.php?' + params.toString())
        .then(function (response) {
          var data = dataOf(response);
          state.items = Array.isArray(data.items) ? data.items : [];
          state.total = Number(data.pagination && data.pagination.total || 0);
          state.totalPages = Number(data.pagination && data.pagination.total_pages || 0);
          renderRows();
          renderPages();
          return Promise.all([loadCounts(), loadAllWarehouses()]);
        })
        .catch(function (error) {
          byId('warehouseRows').innerHTML = '<tr><td colspan="6"><div class="empty"><strong>Could not load warehouses</strong>' + esc(error.message) + '</div></td></tr>';
          byId('warehouseRangeLabel').textContent = 'Unable to load records';
          byId('warehousePages').innerHTML = '';
          showNotice('warehouseNotice', error.message);
        });
    }

    function openForm(item) {
      byId('warehouseForm').reset();
      hideNotice('warehouseFormNotice');
      ['warehouseCodeError', 'warehouseNameError', 'warehouseParentError'].forEach(function (id) {
        byId(id).textContent = '';
      });
      byId('warehouseId').value = item ? item.id : '';
      byId('warehouseFormTitle').textContent = item ? 'Edit warehouse' : 'Add warehouse';
      byId('warehouseCode').value = item ? item.warehouse_code || '' : '';
      byId('warehouseName').value = item ? item.name || '' : '';
      byId('warehouseAddress').value = item ? item.address || '' : '';
      byId('warehouseCity').value = item ? item.city || '' : '';
      byId('warehouseState').value = item ? item.state || '' : '';
      byId('warehouseStatus').value = item ? item.status || 'active' : 'active';
      renderParentOptions(item ? (item.parent_location ? item.parent_location.id : '') : '', item ? item.id : '');
      byId('warehouseFormBackdrop').classList.add('open');
      window.setTimeout(function () { byId('warehouseCode').focus(); }, 10);
    }

    function closeForm() {
      byId('warehouseFormBackdrop').classList.remove('open');
    }

    function submitWarehouse(event) {
      event.preventDefault();
      hideNotice('warehouseFormNotice');
      ['warehouseCodeError', 'warehouseNameError', 'warehouseParentError'].forEach(function (id) {
        byId(id).textContent = '';
      });

      if (!byId('warehouseCode').value.trim()) {
        byId('warehouseCodeError').textContent = 'Warehouse code is required.';
        byId('warehouseCode').focus();
        return;
      }
      if (!byId('warehouseName').value.trim()) {
        byId('warehouseNameError').textContent = 'Warehouse name is required.';
        byId('warehouseName').focus();
        return;
      }

      var id = byId('warehouseId').value;
      var parentId = byId('warehouseParent').value;
      var payload = {
        warehouse_code: byId('warehouseCode').value.trim(),
        name: byId('warehouseName').value.trim(),
        address: byId('warehouseAddress').value.trim(),
        city: byId('warehouseCity').value.trim(),
        state: byId('warehouseState').value.trim(),
        status: byId('warehouseStatus').value,
        parent_location_id: parentId ? Number(parentId) : null
      };
      var button = byId('saveWarehouseBtn');
      button.disabled = true;
      button.textContent = 'Saving…';

      var action = id
        ? request(apiBase + '/update.php?id=' + encodeURIComponent(id), { method: 'PATCH', body: payload })
        : request(apiBase + '/index.php', { method: 'POST', body: payload });

      action.then(function () {
        closeForm();
        showNotice('warehouseNotice', id ? 'Warehouse updated successfully.' : 'Warehouse created successfully.', 'success');
        if (!id) state.page = 1;
        return load();
      }).catch(function (error) {
        var message = error.message || 'Unable to save warehouse.';
        if (/code/i.test(message)) {
          byId('warehouseCodeError').textContent = message;
        } else if (/name/i.test(message)) {
          byId('warehouseNameError').textContent = message;
        } else if (/parent|location|cycle/i.test(message)) {
          byId('warehouseParentError').textContent = message;
        } else {
          showNotice('warehouseFormNotice', message);
        }
      }).finally(function () {
        button.disabled = false;
        button.textContent = 'Save warehouse';
      });
    }

    function toggleStatus(item) {
      var nextStatus = item.status === 'active' ? 'inactive' : 'active';
      var verb = nextStatus === 'inactive' ? 'deactivate' : 'reactivate';
      if (!window.confirm('Are you sure you want to ' + verb + ' "' + item.name + '"?')) return;

      request(apiBase + '/update.php?id=' + encodeURIComponent(item.id), {
        method: 'PATCH',
        body: { status: nextStatus }
      }).then(function () {
        showNotice('warehouseNotice', item.name + ' is now ' + nextStatus + '.', 'success');
        return load();
      }).catch(function (error) {
        showNotice('warehouseNotice', error.message || 'Unable to change warehouse status.');
      });
    }

    function submitAccess(action) {
      hideNotice('accessNotice');
      var userId = Number(byId('accessUserId').value);
      var warehouseId = Number(byId('accessWarehouseId').value);
      if (!Number.isInteger(userId) || userId <= 0) {
        showNotice('accessNotice', 'Enter a valid positive user ID.');
        byId('accessUserId').focus();
        return Promise.resolve();
      }
      if (!Number.isInteger(warehouseId) || warehouseId <= 0) {
        showNotice('accessNotice', 'Select a warehouse.');
        byId('accessWarehouseId').focus();
        return Promise.resolve();
      }

      var button = action === 'assign' ? byId('assignAccessBtn') : byId('removeAccessBtn');
      var original = button.textContent.trim();
      button.disabled = true;
      button.textContent = action === 'assign' ? 'Assigning…' : 'Removing…';

      return request(accessApi, {
        method: 'POST',
        body: { user_id: userId, warehouse_id: warehouseId, action: action }
      }).then(function (response) {
        var data = dataOf(response);
        showNotice('accessNotice', (data.message || (action === 'assign' ? 'Access assigned.' : 'Access removed.')), 'success');
      }).catch(function (error) {
        showNotice('accessNotice', error.message || 'Unable to change warehouse access.');
      }).finally(function () {
        button.disabled = false;
        button.textContent = original;
      });
    }

    byId('addWarehouseBtn').addEventListener('click', function () { openForm(null); });
    byId('refreshWarehousesBtn').addEventListener('click', load);
    byId('warehouseForm').addEventListener('submit', submitWarehouse);
    byId('warehouseAccessForm').addEventListener('submit', function (event) {
      event.preventDefault();
      submitAccess('assign');
    });
    byId('removeAccessBtn').addEventListener('click', function () { submitAccess('remove'); });

    var searchTimer = null;
    byId('warehouseSearch').addEventListener('input', function (event) {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(function () {
        state.search = event.target.value.trim();
        state.page = 1;
        load();
      }, 300);
    });
    byId('warehouseStatusFilter').addEventListener('change', function (event) {
      state.status = event.target.value;
      state.page = 1;
      load();
    });
    byId('warehousePerPage').addEventListener('change', function (event) {
      state.perPage = Number(event.target.value) || 20;
      state.page = 1;
      load();
    });
    byId('warehousePages').addEventListener('click', function (event) {
      var button = event.target.closest('button[data-page]');
      if (!button || button.disabled) return;
      var page = Number(button.getAttribute('data-page'));
      if (page > 0 && page !== state.page) {
        state.page = page;
        load();
      }
    });
    byId('warehouseRows').addEventListener('click', function (event) {
      var button = event.target.closest('button[data-action]');
      if (!button) return;
      var id = Number(button.getAttribute('data-id'));
      var item = state.items.find(function (row) { return Number(row.id) === id; });
      if (!item) return;
      if (button.getAttribute('data-action') === 'edit') openForm(item);
      else toggleStatus(item);
    });

    root.querySelectorAll('[data-close-warehouse-form]').forEach(function (button) {
      button.addEventListener('click', closeForm);
    });
    byId('warehouseFormBackdrop').addEventListener('click', function (event) {
      if (event.target === byId('warehouseFormBackdrop')) closeForm();
    });
    $(document).off('keydown.stockmindWarehouses').on('keydown.stockmindWarehouses', function (event) {
      if (event.key === 'Escape') closeForm();
    });

    load();
  }

  window.StockMindModules.warehouses = { mount: mount };
})(window, document, jQuery);
