
<?php
$categoriesApiBase = ($basePath ?? '') . '/api/v1/categories';
?>

<section
    class="module-view module-categories"
    data-module-view="categories"
    data-categories-api="<?= $e($categoriesApiBase) ?>"
>
    <div class="crumb">
        <a
            href="<?= $e(($basePath ?? '') . '/dashboard.php') ?>"
            data-module-link
            data-module="dashboard"
        >Dashboard</a>
        <span aria-hidden="true">&nbsp;›&nbsp;</span>
        <span>Categories</span>
    </div>

    <section class="head">
        <div>
            <div class="eyebrow">Inventory · Master data</div>
            <h1>Categories</h1>
            <p>Organize products into categories and subcategories.</p>
        </div>

        <button class="btn btn-primary" id="categoryAddBtn" type="button">
            <i class="bi bi-plus-lg"></i> Add category
        </button>
    </section>

    <section class="stats" aria-label="Category totals">
        <div class="stat">
            <span class="stat-icon">
                <i class="bi bi-tags-fill"></i>
            </span>
            <div>
                <div class="label">Total categories</div>
                <div class="value" id="categoryTotalCount">-</div>
            </div>
        </div>

        <div class="stat">
            <span class="stat-icon">
                <i class="bi bi-check2-circle"></i>
            </span>
            <div>
                <div class="label">Active</div>
                <div class="value" id="categoryActiveCount">-</div>
            </div>
        </div>

        <div class="stat">
            <span class="stat-icon">
                <i class="bi bi-slash-circle"></i>
            </span>
            <div>
                <div class="label">Inactive</div>
                <div class="value" id="categoryInactiveCount">-</div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Category catalog</div>
                <div class="sub">Search, filter and manage category records</div>
            </div>

            <button class="btn" id="categoryRefreshBtn" type="button">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>

        <div class="filters">
            <input
                class="field search"
                id="categorySearchInput"
                type="search"
                placeholder="Search categories..."
                maxlength="150"
                aria-label="Search categories"
            >

            <select
                class="field"
                id="categoryStatusFilter"
                aria-label="Filter by status"
            >
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>

            <select
                class="field"
                id="categoryPerPage"
                aria-label="Rows per page"
            >
                <option value="25">25 per page</option>
                <option value="50">50 per page</option>
                <option value="100">100 per page</option>
            </select>
        </div>

        <div
            class="notice"
            id="categoryNotice"
            role="status"
            aria-live="polite"
        ></div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Parent category</th>
                        <th>Status</th>
                        <th>Last updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody id="categoryRows">
                    <tr>
                        <td colspan="5">
                            <div class="empty">Loading categories...</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="footer">
            <span id="categoryRangeLabel">Loading...</span>
            <div class="pages" id="categoryPages"></div>
        </div>
    </section>

    <!-- Add / Edit Category -->
    <div
        class="backdrop"
        id="categoryFormBackdrop"
        role="dialog"
        aria-modal="true"
        aria-labelledby="categoryFormTitle"
    >
        <form class="modal" id="categoryForm" novalidate>
            <div class="modal-head">
                <div>
                    <h2 id="categoryFormTitle">Add category</h2>
                    <p>Create a category or update its details.</p>
                </div>

                <button
                    type="button"
                    class="icon-btn"
                    data-category-close-form
                    aria-label="Close"
                >
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="modal-body">
                <div
                    class="notice error"
                    id="categoryFormNotice"
                    role="alert"
                ></div>

                <input type="hidden" id="categoryId">

                <div class="grid">
                    <div class="form-field full">
                        <label for="categoryName">Category name *</label>
                        <input
                            id="categoryName"
                            maxlength="150"
                            required
                            autocomplete="off"
                        >
                        <div class="error-text" id="categoryNameError"></div>
                    </div>

                    <div class="form-field full">
                        <label for="categoryParent">Parent category</label>
                        <select id="categoryParent">
                            <option value="">No parent category</option>
                        </select>
                        <div class="error-text" id="categoryParentError"></div>
                    </div>

                    <div class="form-field full">
                        <label for="categoryFormStatus">Status</label>
                        <select id="categoryFormStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <div class="error-text" id="categoryStatusError"></div>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button
                    type="button"
                    class="btn"
                    data-category-close-form
                >Cancel</button>

                <button
                    type="submit"
                    class="btn btn-primary"
                    id="categorySaveBtn"
                >Save category</button>
            </div>
        </form>
    </div>

    <!-- Deactivate confirmation -->
    <div
        class="backdrop"
        id="categoryDeactivateBackdrop"
        role="dialog"
        aria-modal="true"
        aria-labelledby="categoryDeactivateTitle"
    >
        <div class="modal">
            <div class="modal-head">
                <div>
                    <h2 id="categoryDeactivateTitle">Deactivate category?</h2>
                    <p>The category record will be preserved.</p>
                </div>

                <button
                    type="button"
                    class="icon-btn"
                    data-category-close-deactivate
                    aria-label="Close"
                >
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="modal-body">
                <p class="confirm">
                    You're about to deactivate
                    <strong id="categoryDeactivateName"></strong>.
                    This will not permanently delete the category.
                </p>

                <div
                    class="notice error"
                    id="categoryDeactivateNotice"
                    role="alert"
                ></div>
            </div>

            <div class="modal-foot">
                <button
                    type="button"
                    class="btn"
                    data-category-close-deactivate
                >Cancel</button>

                <button
                    type="button"
                    class="btn btn-danger"
                    id="categoryConfirmDeactivate"
                >
                    <i class="bi bi-slash-circle"></i> Deactivate
                </button>
            </div>
        </div>
    </div>
</section>
