<?php
$productsApiBase = $moduleApiBase ?? (($basePath ?? '') . '/api/v1/products');
$categoriesApiBase = ($basePath ?? '') . '/api/v1/categories';
$unitsApiBase = ($basePath ?? '') . '/api/v1/units';
?>
<section
    class="module-view module-products"
    data-module-view="products"
    data-products-api="<?= $e($productsApiBase) ?>"
    data-categories-api="<?= $e($categoriesApiBase) ?>"
    data-units-api="<?= $e($unitsApiBase) ?>"
>
    <div class="crumb">
        <a href="<?= $e(($basePath ?? '') . '/dashboard.php') ?>" data-module-link data-module="dashboard">Dashboard</a>
        <span aria-hidden="true">&nbsp;›&nbsp;</span>
        <span>Products</span>
    </div>

    <section class="head">
        <div>
            <div class="eyebrow">Inventory · Master data</div>
            <h1>Products &amp; SKUs</h1>
            <p>Manage SKU records, categories, units, pricing, and lifecycle status.</p>
        </div>
        <button class="btn btn-primary" id="addBtn" type="button"><i class="bi bi-plus-lg"></i> Add product</button>
    </section>

    <section class="stats" aria-label="Product totals">
        <div class="stat">
            <span class="stat-icon"><i class="bi bi-tags-fill"></i></span>
            <div><div class="label">Total products</div><div class="value" id="totalCount">-</div></div>
        </div>
        <div class="stat">
            <span class="stat-icon"><i class="bi bi-check2-circle"></i></span>
            <div><div class="label">Active products</div><div class="value" id="activeCount">-</div></div>
        </div>
        <div class="stat">
            <span class="stat-icon"><i class="bi bi-file-earmark"></i></span>
            <div><div class="label">Draft products</div><div class="value" id="draftCount">-</div></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Product catalog</div>
                <div class="sub">Search, filter, and maintain SKU metadata</div>
            </div>
            <button class="btn" id="refreshBtn" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>

        <div class="filters">
            <input class="field search" id="searchInput" type="search" placeholder="Search SKU, name, brand, or barcode..." maxlength="150" aria-label="Search products">
            <select class="field" id="statusFilter" aria-label="Status">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <select class="field" id="categoryFilter" aria-label="Category">
                <option value="">All categories</option>
            </select>
            <select class="field" id="perPage" aria-label="Rows per page">
                <option value="25">25 per page</option>
                <option value="50">50 per page</option>
                <option value="100">100 per page</option>
            </select>
        </div>

        <div class="notice" id="notice" role="status" aria-live="polite"></div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Unit</th>
                        <th>Pricing</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="rows">
                    <tr><td colspan="7"><div class="empty">Loading products...</div></td></tr>
                </tbody>
            </table>
        </div>

        <div class="footer">
            <span id="rangeLabel">Loading...</span>
            <div class="pages" id="pages"></div>
        </div>
    </section>

    <div class="backdrop" id="formBackdrop" role="dialog" aria-modal="true" aria-labelledby="formTitle">
        <form class="modal" id="productForm" novalidate>
            <div class="modal-head">
                <div>
                    <h2 id="formTitle">Add product</h2>
                    <p>SKU, product name, category, and unit are required.</p>
                </div>
                <button type="button" class="icon-btn" data-close-form aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>

            <div class="modal-body">
                <div class="notice error" id="formNotice"></div>
                <input type="hidden" id="productId">
                <div class="grid">
                    <div class="form-field">
                        <label for="sku">SKU / product code *</label>
                        <input id="sku" maxlength="150" required autocomplete="off">
                        <div class="error-text" id="skuError"></div>
                    </div>
                    <div class="form-field">
                        <label for="status">Status</label>
                        <select id="status">
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="form-field full">
                        <label for="name">Product name *</label>
                        <input id="name" maxlength="255" required autocomplete="off">
                        <div class="error-text" id="nameError"></div>
                    </div>
                    <div class="form-field">
                        <label for="category">Category *</label>
                        <select id="category" required>
                            <option value="">Select category</option>
                        </select>
                        <div class="error-text" id="categoryError"></div>
                    </div>
                    <div class="form-field">
                        <label for="unit">Unit *</label>
                        <select id="unit" required>
                            <option value="">Select unit</option>
                        </select>
                        <div class="error-text" id="unitError"></div>
                    </div>
                    <div class="form-field">
                        <label for="purchasePrice">Purchase price</label>
                        <input id="purchasePrice" inputmode="decimal" pattern="^\d+(\.\d+)?$" autocomplete="off">
                        <div class="error-text" id="purchasePriceError"></div>
                    </div>
                    <div class="form-field">
                        <label for="sellingPrice">Selling price</label>
                        <input id="sellingPrice" inputmode="decimal" pattern="^\d+(\.\d+)?$" autocomplete="off">
                        <div class="error-text" id="sellingPriceError"></div>
                    </div>
                    <div class="form-field">
                        <label for="taxRate">Tax rate</label>
                        <input id="taxRate" inputmode="decimal" pattern="^\d+(\.\d+)?$" autocomplete="off">
                        <div class="error-text" id="taxRateError"></div>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn" data-close-form>Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveBtn">Save product</button>
            </div>
        </form>
    </div>

    <div class="backdrop" id="deactivateBackdrop" role="dialog" aria-modal="true" aria-labelledby="deactivateTitle">
        <div class="modal">
            <div class="modal-head">
                <div>
                    <h2 id="deactivateTitle">Deactivate product?</h2>
                    <p>Historical references will be preserved.</p>
                </div>
                <button type="button" class="icon-btn" data-close-deactivate aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="modal-body">
                <p class="confirm">You're about to deactivate <strong id="deactivateName"></strong>. Existing history remains intact.</p>
                <div class="notice error" id="deactivateNotice"></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" data-close-deactivate>Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeactivate"><i class="bi bi-slash-circle"></i> Deactivate</button>
            </div>
        </div>
    </div>
</section>
