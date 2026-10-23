
/* global window, document, URLSearchParams */
(function (window, document) {
  'use strict';

  window.StockMindModules = window.StockMindModules || {};

  function mount(root) {
    if (!root || root.dataset.categoriesMounted === '1') {
      return;
    }

    root.dataset.categoriesMounted = '1';

    var apiBase = root.getAttribute('data-categories-api') || '';

    var state = {
      page: 1,
      perPage: 25,
      search: '',
      status: '',
      items: [],
      parentOptions: [],
      total: 0,
      totalPages: 0,
      deactivateId: null,
      deactivateName: '',
      searchTimer: null
    };

    function byId(id) {
      return root.querySelector('#' + id);
    }

    function esc(value) {
      return window.StockMindApp
        ? window.StockMindApp.escapeHtml(value == null ? '' : String(value))
        : String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return {
              '&': '&amp;',
              '<': '&lt;',
              '>': '&gt;',
              '"': '&quot;',
              "'": '&#39;'
            }[char];
          });
    }

    function request(url, options) {
      if (!window.StockMindAPI ||
          typeof window.StockMindAPI.request !== 'function') {
        return Promise.reject(
          new Error('Shared API helper is not available.')
        );
      }

      return window.StockMindAPI.request(url, options || {});
    }

    function payloadData(response) {
      return response && response.data ? response.data : response;
    }

    function errorMessage(error) {
      return error && error.message
        ? error.message
        : 'Something went wrong. Please try again.';
    }

    function showNotice(message, type) {
      var notice = byId('categoryNotice');
      notice.className = 'notice show ' + (type || 'error');
      notice.textContent = message;
    }

    function hideNotice() {
      var notice = byId('categoryNotice');
      notice.className = 'notice';
      notice.textContent = '';
    }

    function showFormNotice(message) {
      var notice = byId('categoryFormNotice');
      notice.textContent = message;
      notice.classList.add('show');
    }

    function hideFormNotice() {
      var notice = byId('categoryFormNotice');
      notice.textContent = '';
      notice.classList.remove('show');
    }

    function formatDate(value) {
      if (!value) {
        return '-';
      }

      return esc(String(value).replace('T', ' ').replace(/\.\d+Z?$/, ''));
    }

    function loadCounts() {
      return Promise.all([
        request(apiBase + '/index.php?per_page=1'),
        request(apiBase + '/index.php?status=active&per_page=1'),
        request(apiBase + '/index.php?status=inactive&per_page=1')
      ]).then(function (results) {
        var allData = payloadData(results[0]);
        var activeData = payloadData(results[1]);
        var inactiveData = payloadData(results[2]);

        byId('categoryTotalCount').textContent =
          Number(allData.pagination && allData.pagination.total || 0).toLocaleString();

        byId('categoryActiveCount').textContent =
          Number(activeData.pagination && activeData.pagination.total || 0).toLocaleString();

        byId('categoryInactiveCount').textContent =
          Number(inactiveData.pagination && inactiveData.pagination.total || 0).toLocaleString();
      });
    }

    function loadParentOptions() {
      return request(apiBase + '/index.php?per_page=100')
        .then(function (response) {
          var data = payloadData(response);
          state.parentOptions = Array.isArray(data.items) ? data.items : [];
        });
    }

    function renderParentOptions(selectedId, editingId) {
      var options = '<option value="">No parent category</option>';

      state.parentOptions.forEach(function (category) {
        var id = Number(category.id);

        if (editingId && id === Number(editingId)) {
          return;
        }

        var selected = selectedId && id === Number(selectedId)
          ? ' selected'
          : '';

        var statusLabel = category.status === 'inactive'
          ? ' (Inactive)'
          : '';

        options += '<option value="' + id + '"' + selected + '>' +
          esc(category.name) + esc(statusLabel) +
          '</option>';
      });

      byId('categoryParent').innerHTML = options;
    }

    function renderRows() {
      if (!state.items.length) {
        byId('categoryRows').innerHTML =
          '<tr><td colspan="5"><div class="empty">' +
          '<i class="bi bi-tags"></i>' +
          '<strong>No categories found</strong>' +
          'Try another search or status filter, or add a category.' +
          '</div></td></tr>';
        return;
      }

      byId('categoryRows').innerHTML = state.items.map(function (category) {
        var id = Number(category.id);
        var status = category.status === 'inactive' ? 'inactive' : 'active';
        var statusLabel = status === 'active' ? 'Active' : 'Inactive';

        var toggleButton = status === 'active'
          ? '<button class="icon-btn danger" data-action="deactivate" data-id="' +
            id + '" aria-label="Deactivate category" title="Deactivate">' +
            '<i class="bi bi-slash-circle"></i></button>'
          : '<button class="icon-btn" data-action="activate" data-id="' +
            id + '" aria-label="Activate category" title="Activate">' +
            '<i class="bi bi-check-circle"></i></button>';

        return '<tr>' +
          '<td><div class="name">' + esc(category.name) + '</div>' +
          '<div class="small">ID ' + id + '</div></td>' +
          '<td>' + esc(category.parent_name || '—') + '</td>' +
          '<td><span class="status ' + status + '">' + statusLabel + '</span></td>' +
          '<td>' + formatDate(category.updated_at || category.created_at) + '</td>' +
          '<td><div class="actions">' +
          '<button class="icon-btn" data-action="edit" data-id="' + id +
          '" aria-label="Edit category" title="Edit">' +
          '<i class="bi bi-pencil"></i></button>' +
          toggleButton +
          '</div></td>' +
          '</tr>';
      }).join('');
    }

    function renderPages() {
      var start = state.total
        ? (state.page - 1) * state.perPage + 1
        : 0;

      var end = Math.min(state.page * state.perPage, state.total);
      var max = Math.max(1, state.totalPages);

      var pages = [1, max, state.page - 1, state.page, state.page + 1]
        .filter(function (value, index, list) {
          return value >= 1 &&
            value <= max &&
            list.indexOf(value) === index;
        })
        .sort(function (a, b) {
          return a - b;
        });

      byId('categoryRangeLabel').textContent =
        'Showing ' + start + '-' + end + ' of ' + state.total + ' categories';

      byId('categoryPages').innerHTML =
        '<button data-page="' + (state.page - 1) + '"' +
        (state.page <= 1 ? ' disabled' : '') +
        ' aria-label="Previous page">‹</button>' +
        pages.map(function (page) {
          return '<button data-page="' + page + '"' +
            (page === state.page ? ' class="current" aria-current="page"' : '') +
            '>' + page + '</button>';
        }).join('') +
        '<button data-page="' + (state.page + 1) + '"' +
        (state.page >= max ? ' disabled' : '') +
        ' aria-label="Next page">›</button>';
    }

    function load() {
      hideNotice();

      byId('categoryRows').innerHTML =
        '<tr><td colspan="5"><div class="empty">Loading categories...</div></td></tr>';

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
        .then(function (response) {
          var data = payloadData(response);

          state.items = Array.isArray(data.items) ? data.items : [];
          state.total = Number(data.pagination && data.pagination.total || 0);
          state.totalPages = Number(data.pagination && data.pagination.total_pages || 0);

          renderRows();
          renderPages();

          return loadCounts();
        })
        .catch(function (error) {
          byId('categoryRows').innerHTML =
            '<tr><td colspan="5"><div class="empty">' +
            '<strong>Could not load categories</strong>' +
            esc(errorMessage(error)) +
            '</div></td></tr>';

          byId('categoryRangeLabel').textContent = 'Unable to load records';
          byId('categoryPages').innerHTML = '';
          showNotice(errorMessage(error), 'error');
        });
    }

    function clearFormErrors() {
      ['categoryNameError', 'categoryParentError', 'categoryStatusError']
        .forEach(function (id) {
          byId(id).textContent = '';
        });

      hideFormNotice();
    }

    function openForm(category) {
      byId('categoryForm').reset();
      clearFormErrors();

      byId('categoryId').value = category ? category.id : '';
      byId('categoryFormTitle').textContent =
        category ? 'Edit category' : 'Add category';

      byId('categorySaveBtn').textContent = 'Save category';
      byId('categoryName').value = category ? category.name || '' : '';
      byId('categoryFormStatus').value =
        category ? category.status || 'active' : 'active';

      renderParentOptions(
        category ? category.parent_id : '',
        category ? category.id : null
      );

      byId('categoryFormBackdrop').classList.add('open');
      byId('categoryName').focus();
    }

    function closeForm() {
      byId('categoryFormBackdrop').classList.remove('open');
    }

    function openDeactivate(category) {
      state.deactivateId = Number(category.id);
      state.deactivateName = category.name;

      byId('categoryDeactivateName').textContent = category.name;
      byId('categoryDeactivateNotice').className = 'notice error';
      byId('categoryDeactivateNotice').textContent = '';
      byId('categoryDeactivateBackdrop').classList.add('open');
    }

    function closeDeactivate() {
      byId('categoryDeactivateBackdrop').classList.remove('open');
    }

    function setBusy(button, busy, busyText, defaultText) {
      button.disabled = busy;
      button.textContent = busy ? busyText : defaultText;
    }

    function saveCategory(event) {
      event.preventDefault();
      clearFormErrors();

      var name = byId('categoryName').value.trim();
      var parentValue = byId('categoryParent').value;
      var status = byId('categoryFormStatus').value;
      var id = byId('categoryId').value;

      if (!name) {
        byId('categoryNameError').textContent = 'Category name is required.';
        byId('categoryName').focus();
        return;
      }

      if (name.length > 150) {
        byId('categoryNameError').textContent =
          'Category name cannot exceed 150 characters.';
        byId('categoryName').focus();
        return;
      }

      if (status !== 'active' && status !== 'inactive') {
        byId('categoryStatusError').textContent = 'Choose a valid status.';
        return;
      }

      var payload = {
        name: name,
        parent_id: parentValue ? Number(parentValue) : null,
        status: status
      };

      var button = byId('categorySaveBtn');
      var defaultText = 'Save category';

      setBusy(button, true, 'Saving...', defaultText);

      var action = id
        ? request(apiBase + '/update.php?id=' + encodeURIComponent(id), {
            method: 'PATCH',
            body: payload
          })
        : request(apiBase + '/index.php', {
            method: 'POST',
            body: payload
          });

      action.then(function () {
        closeForm();

        showNotice(
          id ? 'Category updated successfully.' : 'Category created successfully.',
          'success'
        );

        if (!id) {
          state.page = 1;
        }

        return loadParentOptions().then(load);
      }).catch(function (error) {
        showFormNotice(errorMessage(error));
      }).finally(function () {
        setBusy(button, false, 'Saving...', defaultText);
      });
    }

    function deactivateCategory() {
      if (!state.deactivateId) {
        return;
      }

      var button = byId('categoryConfirmDeactivate');
      setBusy(button, true, 'Deactivating...', 'Deactivate');

      request(
        apiBase + '/deactivate.php?id=' +
          encodeURIComponent(state.deactivateId),
        { method: 'POST' }
      ).then(function () {
        var name = state.deactivateName;

        closeDeactivate();
        state.deactivateId = null;
        state.deactivateName = '';

        showNotice(name + ' has been deactivated.', 'success');
        return loadParentOptions().then(load);
      }).catch(function (error) {
        var notice = byId('categoryDeactivateNotice');
        notice.textContent = errorMessage(error);
        notice.classList.add('show');
      }).finally(function () {
        setBusy(button, false, 'Deactivating...', 'Deactivate');
      });
    }

    function activateCategory(category) {
      var button = root.querySelector(
        'button[data-action="activate"][data-id="' + Number(category.id) + '"]'
      );

      if (button) {
        button.disabled = true;
      }

      request(apiBase + '/update.php?id=' + encodeURIComponent(category.id), {
        method: 'PATCH',
        body: { status: 'active' }
      }).then(function () {
        showNotice(category.name + ' has been activated.', 'success');
        return loadParentOptions().then(load);
      }).catch(function (error) {
        showNotice(errorMessage(error), 'error');

        if (button) {
          button.disabled = false;
        }
      });
    }

    byId('categoryAddBtn').addEventListener('click', function () {
      openForm(null);
    });

    byId('categoryRefreshBtn').addEventListener('click', function () {
      load();
    });

    byId('categorySearchInput').addEventListener('input', function (event) {
      window.clearTimeout(state.searchTimer);

      state.searchTimer = window.setTimeout(function () {
        state.search = event.target.value.trim();
        state.page = 1;
        load();
      }, 300);
    });

    byId('categoryStatusFilter').addEventListener('change', function (event) {
      state.status = event.target.value;
      state.page = 1;
      load();
    });

    byId('categoryPerPage').addEventListener('change', function (event) {
      state.perPage = Number(event.target.value) || 25;
      state.page = 1;
      load();
    });

    byId('categoryPages').addEventListener('click', function (event) {
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

    byId('categoryRows').addEventListener('click', function (event) {
      var button = event.target.closest('button[data-action]');

      if (!button) {
        return;
      }

      var id = Number(button.getAttribute('data-id'));
      var category = state.items.find(function (item) {
        return Number(item.id) === id;
      });

      if (!category) {
        return;
      }

      var action = button.getAttribute('data-action');

      if (action === 'edit') {
        openForm(category);
      } else if (action === 'deactivate') {
        openDeactivate(category);
      } else if (action === 'activate') {
        activateCategory(category);
      }
    });

    root.querySelectorAll('[data-category-close-form]').forEach(function (button) {
      button.addEventListener('click', closeForm);
    });

    root.querySelectorAll('[data-category-close-deactivate]').forEach(function (button) {
      button.addEventListener('click', closeDeactivate);
    });

    byId('categoryFormBackdrop').addEventListener('click', function (event) {
      if (event.target === byId('categoryFormBackdrop')) {
        closeForm();
      }
    });

    byId('categoryDeactivateBackdrop').addEventListener('click', function (event) {
      if (event.target === byId('categoryDeactivateBackdrop')) {
        closeDeactivate();
      }
    });

    byId('categoryForm').addEventListener('submit', saveCategory);

    byId('categoryConfirmDeactivate').addEventListener(
      'click',
      deactivateCategory
    );

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeForm();
        closeDeactivate();
      }
    });

    loadParentOptions()
      .then(load)
      .catch(function (error) {
        showNotice(errorMessage(error), 'error');
        load();
      });
  }

  window.StockMindModules.categories = {
    mount: mount
  };
})(window, document);
