/* global window, document, jQuery, URLSearchParams */
(function (window, document, $) {
  'use strict';

  window.StockMindModules = window.StockMindModules || {};

  function mount(root) {
    if (!root) return;

    var apiBase = root.getAttribute('data-suppliers-api') || '';
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

    function byId(id) { return root.querySelector('#' + id); }
    var esc = window.StockMindApp ? window.StockMindApp.escapeHtml : function (value) {
      return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
      });
    };

    function request(url, options) {
      if (!window.StockMindAPI) throw new Error('Shared API helper is not available.');
      return window.StockMindAPI.request(url, options);
    }

    function dataOf(response) { return response && response.data ? response.data : (response || {}); }

    function showNotice(message, type, targetId) {
      var el = byId(targetId || 'notice');
      el.className = 'notice show ' + (type || 'error');
      el.textContent = message;
    }

    function hideNotice(targetId) {
      var el = byId(targetId || 'notice');
      el.className = 'notice';
      el.textContent = '';
    }

    function displayDays(value, suffix) {
      if (value === null || value === undefined || value === '') return '-';
      var number = Number(value);
      if (!Number.isFinite(number)) return esc(value);
      return esc(String(number)) + ' ' + suffix;
    }

    function renderRows() {
      if (!state.items.length) {
        byId('rows').innerHTML = '<tr><td colspan="8"><div class="empty"><i class="bi bi-truck"></i><strong>No suppliers found</strong>Try another search/filter or add a supplier.</div></td></tr>';
        return;
      }

      byId('rows').innerHTML = state.items.map(function (supplier) {
        var id = Number(supplier.id);
        var active = supplier.status === 'active';
        var deactivate = active
          ? '<button class="icon-btn danger" data-action="deactivate" data-id="' + id + '" aria-label="Deactivate supplier" title="Deactivate supplier"><i class="bi bi-slash-circle"></i></button>'
          : '';
        var code = supplier.code ? 'Code: ' + esc(supplier.code) : 'No code';
        return '<tr>' +
          '<td><div class="name">' + esc(supplier.name) + '</div><div class="small">' + code + ' · ID ' + id + '</div></td>' +
          '<td>' + esc(supplier.contact_name || '-') + '</td>' +
          '<td><div>' + esc(supplier.email || 'No email') + '</div><div class="small">' + esc(supplier.phone || 'No phone') + '</div></td>' +
          '<td>' + displayDays(supplier.lead_time_days, 'days') + '</td>' +
          '<td>' + displayDays(supplier.payment_terms_days, 'days') + '</td>' +
          '<td><span class="status ' + (active ? 'active' : 'inactive') + '">' + (active ? 'Active' : 'Inactive') + '</span></td>' +
          '<td>' + esc(supplier.updated_at || '-') + '</td>' +
          '<td><div class="actions"><button class="icon-btn" data-action="edit" data-id="' + id + '" aria-label="Edit supplier" title="Edit supplier"><i class="bi bi-pencil"></i></button>' + deactivate + '</div></td>' +
          '</tr>';
      }).join('');
    }

    function renderPages() {
      var start = state.total ? (state.page - 1) * state.perPage + 1 : 0;
      var end = Math.min(state.page * state.perPage, state.total);
      var max = Math.max(1, state.totalPages);
      var nums = [1, max, state.page - 1, state.page, state.page + 1]
        .filter(function (value, index, arr) { return value >= 1 && value <= max && arr.indexOf(value) === index; })
        .sort(function (a, b) { return a - b; });
      byId('rangeLabel').textContent = 'Showing ' + start + '-' + end + ' of ' + state.total + ' suppliers';
      byId('pages').innerHTML =
        '<button data-page="' + (state.page - 1) + '" ' + (state.page <= 1 ? 'disabled' : '') + ' aria-label="Previous page">‹</button>' +
        nums.map(function (n) { return '<button data-page="' + n + '" class="' + (n === state.page ? 'current' : '') + '" ' + (n === state.page ? 'aria-current="page"' : '') + '>' + n + '</button>'; }).join('') +
        '<button data-page="' + (state.page + 1) + '" ' + (state.page >= max ? 'disabled' : '') + ' aria-label="Next page">›</button>';
    }

    function fetchCount(status) {
      var params = new URLSearchParams({ page: '1', per_page: '1' });
      if (status) params.set('status', status);
      return request(apiBase + '/index.php?' + params.toString()).then(function (response) {
        var data = dataOf(response);
        return Number(data.pagination && data.pagination.total || 0);
      });
    }

    function loadCounts() {
      return Promise.all([fetchCount(''), fetchCount('active'), fetchCount('inactive')]).then(function (counts) {
        byId('totalCount').textContent = counts[0].toLocaleString();
        byId('activeCount').textContent = counts[1].toLocaleString();
        byId('inactiveCount').textContent = counts[2].toLocaleString();
      });
    }

    function load() {
      hideNotice();
      byId('rows').innerHTML = '<tr><td colspan="8"><div class="empty">Loading suppliers...</div></td></tr>';
      var params = new URLSearchParams({ page: String(state.page), per_page: String(state.perPage) });
      if (state.search) params.set('search', state.search);
      if (state.status) params.set('status', state.status);

      return request(apiBase + '/index.php?' + params.toString()).then(function (response) {
        var data = dataOf(response);
        state.items = Array.isArray(data.items) ? data.items : [];
        state.total = Number(data.pagination && data.pagination.total || 0);
        state.totalPages = Number(data.pagination && data.pagination.total_pages || 0);
        renderRows();
        renderPages();
        return loadCounts();
      }).catch(function (error) {
        byId('rows').innerHTML = '<tr><td colspan="8"><div class="empty"><strong>Could not load suppliers</strong>' + esc(error.message) + '</div></td></tr>';
        byId('rangeLabel').textContent = 'Unable to load records';
        byId('pages').innerHTML = '';
        showNotice(error.message);
      });
    }

    function clearFormErrors() {
      ['nameError', 'codeError', 'contactError', 'phoneError', 'emailError', 'leadTimeError', 'paymentTermsError'].forEach(function (id) {
        byId(id).textContent = '';
      });
      hideNotice('formNotice');
    }

    function openForm(supplier) {
      byId('supplierForm').reset();
      clearFormErrors();
      byId('supplierId').value = supplier ? supplier.id : '';
      byId('formTitle').textContent = supplier ? 'Edit supplier' : 'Add supplier';
      byId('name').value = supplier ? supplier.name || '' : '';
      byId('code').value = supplier ? supplier.code || '' : '';
      byId('contact').value = supplier ? supplier.contact_name || '' : '';
      byId('phone').value = supplier ? supplier.phone || '' : '';
      byId('email').value = supplier ? supplier.email || '' : '';
      byId('leadTime').value = supplier && supplier.lead_time_days != null ? supplier.lead_time_days : '';
      byId('paymentTerms').value = supplier && supplier.payment_terms_days != null ? supplier.payment_terms_days : '';
      byId('status').value = supplier ? supplier.status || 'active' : 'active';
      byId('formBackdrop').classList.add('open');
      window.setTimeout(function () { byId('name').focus(); }, 10);
    }

    function closeForm() { byId('formBackdrop').classList.remove('open'); }

    function openDeactivate(supplier) {
      state.deactivateId = Number(supplier.id);
      state.deactivateName = supplier.name;
      byId('deactivateName').textContent = supplier.name;
      hideNotice('deactivateNotice');
      byId('deactivateBackdrop').classList.add('open');
    }

    function closeDeactivate() {
      byId('deactivateBackdrop').classList.remove('open');
      state.deactivateId = null;
      state.deactivateName = '';
    }

    byId('addBtn').addEventListener('click', function () { openForm(null); });
    byId('refreshBtn').addEventListener('click', load);

    var searchTimer = null;
    byId('searchInput').addEventListener('input', function (event) {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(function () { state.search = event.target.value.trim(); state.page = 1; load(); }, 300);
    });
    byId('statusFilter').addEventListener('change', function (event) { state.status = event.target.value; state.page = 1; load(); });
    byId('perPage').addEventListener('change', function (event) { state.perPage = Number(event.target.value); state.page = 1; load(); });

    byId('pages').addEventListener('click', function (event) {
      var button = event.target.closest('button[data-page]');
      if (!button || button.disabled) return;
      var page = Number(button.getAttribute('data-page'));
      if (page > 0 && page !== state.page) { state.page = page; load(); }
    });

    byId('rows').addEventListener('click', function (event) {
      var button = event.target.closest('button[data-action]');
      if (!button) return;
      var supplier = state.items.find(function (item) { return Number(item.id) === Number(button.getAttribute('data-id')); });
      if (!supplier) return;
      if (button.getAttribute('data-action') === 'edit') openForm(supplier);
      if (button.getAttribute('data-action') === 'deactivate') openDeactivate(supplier);
    });

    root.querySelectorAll('[data-close-form]').forEach(function (button) { button.addEventListener('click', closeForm); });
    root.querySelectorAll('[data-close-deactivate]').forEach(function (button) { button.addEventListener('click', closeDeactivate); });
    byId('formBackdrop').addEventListener('click', function (event) { if (event.target === byId('formBackdrop')) closeForm(); });
    byId('deactivateBackdrop').addEventListener('click', function (event) { if (event.target === byId('deactivateBackdrop')) closeDeactivate(); });

    $(document).off('keydown.stockmindSuppliers').on('keydown.stockmindSuppliers', function (event) {
      if (event.key === 'Escape') { closeForm(); closeDeactivate(); }
    });

    byId('supplierForm').addEventListener('submit', function (event) {
      event.preventDefault();
      clearFormErrors();
      var name = byId('name').value.trim();
      var email = byId('email').value.trim();
      var leadTime = byId('leadTime').value.trim();
      var paymentTerms = byId('paymentTerms').value.trim();
      var valid = true;

      if (!name) { byId('nameError').textContent = 'Supplier name is required.'; valid = false; }
      if (email && !byId('email').checkValidity()) { byId('emailError').textContent = 'Enter a valid email address.'; valid = false; }
      if (leadTime !== '' && (!Number.isFinite(Number(leadTime)) || Number(leadTime) < 0)) { byId('leadTimeError').textContent = 'Lead time must be a non-negative number.'; valid = false; }
      if (paymentTerms !== '' && (!/^\d+$/.test(paymentTerms) || Number(paymentTerms) < 0)) { byId('paymentTermsError').textContent = 'Payment terms must be a non-negative whole number.'; valid = false; }
      if (!valid) return;

      var id = byId('supplierId').value;
      var payload = {
        name: name,
        code: byId('code').value.trim() || null,
        contact_name: byId('contact').value.trim() || null,
        phone: byId('phone').value.trim() || null,
        email: email || null,
        lead_time_days: leadTime === '' ? null : Number(leadTime),
        payment_terms_days: paymentTerms === '' ? null : Number(paymentTerms),
        status: byId('status').value
      };
      var button = byId('saveBtn');
      button.disabled = true;
      button.textContent = 'Saving...';
      var action = id
        ? request(apiBase + '/update.php?id=' + encodeURIComponent(id), { method: 'PATCH', body: payload })
        : request(apiBase + '/index.php', { method: 'POST', body: payload });

      action.then(function () {
        closeForm();
        showNotice(id ? 'Supplier updated successfully.' : 'Supplier created successfully.', 'success');
        if (!id) state.page = 1;
        return load();
      }).catch(function (error) {
        var message = String(error.message || 'Unable to save supplier.');
        var lower = message.toLowerCase();
        var target = lower.indexOf('name') !== -1 ? 'nameError'
          : lower.indexOf('code') !== -1 ? 'codeError'
          : lower.indexOf('contact') !== -1 ? 'contactError'
          : lower.indexOf('phone') !== -1 ? 'phoneError'
          : lower.indexOf('email') !== -1 ? 'emailError'
          : lower.indexOf('lead time') !== -1 ? 'leadTimeError'
          : lower.indexOf('payment term') !== -1 ? 'paymentTermsError' : '';
        if (target) byId(target).textContent = message;
        else showNotice(message, 'error', 'formNotice');
      }).finally(function () {
        button.disabled = false;
        button.textContent = 'Save supplier';
      });
    });

    byId('confirmDeactivate').addEventListener('click', function () {
      if (!state.deactivateId) return;
      var button = byId('confirmDeactivate');
      button.disabled = true;
      button.textContent = 'Updating...';
      request(apiBase + '/update.php?id=' + encodeURIComponent(state.deactivateId), {
        method: 'PATCH',
        body: { status: 'inactive' }
      }).then(function () {
        var name = state.deactivateName;
        closeDeactivate();
        showNotice(name + ' marked as inactive.', 'success');
        return load();
      }).catch(function (error) {
        showNotice(error.message, 'error', 'deactivateNotice');
      }).finally(function () {
        button.disabled = false;
        button.innerHTML = '<i class="bi bi-slash-circle"></i> Deactivate';
      });
    });

    load();
  }

  window.StockMindModules.suppliers = { mount: mount };
})(window, document, window.jQuery);
