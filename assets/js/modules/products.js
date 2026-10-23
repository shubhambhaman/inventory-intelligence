/* global window, document, jQuery, URLSearchParams */
(function (window, document, $) {
  'use strict';

  window.StockMindModules = window.StockMindModules || {};

  function mount(root) {
    if (!root) {
      return;
    }

    var apiBase = root.getAttribute('data-products-api') || '';
    var categoriesApi = root.getAttribute('data-categories-api') || '';
    var unitsApi = root.getAttribute('data-units-api') || '';
    var state = {
      page: 1,
      perPage: 25,
      search: '',
      status: '',
      categoryId: '',
      items: [],
      categories: [],
      units: [],
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

    function payloadData(response) {
      return response && response.data ? response.data : response;
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

    function formatMoney(value) {
      if (value === null || value === undefined || value === '') {
        return '-';
      }

      var number = Number(value);
      if (!Number.isFinite(number)) {
        return esc(value);
      }

      return number.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    function normalizeDecimal(value) {
      var trimmed = value.trim();
      return trimmed === '' ? null : trimmed;
    }

    function fieldErrors(error) {
      var fields = error.payload && error.payload.error && error.payload.error.fields
        ? error.payload.error.fields
        : {};

      return fields.fields || fields;
    }

    function productCategory(product) {
      return product.category && product.category.name ? product.category.name : '-';
    }

    function productUnit(product) {
      return product.unit && product.unit.name ? product.unit.name : '-';
    }

    function renderRows() {
      if (!state.items.length) {
        byId('rows').innerHTML = '<tr><td colspan="7"><div class="empty"><i class="bi bi-tags"></i><strong>No products found</strong>Try another search/filter or add a product.</div></td></tr>';
        return;
      }

      byId('rows').innerHTML = state.items.map(function (product) {
        var id = Number(product.id);
        var status = product.status || 'draft';
        var canDeactivate = status !== 'inactive';
        var deactivate = canDeactivate
          ? '<button class="icon-btn danger" data-action="deactivate" data-id="' + id + '" aria-label="Deactivate product"><i class="bi bi-slash-circle"></i></button>'
          : '';

        return '<tr>' +
          '<td><div class="name">' + esc(product.name) + '</div><div class="small">SKU: ' + esc(product.sku) + ' · ID ' + id + '</div></td>' +
          '<td>' + esc(productCategory(product)) + '</td>' +
          '<td>' + esc(productUnit(product)) + '</td>' +
          '<td><div class="price-line"><span>Buy: ' + formatMoney(product.purchase_price) + '</span><span class="small">Sell: ' + formatMoney(product.selling_price) + '</span></div></td>' +
          '<td><span class="status ' + esc(status) + '">' + esc(status.charAt(0).toUpperCase() + status.slice(1)) + '</span></td>' +
          '<td>' + esc(product.updated_at || '-') + '</td>' +
          '<td><div class="actions"><button class="icon-btn" data-action="edit" data-id="' + id + '" aria-label="Edit product"><i class="bi bi-pencil"></i></button>' + deactivate + '</div></td>' +
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

      byId('rangeLabel').textContent = 'Showing ' + start + '-' + end + ' of ' + state.total + ' products';
      byId('pages').innerHTML =
        '<button data-page="' + (state.page - 1) + '" ' + (state.page <= 1 ? 'disabled' : '') + ' aria-label="Previous page">‹</button>' +
        nums.map(function (n) {
          return '<button data-page="' + n + '" class="' + (n === state.page ? 'current' : '') + '" ' + (n === state.page ? 'aria-current="page"' : '') + '>' + n + '</button>';
        }).join('') +
        '<button data-page="' + (state.page + 1) + '" ' + (state.page >= max ? 'disabled' : '') + ' aria-label="Next page">›</button>';
    }

    function renderOptions() {
      var categoryOptions = '<option value="">Select category</option>' + state.categories.map(function (category) {
        return '<option value="' + Number(category.id) + '">' + esc(category.name) + '</option>';
      }).join('');
      var unitOptions = '<option value="">Select unit</option>' + state.units.map(function (unit) {
        return '<option value="' + Number(unit.id) + '">' + esc(unit.name) + '</option>';
      }).join('');
      var filterOptions = '<option value="">All categories</option>' + state.categories.map(function (category) {
        return '<option value="' + Number(category.id) + '">' + esc(category.name) + '</option>';
      }).join('');

      byId('category').innerHTML = categoryOptions;
      byId('unit').innerHTML = unitOptions;
      byId('categoryFilter').innerHTML = filterOptions;
      byId('categoryFilter').value = state.categoryId;
    }

    function loadLookups() {
      var categories = request(categoriesApi + '/index.php?status=active&per_page=100')
        .then(function (response) {
          state.categories = payloadData(response).items || [];
        });
      var units = request(unitsApi + '/index.php?status=active&per_page=100')
        .then(function (response) {
          state.units = payloadData(response).items || [];
        });

      return Promise.all([categories, units]).then(renderOptions);
    }

    function loadCounts() {
      return Promise.all([
        request(apiBase + '/index.php?per_page=1'),
        request(apiBase + '/index.php?status=active&per_page=1'),
        request(apiBase + '/index.php?status=draft&per_page=1')
      ]).then(function (results) {
        var total = Number(payloadData(results[0]).pagination && payloadData(results[0]).pagination.total || 0);
        var active = Number(payloadData(results[1]).pagination && payloadData(results[1]).pagination.total || 0);
        var draft = Number(payloadData(results[2]).pagination && payloadData(results[2]).pagination.total || 0);
        byId('totalCount').textContent = total.toLocaleString();
        byId('activeCount').textContent = active.toLocaleString();
        byId('draftCount').textContent = draft.toLocaleString();
      });
    }

    function load() {
      hideNotice();
      byId('rows').innerHTML = '<tr><td colspan="7"><div class="empty">Loading products...</div></td></tr>';

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
      if (state.categoryId) {
        params.set('category_id', state.categoryId);
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
          byId('rows').innerHTML = '<tr><td colspan="7"><div class="empty"><strong>Could not load products</strong>' + esc(error.message) + '</div></td></tr>';
          byId('rangeLabel').textContent = 'Unable to load records';
          byId('pages').innerHTML = '';
          showNotice(error.message);
        });
    }

    function clearFormErrors() {
      ['skuError', 'nameError', 'categoryError', 'unitError', 'purchasePriceError', 'sellingPriceError', 'taxRateError'].forEach(function (id) {
        byId(id).textContent = '';
      });
      byId('formNotice').className = 'notice error';
      byId('formNotice').textContent = '';
    }

    function openForm(product) {
      var form = byId('productForm');
      form.reset();
      clearFormErrors();

      byId('productId').value = product ? product.id : '';
      byId('formTitle').textContent = product ? 'Edit product' : 'Add product';
      byId('sku').value = product ? product.sku || '' : '';
      byId('name').value = product ? product.name || '' : '';
      byId('category').value = product && product.category ? String(product.category.id) : '';
      byId('unit').value = product && product.unit ? String(product.unit.id) : '';
      byId('purchasePrice').value = product && product.purchase_price !== null ? product.purchase_price : '';
      byId('sellingPrice').value = product && product.selling_price !== null ? product.selling_price : '';
      byId('taxRate').value = product && product.tax_rate !== null ? product.tax_rate : '';
      byId('status').value = product ? product.status || 'draft' : 'draft';
      byId('formBackdrop').classList.add('open');
      window.setTimeout(function () { byId('sku').focus(); }, 10);
    }

    function closeForm() {
      byId('formBackdrop').classList.remove('open');
    }

    function openDeactivate(product) {
      state.deactivateId = Number(product.id);
      state.deactivateName = product.name;
      byId('deactivateName').textContent = product.name;
      byId('deactivateNotice').className = 'notice error';
      byId('deactivateNotice').textContent = '';
      byId('deactivateBackdrop').classList.add('open');
    }

    function closeDeactivate() {
      byId('deactivateBackdrop').classList.remove('open');
    }

    function validateDecimalField(id, errorId, label) {
      var value = byId(id).value.trim();
      if (value !== '' && !/^\d+(?:\.\d+)?$/.test(value)) {
        byId(errorId).textContent = label + ' must be a non-negative number.';
        byId(id).focus();
        return false;
      }
      return true;
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

    byId('categoryFilter').addEventListener('change', function (event) {
      state.categoryId = event.target.value;
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
      var product = state.items.find(function (item) {
        return Number(item.id) === Number(button.getAttribute('data-id'));
      });
      if (!product) {
        return;
      }
      if (button.getAttribute('data-action') === 'edit') {
        openForm(product);
      } else {
        openDeactivate(product);
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

    $(document).off('keydown.stockmindProducts').on('keydown.stockmindProducts', function (event) {
      if (event.key === 'Escape') {
        closeForm();
        closeDeactivate();
      }
    });

    byId('productForm').addEventListener('submit', function (event) {
      event.preventDefault();
      clearFormErrors();

      if (!byId('sku').value.trim()) {
        byId('skuError').textContent = 'SKU is required.';
        byId('sku').focus();
        return;
      }
      if (!byId('name').value.trim()) {
        byId('nameError').textContent = 'Product name is required.';
        byId('name').focus();
        return;
      }
      if (!byId('category').value) {
        byId('categoryError').textContent = 'Category is required.';
        byId('category').focus();
        return;
      }
      if (!byId('unit').value) {
        byId('unitError').textContent = 'Unit is required.';
        byId('unit').focus();
        return;
      }
      if (!validateDecimalField('purchasePrice', 'purchasePriceError', 'Purchase price') ||
          !validateDecimalField('sellingPrice', 'sellingPriceError', 'Selling price') ||
          !validateDecimalField('taxRate', 'taxRateError', 'Tax rate')) {
        return;
      }

      var id = byId('productId').value;
      var payload = {
        sku: byId('sku').value.trim(),
        name: byId('name').value.trim(),
        category_id: Number(byId('category').value),
        unit_id: Number(byId('unit').value),
        purchase_price: normalizeDecimal(byId('purchasePrice').value),
        selling_price: normalizeDecimal(byId('sellingPrice').value),
        tax_rate: normalizeDecimal(byId('taxRate').value),
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
          showNotice(id ? 'Product updated successfully.' : 'Product created successfully.', 'success');
          if (!id) {
            state.page = 1;
          }
          closeForm();
          return load();
        })
        .catch(function (error) {
          var fields = fieldErrors(error);
          if (fields.sku) {
            byId('skuError').textContent = fields.sku;
          } else if (fields.name) {
            byId('nameError').textContent = fields.name;
          } else if (fields.category_id) {
            byId('categoryError').textContent = fields.category_id;
          } else if (fields.unit_id) {
            byId('unitError').textContent = fields.unit_id;
          } else if (fields.purchase_price) {
            byId('purchasePriceError').textContent = fields.purchase_price;
          } else if (fields.selling_price) {
            byId('sellingPriceError').textContent = fields.selling_price;
          } else if (fields.tax_rate) {
            byId('taxRateError').textContent = fields.tax_rate;
          } else {
            byId('formNotice').textContent = error.message;
            byId('formNotice').classList.add('show');
          }
        })
        .finally(function () {
          button.disabled = false;
          button.textContent = 'Save product';
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
          button.innerHTML = '<i class="bi bi-slash-circle"></i> Deactivate';
        });
    });

    loadLookups()
      .then(load)
      .catch(function (error) {
        showNotice(error.message);
        load();
      });
  }

  window.StockMindModules.products = {
    mount: mount
  };
})(window, document, jQuery);
