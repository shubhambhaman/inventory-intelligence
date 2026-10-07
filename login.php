<?php

declare(strict_types=1);

/**
 * Inventory Intelligence Platform
 *
 * Login page.
 *
 * Responsibilities:
 * - Start a secure browser session
 * - Generate CSRF token for login
 * - Redirect already-authenticated users
 */

require_once __DIR__ . '/config/csrf.php';

/**
 * Configure secure PHP session cookie.
 *
 * - HttpOnly prevents JavaScript from reading the session cookie.
 * - SameSite=Lax provides CSRF protection at cookie level.
 * - Secure is enabled automatically when HTTPS is active.
 */
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    ),
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

/**
 * Redirect already-authenticated users.
 */
if (
    !empty($_SESSION['authenticated']) &&
    !empty($_SESSION['user_id']) &&
    !empty($_SESSION['tenant_id'])
) {
    header('Location: dashboard.php');
    exit;
}

/**
 * Generate CSRF token for the login form.
 *
 * This token is stored server-side in the PHP session
 * and submitted back through X-CSRF-Token by auth.js.
 */
$csrfToken = csrf_token();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in · Inventory Intelligence Platform</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

  <link
    href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap"
    rel="stylesheet"
  >

  <link rel="stylesheet" href="css/auth.css">
</head>

<body>

<div class="shell">

  <!-- Dark branding panel -->
  <aside class="rail">

    <div class="brand">

      <svg
        width="32"
        height="32"
        viewBox="0 0 32 32"
        aria-hidden="true"
      >
        <rect
          x="2"
          y="2"
          width="12"
          height="12"
          rx="3"
          fill="#fff"
        />

        <rect
          x="18"
          y="2"
          width="12"
          height="12"
          rx="3"
          fill="#fff"
          opacity=".45"
        />

        <rect
          x="2"
          y="18"
          width="12"
          height="12"
          rx="3"
          fill="#fff"
          opacity=".45"
        />

        <rect
          x="18"
          y="18"
          width="12"
          height="12"
          rx="3"
          fill="#3fc1a8"
        />
      </svg>

      Inventory Intelligence Platform

    </div>

    <p>
      Sign in to your workspace to manage stock with confidence.
    </p>

    <small>
      &copy; <?php echo date('Y'); ?> Inventory Intelligence Platform
    </small>

  </aside>


  <!-- Main area -->
  <main class="stage">

    <section
      class="card"
      aria-labelledby="page-title"
    >

      <h1 id="page-title">
        Sign in
      </h1>

      <p class="sub">
        Use your work email to access your workspace.
      </p>


      <form
        class="auth-form"
        data-form="login"
        novalidate
      >

        <!--
          CSRF protection.

          The token is generated server-side by login.php
          and stored in the PHP session.
        -->
        <input
          type="hidden"
          name="csrf_token"
          value="<?php echo htmlspecialchars(
              $csrfToken,
              ENT_QUOTES,
              'UTF-8'
          ); ?>"
        >


        <!-- Error alert (shown by js/auth.js) -->
        <div
          class="alert"
          role="alert"
          hidden
        >
          <strong class="alert-title"></strong>
          <span class="alert-text"></span>
        </div>


        <div class="field">

          <label for="email">
            Work email
          </label>

          <input
            id="email"
            name="email"
            type="email"
            autocomplete="username"
            placeholder="name@company.com"
            data-rule="email"
            aria-describedby="email-error"
            required
          >

          <p
            class="field-error"
            id="email-error"
          ></p>

        </div>


        <div class="field">

          <label for="password">
            Password
          </label>

          <div class="control">

            <input
              id="password"
              name="password"
              type="password"
              autocomplete="current-password"
              placeholder="Enter your password"
              data-rule="loginPassword"
              aria-describedby="password-error"
              required
            >

            <button
              type="button"
              class="toggle"
              aria-label="Show password"
              aria-pressed="false"
            >

              <svg
                width="20"
                height="20"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >

                <g class="eye-on">

                  <path
                    d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"
                  />

                  <circle
                    cx="12"
                    cy="12"
                    r="3"
                  />

                </g>

                <path
                  class="eye-off"
                  d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4M6.5 6.5A17 17 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 4.2-.9M9.9 9.9a3 3 0 0 0 4.2 4.2"
                />

              </svg>

            </button>

          </div>

          <p
            class="field-error"
            id="password-error"
          ></p>

        </div>


        <div class="row">

          <label class="check">

            <input
              type="checkbox"
              name="remember"
            >

            Remember me

          </label>

          <a
            href="#"
            class="ui-only"
          >
            Forgot password?
          </a>

        </div>


        <button
          type="submit"
          class="btn"
        >

          <span
            class="spinner"
            aria-hidden="true"
          ></span>

          <span
            class="btn-label"
            data-default="Sign in"
            data-loading="Signing in…"
          >
            Sign in
          </span>

        </button>

      </form>


      <p class="switch">
        New to Inventory Intelligence Platform?
        <a href="signup.php">
          Create an account
        </a>
      </p>

    </section>

  </main>

</div>


<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="js/auth.js"></script>

</body>
</html>