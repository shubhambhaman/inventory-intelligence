
/* global window, document */
(function (window, document) {
    'use strict';

    function initUnitsModule(root) {
        root = root || document.getElementById('units-module');

        if (!root || root.dataset.initialized === 'true') {
            return;
        }

        root.dataset.initialized = 'true';

        const apiBase = (root.dataset.apiBase || '').replace(/\/+$/, '');
        const $ = (id) => root.querySelector('#' + id);

        const tableBody = $('units-table-body');
        const alertBox = $('units-alert');
        const formAlert = $('units-form-alert');
        const form = $('unit-form');
        const saveButton = $('save-unit-btn');
        const modalElement = $('unit-modal');

        const requiredElements = [
            tableBody,
            alertBox,
            formAlert,
            form,
            saveButton,
            modalElement,
            $('add-unit-btn'),
            $('refresh-units-btn'),
            $('units-search'),
            $('units-status-filter'),
            $('units-per-page'),
            $('units-prev'),
            $('units-next'),
            $('unit-id'),
            $('unit-name'),
            $('unit-symbol'),
            $('unit-status'),
            $('unit-modal-title'),
            $('units-total'),
            $('units-active'),
            $('units-inactive'),
            $('units-count'),
            $('units-page')
        ];

        if (!apiBase || requiredElements.some((element) => !element)) {
            console.error('Units module: required HTML elements are missing.');
            root.dataset.initialized = 'false';
            return;
        }

        const state = {
            units: [],
            page: 1,
            perPage: 20,
            search: '',
            status: '',
            total: 0,
            totalPages: 1,
            loading: false,
            saving: false,
            searchTimer: null,
            requestVersion: 0
        };

        /* ---------------------------------------------------------
           Helpers
        --------------------------------------------------------- */

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char]);
        }

        function showAlert(message, type = 'error', target = alertBox) {
            target.className = target === formAlert
                ? `units-form-notice ${type}`
                : `units-notice ${type}`;

            target.textContent = String(message || '');
        }

        function hideAlert(target = alertBox) {
            target.className = target === formAlert
                ? 'units-form-notice'
                : 'units-notice';

            target.textContent = '';
        }

        function getCsrfToken() {
            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            if (!token) {
                throw new Error(
                    'Security token nahi mila. Page refresh karke dobara try karo.'
                );
            }

            return token;
        }

        async function parseResponse(response) {
            const responseText = await response.text();
            let result;

            try {
                result = JSON.parse(responseText);
            } catch {
                if (response.status === 401) {
                    throw new Error(
                        'Session expire ho gaya. Dobara login karo.'
                    );
                }

                console.error('Invalid API response:', responseText);

                throw new Error(
                    `Server se valid JSON response nahi mila (HTTP ${response.status}).`
                );
            }

            if (!response.ok || result.success === false) {
                if (response.status === 401) {
                    throw new Error(
                        'Session expire ho gaya. Dobara login karo.'
                    );
                }

                throw new Error(
                    result.message ||
                    result.error?.message ||
                    result.error ||
                    `Request failed (HTTP ${response.status}).`
                );
            }

            return result;
        }

        async function request(url, options = {}) {
            const headers = {
                Accept: 'application/json',
                ...(options.body ? {
                    'Content-Type': 'application/json'
                } : {}),
                ...(options.headers || {})
            };

            let response;

            try {
                response = await fetch(url, {
                    ...options,
                    headers,
                    credentials: 'same-origin'
                });
            } catch (error) {
                console.error('Units API network error:', error);

                throw new Error(
                    'Network error. Local server aur connection check karo.'
                );
            }

            return parseResponse(response);
        }

        function getData(result) {
            return result.data || {};
        }

        function getErrorMessage(error) {
            return error?.message || 'Something went wrong. Please try again.';
        }

        /* ---------------------------------------------------------
           Modal
        --------------------------------------------------------- */

        function openModal(unit = null) {
            form.reset();
            hideAlert(formAlert);

            $('unit-id').value = unit ? String(unit.id) : '';
            $('unit-name').value = unit ? (unit.name || '') : '';
            $('unit-symbol').value = unit
                ? (unit.symbol || unit.abbreviation || '')
                : '';

            $('unit-symbol').disabled = false;

            $('unit-status').value = unit
                ? (unit.status || 'active')
                : 'active';

            $('unit-modal-title').textContent = unit
                ? 'Edit Unit'
                : 'Add Unit';

            saveButton.textContent = unit ? 'Update Unit' : 'Save Unit';

            modalElement.classList.add('open');
            modalElement.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            $('unit-name').focus();
        }

        function closeModal() {
            if (state.saving) {
                return;
            }

            modalElement.classList.remove('open');
            modalElement.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';

            hideAlert(formAlert);
        }

        root.querySelectorAll('[data-units-close]').forEach((button) => {
            button.addEventListener('click', closeModal);
        });

        modalElement.addEventListener('click', (event) => {
            if (event.target === modalElement) {
                closeModal();
            }
        });

        // Root-scoped listener: avoids accumulating document listeners
        // when the Units module is mounted again.
        root.addEventListener('keydown', (event) => {
            if (
                event.key === 'Escape' &&
                modalElement.classList.contains('open')
            ) {
                closeModal();
            }
        });

        $('add-unit-btn').addEventListener('click', () => {
            openModal();
        });

        /* ---------------------------------------------------------
           Statistics
        --------------------------------------------------------- */

        function updateStats(total, active, inactive) {
            $('units-total').textContent = total;
            $('units-active').textContent = active;
            $('units-inactive').textContent = inactive;
        }

        async function refreshStats() {
            try {
                const [allResult, activeResult, inactiveResult] =
                    await Promise.all([
                        request(`${apiBase}/index.php?per_page=1`),
                        request(`${apiBase}/index.php?status=active&per_page=1`),
                        request(`${apiBase}/index.php?status=inactive&per_page=1`)
                    ]);

                const all = getData(allResult);
                const active = getData(activeResult);
                const inactive = getData(inactiveResult);

                updateStats(
                    Number(all.pagination?.total ?? all.items?.length ?? 0),
                    Number(active.pagination?.total ?? active.items?.length ?? 0),
                    Number(inactive.pagination?.total ?? inactive.items?.length ?? 0)
                );
            } catch (error) {
                console.error('Units stats refresh failed:', error);
            }
        }

        /* ---------------------------------------------------------
           Table rendering
        --------------------------------------------------------- */

        function renderRows(units) {
            if (!units.length) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="5">
                            <div class="units-empty">
                                <i class="bi bi-inbox"
                                   style="font-size:28px;display:block;margin-bottom:8px"></i>
                                No units found.
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }

            tableBody.innerHTML = units.map((unit) => {
                const status = String(unit.status || 'inactive').toLowerCase();

                const statusClass = status === 'active'
                    ? 'units-status-active'
                    : 'units-status-inactive';

                const statusIcon = status === 'active'
                    ? 'bi-check-circle-fill'
                    : 'bi-pause-circle-fill';

                const updatedAt = unit.updated_at || '—';
                const symbol = unit.symbol || unit.abbreviation || '—';

                return `
                    <tr>
                        <td>${escapeHtml(unit.name)}</td>
                        <td>${escapeHtml(symbol)}</td>
                        <td>
                            <span class="units-status-badge ${statusClass}">
                                <i class="bi ${statusIcon}"></i>
                                ${escapeHtml(status)}
                            </span>
                        </td>
                        <td>${escapeHtml(updatedAt)}</td>
                        <td>
                            <button
                                type="button"
                                class="units-action-btn"
                                data-action="edit"
                                data-id="${escapeHtml(unit.id)}"
                                aria-label="Edit ${escapeHtml(unit.name)}"
                                title="Edit unit">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        /* ---------------------------------------------------------
           Load units and pagination
        --------------------------------------------------------- */

        async function loadUnits() {
            const version = ++state.requestVersion;

            state.loading = true;
            hideAlert();

            tableBody.innerHTML = `
                <tr>
                    <td colspan="5">
                        <div class="units-empty">
                            <i class="bi bi-arrow-repeat"
                               style="font-size:22px;display:block;margin-bottom:8px"></i>
                            Loading units...
                        </div>
                    </td>
                </tr>
            `;

            state.perPage = Math.min(
                100,
                Math.max(1, Number($('units-per-page').value) || 20)
            );

            const params = new URLSearchParams({
                page: String(state.page),
                per_page: String(state.perPage),
                search: state.search,
                status: state.status
            });

            try {
                const result = await request(
                    `${apiBase}/index.php?${params.toString()}`
                );

                if (version !== state.requestVersion) {
                    return;
                }

                const data = getData(result);

                state.units = Array.isArray(data.items)
                    ? data.items
                    : [];

                const pagination = data.pagination || {};

                state.total = Number(
                    pagination.total ?? state.units.length
                );

                state.totalPages = Math.max(
                    1,
                    Number(
                        pagination.total_pages ??
                        Math.ceil(state.total / state.perPage)
                    )
                );

                if (state.page > state.totalPages) {
                    state.page = state.totalPages;
                    state.loading = false;
                    return loadUnits();
                }

                renderRows(state.units);

                const first = state.total
                    ? ((state.page - 1) * state.perPage) + 1
                    : 0;

                const last = Math.min(
                    state.page * state.perPage,
                    state.total
                );

                $('units-count').textContent = state.total
                    ? `Showing ${first}–${last} of ${state.total}`
                    : '0 units';

                $('units-page').textContent =
                    `${state.page} / ${state.totalPages}`;

                $('units-prev').disabled = state.page <= 1;
                $('units-next').disabled = state.page >= state.totalPages;

            } catch (error) {
                if (version !== state.requestVersion) {
                    return;
                }

                state.units = [];
                state.total = 0;
                state.totalPages = 1;

                renderRows([]);
                $('units-count').textContent = 'Unable to load units';
                $('units-page').textContent = '1 / 1';
                $('units-prev').disabled = true;
                $('units-next').disabled = true;

                showAlert(getErrorMessage(error));
            } finally {
                if (version === state.requestVersion) {
                    state.loading = false;
                }
            }
        }

        /* ---------------------------------------------------------
           Create and update unit
        --------------------------------------------------------- */

        async function saveUnit(event) {
            event.preventDefault();

            if (state.saving) {
                return;
            }

            hideAlert(formAlert);

            const id = $('unit-id').value.trim();
            const name = $('unit-name').value.trim();
            const symbol = $('unit-symbol').value.trim();
            const status = $('unit-status').value;

            if (!name) {
                showAlert(
                    'Please enter a unit name.',
                    'error',
                    formAlert
                );

                $('unit-name').focus();
                return;
            }

            if (name.length > 150) {
                showAlert(
                    'Unit name cannot exceed 150 characters.',
                    'error',
                    formAlert
                );

                $('unit-name').focus();
                return;
            }

            if (symbol.length > 20) {
                showAlert(
                    'Abbreviation cannot exceed 20 characters.',
                    'error',
                    formAlert
                );

                $('unit-symbol').focus();
                return;
            }

            if (!['active', 'inactive'].includes(status)) {
                showAlert(
                    'Please select a valid status.',
                    'error',
                    formAlert
                );
                return;
            }

            state.saving = true;
            saveButton.disabled = true;
            saveButton.textContent = id ? 'Updating...' : 'Saving...';

            try {
                const csrfToken = getCsrfToken();
                const isEdit = Boolean(id);

                const url = isEdit
                    ? `${apiBase}/update.php?id=${encodeURIComponent(id)}`
                    : `${apiBase}/index.php`;

                const result = await request(url, {
                    method: isEdit ? 'PATCH' : 'POST',
                    headers: {
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        name,
                        symbol: symbol || null,
                        status
                    })
                });

                modalElement.classList.remove('open');
                modalElement.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';

                state.page = 1;

                showAlert(
                    result.message ||
                    (isEdit
                        ? 'Unit updated successfully.'
                        : 'Unit created successfully.'),
                    'success'
                );

                await Promise.all([
                    loadUnits(),
                    refreshStats()
                ]);

            } catch (error) {
                showAlert(getErrorMessage(error), 'error', formAlert);

            } finally {
                state.saving = false;
                saveButton.disabled = false;
                saveButton.textContent = $('unit-id').value
                    ? 'Update Unit'
                    : 'Save Unit';
            }
        }

        form.addEventListener('submit', saveUnit);

        /* ---------------------------------------------------------
           Search, filters, refresh and pagination
        --------------------------------------------------------- */

        $('refresh-units-btn').addEventListener('click', () => {
            state.page = 1;
            loadUnits();
            refreshStats();
        });

        $('units-search').addEventListener('input', (event) => {
            window.clearTimeout(state.searchTimer);

            state.searchTimer = window.setTimeout(() => {
                state.search = event.target.value.trim();
                state.page = 1;
                loadUnits();
            }, 300);
        });

        $('units-status-filter').addEventListener('change', (event) => {
            state.status = event.target.value;
            state.page = 1;
            loadUnits();
        });

        $('units-per-page').addEventListener('change', () => {
            state.page = 1;
            loadUnits();
        });

        $('units-prev').addEventListener('click', () => {
            if (!state.loading && state.page > 1) {
                state.page--;
                loadUnits();
            }
        });

        $('units-next').addEventListener('click', () => {
            if (!state.loading && state.page < state.totalPages) {
                state.page++;
                loadUnits();
            }
        });

        /* ---------------------------------------------------------
           Edit button delegation
        --------------------------------------------------------- */

        tableBody.addEventListener('click', (event) => {
            const button = event.target.closest('[data-action="edit"]');

            if (!button || !root.contains(button)) {
                return;
            }

            const unit = state.units.find(
                (item) => String(item.id) === button.dataset.id
            );

            if (unit) {
                openModal(unit);
            }
        });

        /* ---------------------------------------------------------
           Initial load
        --------------------------------------------------------- */

        loadUnits();
        refreshStats();
    }

    // The module loader calls this whenever Units HTML is mounted.
    window.StockMindUnitsInit = initUnitsModule;

    // Support direct page load as well.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initUnitsModule();
        }, { once: true });
    } else {
        initUnitsModule();
    }

})(window, document);
