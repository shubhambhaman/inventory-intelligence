
<?php


$unitsApiBase = $moduleApiBase ?? (($basePath ?? '') . '/api/v1/units');

$unitsEscape = static function ($value): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
};

?>

<section
    id="units-module"
    class="module-view module-units"
    data-module-view="units"
    data-api-base="<?= $unitsEscape($unitsApiBase) ?>"
>
    <!-- Page heading -->
    <div class="units-head">
        <div>
            <div class="units-eyebrow">Inventory · Master data</div>
            <h1>Units Management</h1>
            <p>Manage measurement units used by your products.</p>
        </div>

        <button
            class="units-btn units-btn-primary"
            id="add-unit-btn"
            type="button"
        >
            <i class="bi bi-plus-lg"></i>
            Add Unit
        </button>
    </div>

    <!-- Statistics -->
    <section class="units-stats" aria-label="Unit totals">
        <div class="units-stat">
            <span class="units-stat-icon">
                <i class="bi bi-rulers"></i>
            </span>

            <div>
                <div class="units-label">Total Units</div>
                <div class="units-value" id="units-total">0</div>
            </div>
        </div>

        <div class="units-stat">
            <span class="units-stat-icon">
                <i class="bi bi-check2-circle"></i>
            </span>

            <div>
                <div class="units-label">Active Units</div>
                <div class="units-value" id="units-active">0</div>
            </div>
        </div>

        <div class="units-stat">
            <span class="units-stat-icon">
                <i class="bi bi-pause-circle"></i>
            </span>

            <div>
                <div class="units-label">Inactive Units</div>
                <div class="units-value" id="units-inactive">0</div>
            </div>
        </div>
    </section>

    <!-- Units directory -->
    <section class="units-panel">
        <div class="units-panel-head">
            <div>
                <div class="units-panel-title">Units Directory</div>
                <div class="units-sub">
                    Search, filter, and manage measurement units.
                </div>
            </div>

            <button
                class="units-btn"
                id="refresh-units-btn"
                type="button"
            >
                <i class="bi bi-arrow-clockwise"></i>
                Refresh
            </button>
        </div>

        <!-- Filters -->
        <div class="units-filters">
            <input
                class="units-field units-search"
                id="units-search"
                type="search"
                maxlength="150"
                placeholder="Search unit name..."
                aria-label="Search units"
            >

            <select
                class="units-field"
                id="units-status-filter"
                aria-label="Filter status"
            >
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>

            <select
                class="units-field"
                id="units-per-page"
                aria-label="Rows per page"
            >
                <option value="20">20 per page</option>
                <option value="50">50 per page</option>
                <option value="100">100 per page</option>
            </select>
        </div>

        <!-- API messages -->
        <div
            class="units-notice"
            id="units-alert"
            role="status"
            aria-live="polite"
        ></div>

        <!-- Table -->
        <div class="units-table-wrap">
            <table class="units-table">
                <thead>
                    <tr>
                        <th>Unit</th>
                        <th>Abbreviation</th>
                        <th>Status</th>
                        <th>Last updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody id="units-table-body">
                    <tr>
                        <td colspan="5">
                            <div class="units-empty">Loading units...</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="units-footer">
            <span id="units-count">Loading...</span>

            <div class="units-pages">
                <button
                    type="button"
                    id="units-prev"
                    disabled
                    aria-label="Previous page"
                >
                    <i class="bi bi-chevron-left"></i>
                </button>

                <span id="units-page">1 / 1</span>

                <button
                    type="button"
                    id="units-next"
                    disabled
                    aria-label="Next page"
                >
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>
        </div>
    </section>

    <!-- Add/Edit Unit modal: custom Products-style modal -->
    <div
        class="units-backdrop"
        id="unit-modal"
        role="dialog"
        aria-modal="true"
        aria-hidden="true"
        aria-labelledby="unit-modal-title"
    >
        <form class="units-modal" id="unit-form" novalidate>
            <!-- Modal header -->
            <div class="units-modal-head">
                <div>
                    <h2 id="unit-modal-title">Add Unit</h2>
                    <p>Enter the unit details below.</p>
                </div>

                <button
                    type="button"
                    class="units-icon-btn"
                    data-units-close
                    aria-label="Close"
                >
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <!-- Modal body -->
            <div class="units-modal-body">
                <div
                    class="units-form-notice"
                    id="units-form-alert"
                    role="alert"
                    aria-live="polite"
                ></div>

                <input type="hidden" id="unit-id">

                <div class="units-form-grid">
                    <!-- Unit name -->
                    <div class="units-form-field full">
                        <label for="unit-name">
                            Unit Name <span>*</span>
                        </label>

                        <input
                            type="text"
                            class="units-form-control"
                            id="unit-name"
                            maxlength="150"
                            placeholder="e.g. Kilogram"
                            autocomplete="off"
                            required
                        >
                    </div>

                    <!-- Abbreviation -->
                    <div class="units-form-field full">
                        <label for="unit-symbol">Abbreviation</label>

                        <input
                            type="text"
                            class="units-form-control"
                            id="unit-symbol"
                            maxlength="20"
                            placeholder="e.g. kg, g, pcs"
                            autocomplete="off"
                        >

                        <small class="units-field-help">
                            Examples: kg, g, litre, pcs.
                        </small>
                    </div>

                    <!-- Status -->
                    <div class="units-form-field full">
                        <label for="unit-status">Status</label>

                        <select
                            class="units-form-control"
                            id="unit-status"
                        >
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Modal footer -->
            <div class="units-modal-foot">
                <button
                    type="button"
                    class="units-btn"
                    data-units-close
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="units-btn units-btn-primary"
                    id="save-unit-btn"
                >
                    Save Unit
                </button>
            </div>
        </form>
    </div>
</section>
