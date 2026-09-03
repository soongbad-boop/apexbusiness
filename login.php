<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
start_secure_session($config);

if (current_user()) {
    header('Location: dashboard.php', true, 302);
    exit;
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex,nofollow,noarchive" />
    <title>Sign in | Apex Business Consultancy</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <style>
      :root { --navy: #07152f; --green: #58c748; --mist: #eaf9fb; --line: #c9dfe2; --muted: #607881; }
      * { box-sizing: border-box; }
      body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; background: linear-gradient(135deg, #eefafb, #e1f3ed); color: var(--navy); font-family: Manrope, sans-serif; }
      main { width: min(100%, 420px); padding: 38px; background: #fff; border: 1px solid var(--line); border-radius: 8px; box-shadow: 0 18px 48px rgba(7, 21, 47, .12); }
      .brand { display: inline-flex; align-items: center; gap: 9px; color: var(--navy); font-weight: 800; font-size: 20px; text-decoration: none; }
      .mark { width: 15px; height: 15px; background: var(--green); transform: rotate(45deg); }
      h1 { margin: 34px 0 8px; font-size: 24px; letter-spacing: 0; }
      p { margin: 0 0 28px; color: var(--muted); font-size: 14px; line-height: 1.6; }
      label { display: block; margin: 0 0 7px; font-size: 12px; font-weight: 800; }
      input { width: 100%; margin: 0 0 18px; padding: 12px 13px; border: 1px solid var(--line); border-radius: 5px; color: var(--navy); font: inherit; }
      input:focus { border-color: #397d89; outline: 3px solid rgba(69, 197, 220, .18); }
      button { width: 100%; border: 0; border-radius: 5px; padding: 13px; background: var(--green); color: #fff; cursor: pointer; font: 800 14px Manrope, sans-serif; }
      button:disabled { cursor: wait; opacity: .7; }
      #message { min-height: 20px; margin: 14px 0 0; color: #b42318; font-size: 13px; }
    </style>
  </head>
  <body>
    <main>
      <a class="brand" href="/"><span class="mark" aria-hidden="true"></span>apex</a>
      <h1>Sign in to the CMS</h1>
      <p>Use the authorized email address and password for your Apex account.</p>
      <form id="login-form">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" autocomplete="username" required />
        <label for="password">Password</label>
        <input id="password" name="password" type="password" minlength="8" autocomplete="current-password" required />
        <button type="submit">Sign in</button>
        <p id="message" role="alert"></p>
      </form>
    </main>
    <script>
      const form = document.getElementById('login-form');
      const message = document.getElementById('message');
      const submitButton = form.querySelector('button');

      form.addEventListener('submit', async event => {
        event.preventDefault();
        message.textContent = '';
        submitButton.disabled = true;
        try {
          const csrfResponse = await fetch('public/api/auth/csrf.php', { credentials: 'same-origin' });
          const csrf = await csrfResponse.json();
          const response = await fetch('public/api/auth/login.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.csrf_token },
            body: JSON.stringify({ email: form.email.value.trim(), password: form.password.value })
          });
          const result = await response.json();
          if (!response.ok) throw new Error(result.error || 'Unable to sign in.');
          location.assign('dashboard.php');
        } catch (error) {
          message.textContent = error.message || 'Unable to sign in. Please try again.';
        } finally {
          submitButton.disabled = false;
        }
      });
    </script>
  </body>
</html>