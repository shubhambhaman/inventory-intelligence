<?php
$suppliersApiBase = $moduleApiBase ?? (($basePath ?? '') . '/api/v1/suppliers');
?>
<section class="module-view module-suppliers" data-module-view="suppliers" data-suppliers-api="<?= $e($suppliersApiBase) ?>">
    <div class="crumb">
        <a href="<?= $e(($basePath ?? '') . '/dashboard.php') ?>" data-module-link data-module="dashboard">Dashboard</a>
        <span aria-hidden="true">&nbsp;›&nbsp;</span>
        <span>Suppliers</span>
    </div>

    <section class="head">
        <div>
            <div class="eyebrow">Inventory · Master data</div>
            <h1>Suppliers</h1>
            <p>Manage supplier contacts, replenishment lead times, payment terms, and status.</p>
        </div>
        <button class="btn btn-primary" id="addBtn" type="button"><i class="bi bi-plus-lg"></i> Add supplier</button>
    </section>

    <section class="stats" aria-label="Supplier totals">
        <div class="stat">
            <span class="stat-icon"><i class="bi bi-truck"></i></span>
            <div><div class="label">Total suppliers</div><div class="value" id="totalCount">-</div></div>
        </div>
        <div class="stat">
            <span class="stat-icon"><i class="bi bi-check2-circle"></i></span>
            <div><div class="label">Active suppliers</div><div class="value" id="activeCount">-</div></div>
        </div>
        <div class="stat">
            <span class="stat-icon"><i class="bi bi-slash-circle"></i></span>
            <div><div class="label">Inactive suppliers</div><div class="value" id="inactiveCount">-</div></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Supplier directory</div>
                <div class="sub">Search, filter, and maintain supplier records</div>
            </div>
            <button class="btn" id="refreshBtn" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>

        <div class="filters">
            <input class="field search" id="searchInput" type="search" placeholder="Search supplier, code, contact, phone or email..." maxlength="150" aria-label="Search suppliers">
            <select class="field" id="statusFilter" aria-label="Supplier status">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <select class="field" id="perPage" aria-label="Rows per page">
                <option value="20">20 per page</option>
                <option value="50">50 per page</option>
                <option value="100">100 per page</option>
            </select>
        </div>

        <div class="notice" id="notice" role="status" aria-live="polite"></div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Contact</th>
                        <th>Email / Phone</th>
                        <th>Lead time</th>
                        <th>Payment terms</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="rows">
                    <tr><td colspan="8"><div class="empty">Loading suppliers...</div></td></tr>
                </tbody>
            </table>
        </div>

        <div class="footer">
            <span id="rangeLabel">Loading...</span>
            <div class="pages" id="pages"></div>
        </div>
    </section>

    <div class="backdrop" id="formBackdrop" role="dialog" aria-modal="true" aria-labelledby="formTitle">
        <form class="modal" id="supplierForm" novalidate>
            <div class="modal-head">
                <div>
                    <h2 id="formTitle">Add supplier</h2>
                    <p>Supplier name is required. Other details are optional.</p>
                </div>
                <button type="button" class="icon-btn" data-close-form aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>

            <div class="modal-body">
                <div class="notice error" id="formNotice" role="alert"></div>
                <input type="hidden" id="supplierId">
                <div class="grid">
                    <div class="form-field full">
                        <label for="name">Supplier name *</label>
                        <input id="name" maxlength="150" required autocomplete="organization">
                        <div class="error-text" id="nameError"></div>
                    </div>
                    <div class="form-field">
                        <label for="code">Supplier code</label>
                        <input id="code" maxlength="100" autocomplete="off">
                        <div class="error-text" id="codeError"></div>
                    </div>
                    <div class="form-field">
                        <label for="status">Status</label>
                        <select id="status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    </div>
                    <div class="form-field">
                        <label for="contact">Contact person</label>
                        <input id="contact" maxlength="150" autocomplete="name">
                        <div class="error-text" id="contactError"></div>
                    </div>
                    <div class="form-field">
                        <label for="phone">Phone</label>
                        <input id="phone" maxlength="50" autocomplete="tel">
                        <div class="error-text" id="phoneError"></div>
                    </div>
                    <div class="form-field full">
                        <label for="email">Email</label>
                        <input id="email" type="email" maxlength="254" autocomplete="email">
                        <div class="error-text" id="emailError"></div>
                    </div>
                    <div class="form-field">
                        <label for="leadTime">Lead time (days)</label>
                        <input id="leadTime" type="number" min="0" step="0.01" inputmode="decimal" placeholder="e.g. 3.5">
                        <div class="error-text" id="leadTimeError"></div>
                    </div>
                    <div class="form-field">
                        <label for="paymentTerms">Payment terms (days)</label>
                        <input id="paymentTerms" type="number" min="0" step="1" inputmode="numeric" placeholder="e.g. 30">
                        <div class="error-text" id="paymentTermsError"></div>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn" data-close-form>Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveBtn">Save supplier</button>
            </div>
        </form>
    </div>

    <div class="backdrop" id="deactivateBackdrop" role="dialog" aria-modal="true" aria-labelledby="deactivateTitle">
        <div class="modal modal-confirm">
            <div class="modal-head">
                <div>
                    <h2 id="deactivateTitle">Deactivate supplier?</h2>
                    <p>The supplier record and historical references will be preserved.</p>
                </div>
                <button type="button" class="icon-btn" data-close-deactivate aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="modal-body">
                <p class="confirm">You're about to mark <strong id="deactivateName"></strong> as inactive. You can reactivate it later by editing the supplier.</p>
                <div class="notice error" id="deactivateNotice" role="alert"></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" data-close-deactivate>Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeactivate"><i class="bi bi-slash-circle"></i> Deactivate</button>
            </div>
        </div>
    </div>
</section>
