<?php
$warehousesApiBase = $moduleApiBase ?? (($basePath ?? '') . '/api/v1/warehouses');
$warehousesAccessApi = ($basePath ?? '') . '/api/v1/warehouses/access.php';
?>
<section
    class="module-view module-warehouses"
    data-module-view="warehouses"
    data-warehouses-api="<?= $e($warehousesApiBase) ?>"
    data-warehouse-access-api="<?= $e($warehousesAccessApi) ?>"
>
    <div class="crumb">
        <a href="<?= $e(($basePath ?? '') . '/dashboard.php') ?>" data-module-link data-module="dashboard">Dashboard</a>
        <span aria-hidden="true">&nbsp;›&nbsp;</span>
        <span>Warehouses</span>
    </div>

    <section class="head">
        <div>
            <div class="eyebrow">Inventory · Master data</div>
            <h1>Warehouses</h1>
            <p>Manage warehouse details, parent-location hierarchy, status and user access.</p>
        </div>
        <button class="btn btn-primary" id="addWarehouseBtn" type="button">
            <i class="bi bi-plus-lg"></i> Add warehouse
        </button>
    </section>

    <section class="stats" aria-label="Warehouse totals">
        <div class="stat">
            <span class="stat-icon"><i class="bi bi-buildings"></i></span>
            <div><div class="label">Total warehouses</div><div class="value" id="totalCount">—</div></div>
        </div>
        <div class="stat">
            <span class="stat-icon"><i class="bi bi-check-circle"></i></span>
            <div><div class="label">Active</div><div class="value" id="activeCount">—</div></div>
        </div>
        <div class="stat">
            <span class="stat-icon"><i class="bi bi-slash-circle"></i></span>
            <div><div class="label">Inactive</div><div class="value" id="inactiveCount">—</div></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Warehouse directory</div>
                <div class="sub">Search by code, name, city or state. Records are tenant-scoped by the API.</div>
            </div>
            <button class="btn" id="refreshWarehousesBtn" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>

        <div class="filters">
            <input class="field search" id="warehouseSearch" type="search" maxlength="150"
                placeholder="Search code, warehouse, city or state..." aria-label="Search warehouses">
            <select class="field" id="warehouseStatusFilter" aria-label="Warehouse status">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <select class="field" id="warehousePerPage" aria-label="Rows per page">
                <option value="20">20 per page</option>
                <option value="50">50 per page</option>
                <option value="100">100 per page</option>
            </select>
        </div>

        <div class="notice" id="warehouseNotice" role="status" aria-live="polite"></div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Warehouse</th>
                        <th>Location</th>
                        <th>Parent location</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="warehouseRows">
                    <tr><td colspan="6"><div class="empty">Loading warehouses…</div></td></tr>
                </tbody>
            </table>
        </div>

        <div class="footer">
            <span id="warehouseRangeLabel">Loading…</span>
            <div class="pages" id="warehousePages"></div>
        </div>
    </section>

    <section class="panel access-panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><i class="bi bi-person-lock"></i> User warehouse access</div>
                <div class="sub">Assign or remove a user's access to a warehouse. The server validates that both belong to the signed-in company.</div>
            </div>
        </div>
        <div class="access-body">
            <form id="warehouseAccessForm" class="access-form" novalidate>
                <div class="form-field">
                    <label for="accessUserId">User ID *</label>
                    <input id="accessUserId" type="number" min="1" step="1" required inputmode="numeric" placeholder="Enter user ID">
                    <small>There is no user-list endpoint in the supplied API files, so enter the existing user's database ID.</small>
                </div>
                <div class="form-field">
                    <label for="accessWarehouseId">Warehouse *</label>
                    <select id="accessWarehouseId" required>
                        <option value="">Select warehouse</option>
                    </select>
                    <small id="accessWarehouseHelp">Select from the loaded warehouse list.</small>
                </div>
                <div class="access-actions">
                    <button class="btn btn-primary" id="assignAccessBtn" type="submit" data-access-action="assign">
                        <i class="bi bi-person-check"></i> Assign access
                    </button>
                    <button class="btn btn-danger-outline" id="removeAccessBtn" type="button" data-access-action="remove">
                        <i class="bi bi-person-dash"></i> Remove access
                    </button>
                </div>
            </form>
            <div class="notice" id="accessNotice" role="status" aria-live="polite"></div>
            <p class="access-note"><strong>Note:</strong> The supplied API supports assigning/removing access but does not expose a GET endpoint to list current assignments. This panel therefore submits changes and displays the API result; it does not claim to show the current assignment roster.</p>
        </div>
    </section>

    <div class="backdrop" id="warehouseFormBackdrop" role="dialog" aria-modal="true" aria-labelledby="warehouseFormTitle">
        <form class="modal" id="warehouseForm" novalidate>
            <div class="modal-head">
                <div>
                    <h2 id="warehouseFormTitle">Add warehouse</h2>
                    <p>Code and name are required. Changes are recorded in the audit log by the API.</p>
                </div>
                <button type="button" class="icon-btn" data-close-warehouse-form aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>

            <div class="modal-body">
                <div class="notice error" id="warehouseFormNotice"></div>
                <input type="hidden" id="warehouseId">
                <div class="grid">
                    <div class="form-field">
                        <label for="warehouseCode">Warehouse code *</label>
                        <input id="warehouseCode" maxlength="100" required autocomplete="off">
                        <div class="error-text" id="warehouseCodeError"></div>
                    </div>
                    <div class="form-field">
                        <label for="warehouseName">Warehouse name *</label>
                        <input id="warehouseName" maxlength="150" required autocomplete="organization">
                        <div class="error-text" id="warehouseNameError"></div>
                    </div>
                    <div class="form-field full">
                        <label for="warehouseAddress">Address</label>
                        <input id="warehouseAddress" maxlength="255" autocomplete="street-address">
                    </div>
                    <div class="form-field">
                        <label for="warehouseCity">City</label>
                        <input id="warehouseCity" maxlength="100" autocomplete="address-level2">
                    </div>
                    <div class="form-field">
                        <label for="warehouseState">State</label>
                        <input id="warehouseState" maxlength="100" autocomplete="address-level1">
                    </div>
                    <div class="form-field">
                        <label for="warehouseParent">Parent location</label>
                        <select id="warehouseParent">
                            <option value="">No parent (top-level warehouse)</option>
                        </select>
                        <div class="error-text" id="warehouseParentError"></div>
                    </div>
                    <div class="form-field">
                        <label for="warehouseStatus">Status</label>
                        <select id="warehouseStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn" data-close-warehouse-form>Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveWarehouseBtn">Save warehouse</button>
            </div>
        </form>
    </div>
</section>
