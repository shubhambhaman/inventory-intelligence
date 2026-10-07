<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 * Dashboard
 *
 * Protected application shell.
 *
 * Authentication is verified server-side.
 * The frontend never decides whether a user is authenticated.
 */

require_once __DIR__ . '/config/csrf.php';

session_start();

/**
 * Basic authentication check.
 *
 * If there is no valid authenticated session,
 * send the user back to login.
 */
if (
    empty($_SESSION['authenticated']) ||
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header('Location: login.php');
    exit;
}
$csrfToken = csrf_token();

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">

<meta
    name="csrf-token"
    content="<?php echo htmlspecialchars(
        $csrfToken,
        ENT_QUOTES,
        'UTF-8'
    ); ?>"
>

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

    <title>Dashboard - Inventory Intelligence</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
            background: #f5f7fa;
            color: #172033;
        }

        .dashboard-shell {
            min-height: 100vh;
        }

        .topbar {
            height: 70px;
            background: #071832;
            color: #ffffff;

            display: flex;
            align-items: center;
            justify-content: space-between;

            padding: 0 32px;
        }

        .brand {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .logout-button {
            border: 1px solid rgba(255, 255, 255, 0.35);
            background: transparent;
            color: #ffffff;

            padding: 9px 18px;
            border-radius: 8px;

            cursor: pointer;
            font-size: 14px;
        }

        .logout-button:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .main {
            padding: 32px;
        }

        .welcome-card {
            background: #ffffff;
            border: 1px solid #e5e9f0;
            border-radius: 14px;

            padding: 28px;

            box-shadow:
                0 4px 18px rgba(15, 23, 42, 0.05);
        }

        .welcome-card h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        .welcome-card p {
            margin: 0;
            color: #64748b;
        }

        .context-grid {
            margin-top: 24px;

            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(220px, 1fr));

            gap: 16px;
        }

        .context-card {
            background: #ffffff;
            border: 1px solid #e5e9f0;
            border-radius: 12px;
            padding: 20px;
        }

        .context-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 8px;
        }

        .context-value {
            font-size: 17px;
            font-weight: 600;
            word-break: break-word;
        }

        .loading {
            color: #64748b;
        }

        .error {
            color: #b42318;
        }

        @media (max-width: 640px) {

            .topbar {
                padding: 0 18px;
            }

            .main {
                padding: 18px;
            }

            .brand {
                font-size: 17px;
            }
        }
    </style>

</head>

<body>

<div class="dashboard-shell">

    <header class="topbar">

        <div class="brand">
            Inventory Intelligence
        </div>

        <button
            type="button"
            id="logoutButton"
            class="logout-button"
        >
            Logout
        </button>

    </header>


    <main class="main">

        <section class="welcome-card">

            <h1>
                Welcome,
                <span id="userName">...</span>
            </h1>

            <p id="companyWelcome">
                Loading your company information...
            </p>

        </section>


        <section class="context-grid">

            <div class="context-card">

                <div class="context-label">
                    Company
                </div>

                <div
                    class="context-value loading"
                    id="companyName"
                >
                    Loading...
                </div>

            </div>


            <div class="context-card">

                <div class="context-label">
                    Legal Name
                </div>

                <div
                    class="context-value loading"
                    id="legalName"
                >
                    Loading...
                </div>

            </div>


            <div class="context-card">

                <div class="context-label">
                    Country
                </div>

                <div
                    class="context-value loading"
                    id="country"
                >
                    Loading...
                </div>

            </div>


            <div class="context-card">

                <div class="context-label">
                    Currency
                </div>

                <div
                    class="context-value loading"
                    id="currency"
                >
                    Loading...
                </div>

            </div>


            <div class="context-card">

                <div class="context-label">
                    Timezone
                </div>

                <div
                    class="context-value loading"
                    id="timezone"
                >
                    Loading...
                </div>

            </div>


            <div class="context-card">

                <div class="context-label">
                    Fiscal Year Start
                </div>

                <div
                    class="context-value loading"
                    id="fiscalYearStart"
                >
                    Loading...
                </div>

            </div>


            <div class="context-card">

                <div class="context-label">
                    Your Role
                </div>

                <div
                    class="context-value loading"
                    id="userRole"
                >
                    Loading...
                </div>

            </div>

        </section>

    </main>

</div>


<script>

(function () {

    'use strict';


    /**
     * Escape HTML before inserting API values
     * into the page.
     */
    function escapeHtml(value) {

        if (value === null || value === undefined) {
            return '';
        }

        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }


    /**
     * Set text safely.
     */
    function setText(id, value) {

        var element = document.getElementById(id);

        if (!element) {
            return;
        }

        element.textContent =
            value === null ||
            value === undefined ||
            value === ''
                ? '—'
                : value;

        element.classList.remove('loading');
    }


    /**
     * Load current authenticated context.
     */
    function loadCurrentUser() {

        fetch(
            'api/v1/auth/me.php',
            {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            }
        )
        .then(function (response) {

            if (response.status === 401) {

                window.location.href = 'login.php';

                return null;
            }

            if (!response.ok) {

                throw new Error(
                    'Unable to load account information.'
                );
            }

            return response.json();
        })
        .then(function (response) {

            if (!response) {
                return;
            }

            if (!response.success) {

                throw new Error(
                    response.error &&
                    response.error.message
                        ? response.error.message
                        : 'Unable to load account information.'
                );
            }

            var user =
                response.data &&
                response.data.user
                    ? response.data.user
                    : null;

            var company =
                response.data &&
                response.data.company
                    ? response.data.company
                    : null;


            if (!user || !company) {

                throw new Error(
                    'Invalid account context received.'
                );
            }


            setText(
                'userName',
                user.name
            );

            setText(
                'companyWelcome',
                'Company: ' + company.name
            );

            setText(
                'companyName',
                company.name
            );

            setText(
                'legalName',
                company.legal_name
            );

            setText(
                'country',
                company.country
            );

            setText(
                'currency',
                company.currency
            );

            setText(
                'timezone',
                company.timezone
            );

            setText(
                'fiscalYearStart',
                company.fiscal_year_start
            );

            setText(
                'userRole',
                user.role
            );

        })
        .catch(function (error) {

            console.error(
                'Dashboard context error:',
                error
            );

            document.getElementById(
                'companyWelcome'
            ).textContent =
                'Unable to load company information.';
        });
    }


    /**
     * Logout.
     */
    function logout() {

        var button =
            document.getElementById('logoutButton');

        if (button) {
            button.disabled = true;
            button.textContent = 'Signing out...';
        }

        fetch(
            'api/v1/auth/logout.php',
            {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': document.querySelector(
                        'meta[name="csrf-token"]'
                    ).getAttribute('content')
                }
            }
        )
        .then(function (response) {

            if (!response.ok) {
                throw new Error(
                    'Unable to sign out.'
                );
            }

            return response.json();
        })
        .then(function (response) {

            if (
                response &&
                response.success
            ) {
                window.location.href =
                    'login.php';

                return;
            }

            throw new Error(
                'Unable to sign out.'
            );
        })
        .catch(function (error) {

            console.error(
                'Logout error:',
                error
            );

            if (button) {
                button.disabled = false;
                button.textContent = 'Logout';
            }

            alert(
                'Unable to sign out. Please try again.'
            );
        });
    }


    /**
     * Logout button.
     */
    document
        .getElementById('logoutButton')
        .addEventListener(
            'click',
            logout
        );


    /**
     * Load dashboard context.
     */
    loadCurrentUser();

})();

</script>

</body>
</html>