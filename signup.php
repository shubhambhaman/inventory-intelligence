<?php

session_start();

require_once __DIR__ . '/config/csrf.php';

if (
    !empty($_SESSION['authenticated']) &&
    !empty($_SESSION['user_id']) &&
    !empty($_SESSION['tenant_id'])
) {
    header('Location: dashboard.php');
    exit;
}

/**
 * Generate the CSRF token for this signup session.
 */
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create account · Inventory Intelligence Platform</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/auth.css">
</head>
<body>
<div class="shell">

  <aside class="rail">
    <div class="brand">
      <svg width="32" height="32" viewBox="0 0 32 32" aria-hidden="true">
        <rect x="2" y="2" width="12" height="12" rx="3" fill="#fff"/>
        <rect x="18" y="2" width="12" height="12" rx="3" fill="#fff" opacity=".45"/>
        <rect x="2" y="18" width="12" height="12" rx="3" fill="#fff" opacity=".45"/>
        <rect x="18" y="18" width="12" height="12" rx="3" fill="#3fc1a8"/>
      </svg>
      Inventory Intelligence Platform
    </div>

    <p>Create an account to start managing stock with confidence.</p>

    <small>
      &copy; <?php echo date('Y'); ?> Inventory Intelligence Platform
    </small>
  </aside>

  <main class="stage">
    <section class="card" aria-labelledby="page-title">

      <h1 id="page-title">Create your account</h1>

      <p class="sub">
        Enter your details to get started.
      </p>

      <form class="auth-form" data-form="signup" novalidate>

        <!-- CSRF protection -->
        <input
          type="hidden"
          name="csrf_token"
          value="<?php echo htmlspecialchars(
              $csrfToken,
              ENT_QUOTES,
              'UTF-8'
          ); ?>"
        >

        <div class="alert" role="alert" hidden>
          <strong class="alert-title"></strong>
          <span class="alert-text"></span>
        </div>

        <div class="field">
          <label for="fullName">Full name</label>

          <input
            id="fullName"
            name="fullName"
            type="text"
            autocomplete="name"
            placeholder="Your full name"
            data-rule="name"
            aria-describedby="fullName-error"
            required
          >

          <p class="field-error" id="fullName-error"></p>
        </div>

        <div class="field">
          <label for="companyName">
            Company / Workspace name
          </label>

          <input
            id="companyName"
            name="companyName"
            type="text"
            autocomplete="organization"
            placeholder="Your company name"
            data-rule="companyName"
            aria-describedby="companyName-error"
            required
          >

          <p
            class="field-error"
            id="companyName-error"
          ></p>
        </div>

        <div class="field">
          <label for="email">Work email</label>

          <input
            id="email"
            name="email"
            type="email"
            autocomplete="email"
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
          <label for="password">Password</label>

          <div class="control">

            <input
              id="password"
              name="password"
              type="password"
              autocomplete="new-password"
              placeholder="Create a password"
              data-rule="password"
              aria-describedby="password-hint password-error"
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
                  <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
                  <circle cx="12" cy="12" r="3"/>
                </g>

                <path
                  class="eye-off"
                  d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4M6.5 6.5A17 17 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 4.2-.9M9.9 9.9a3 3 0 0 0 4.2 4.2"
                />
              </svg>
            </button>

          </div>

          <p class="hint" id="password-hint">
            Use at least 8 characters.
          </p>

          <p
            class="field-error"
            id="password-error"
          ></p>
        </div>

        <div class="field">
          <label for="confirmPassword">
            Confirm password
          </label>

          <div class="control">

            <input
              id="confirmPassword"
              name="confirmPassword"
              type="password"
              autocomplete="new-password"
              placeholder="Re-enter your password"
              data-rule="confirm"
              aria-describedby="confirmPassword-error"
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
                  <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
                  <circle cx="12" cy="12" r="3"/>
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
            id="confirmPassword-error"
          ></p>
        </div>

        <div class="field">

          <label class="check">

            <input
              type="checkbox"
              name="terms"
              data-rule="terms"
              aria-describedby="terms-error"
            >

            <span>
              I agree to the
              <a href="#" class="ui-only">
                Terms &amp; Conditions
              </a>
              and
              <a href="#" class="ui-only">
                Privacy Policy
              </a>.
            </span>

          </label>

          <p
            class="field-error"
            id="terms-error"
          ></p>

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
            data-default="Create account"
            data-loading="Creating account…"
          >
            Create account
          </span>
        </button>

      </form>

      <p class="switch">
        Already have an account?
        <a href="login.php">Sign in</a>
      </p>

    </section>
  </main>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="js/auth.js"></script>

</body>
</html>