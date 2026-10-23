<?php
$customersApiBase = $moduleApiBase ?? (($basePath ?? '') . '/api/v1/customers');
?>
<section class="module-view module-customers" data-module-view="customers" data-customers-api="<?= $e($customersApiBase) ?>">
    <div class="crumb">
        <a href="<?= $e(($basePath ?? '') . '/dashboard.php') ?>" data-module-link data-module="dashboard">Dashboard</a>
        <span aria-hidden="true">&nbsp;›&nbsp;</span>
        <span>Customers</span>
    </div>

    <section class="head">
        <div>
            <div class="eyebrow">Master data · CRM</div>
            <h1>Customers</h1>
            <p>Manage customer details and status. Deactivation preserves historical records.</p>
        </div>
        <button class="btn btn-primary" id="addBtn" type="button"><i class="bi bi-plus-lg"></i> Add customer</button>
    </section>

    <section class="stats" aria-label="Customer totals">
        <div class="stat">
            <span class="stat-icon"><i class="bi bi-people-fill"></i></span>
            <div><div class="label">Total customers</div><div class="value" id="totalCount">-</div></div>
        </div>
        <div class="stat">
            <span class="stat-icon"><i class="bi bi-person-check-fill"></i></span>
            <div><div class="label">Active customers</div><div class="value" id="activeCount">-</div></div>
        </div>
        <div class="stat">
            <span class="stat-icon"><i class="bi bi-person-dash-fill"></i></span>
            <div><div class="label">Inactive customers</div><div class="value" id="inactiveCount">-</div></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Customer directory</div>
                <div class="sub">Search, filter and manage customer records</div>
            </div>
            <button class="btn" id="refreshBtn" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>

        <div class="filters">
            <input class="field search" id="searchInput" type="search" placeholder="Search name, code, contact, phone or email..." maxlength="150" aria-label="Search customers">
            <select class="field" id="statusFilter" aria-label="Status">
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
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Email / Phone</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="rows">
                    <tr><td colspan="6"><div class="empty">Loading customers...</div></td></tr>
                </tbody>
            </table>
        </div>

        <div class="footer">
            <span id="rangeLabel">Loading...</span>
            <div class="pages" id="pages"></div>
        </div>
    </section>

    <div class="backdrop" id="formBackdrop" role="dialog" aria-modal="true" aria-labelledby="formTitle">
        <form class="modal" id="customerForm" novalidate>
            <div class="modal-head">
                <div>
                    <h2 id="formTitle">Add customer</h2>
                    <p>Customer name is required.</p>
                </div>
                <button type="button" class="icon-btn" data-close-form aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>

            <div class="modal-body">
                <div class="notice error" id="formNotice"></div>
                <input type="hidden" id="customerId">
                <div class="grid">
                    <div class="form-field full">
                        <label for="name">Customer name *</label>
                        <input id="name" maxlength="150" required autocomplete="organization">
                        <div class="error-text" id="nameError"></div>
                    </div>
                    <div class="form-field">
                        <label for="code">Customer code</label>
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
                    </div>
                    <div class="form-field">
                        <label for="phone">Phone</label>
                        <input id="phone" maxlength="50" autocomplete="tel">
                    </div>
                    <div class="form-field full">
                        <label for="email">Email</label>
                        <input id="email" type="email" maxlength="254" autocomplete="email">
                        <div class="error-text" id="emailError"></div>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn" data-close-form>Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveBtn">Save customer</button>
            </div>
        </form>
    </div>

    <div class="backdrop" id="deactivateBackdrop" role="dialog" aria-modal="true" aria-labelledby="deactivateTitle">
        <div class="modal">
            <div class="modal-head">
                <div>
                    <h2 id="deactivateTitle">Deactivate customer?</h2>
                    <p>Customer history will be preserved.</p>
                </div>
                <button type="button" class="icon-btn" data-close-deactivate aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="modal-body">
                <p class="confirm">You're about to deactivate <strong id="deactivateName"></strong>. It won't be available for new ordinary outgoing transactions, and existing history will not be deleted.</p>
                <div class="notice error" id="deactivateNotice"></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" data-close-deactivate>Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeactivate"><i class="bi bi-person-dash"></i> Deactivate</button>
            </div>
        </div>
    </div>
</section>
