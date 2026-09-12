<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/otp.php';
start_secure_session($config);

if (current_user()) {
    header('Location: dashboard.php', true, 302);
    exit;
}

$pending = otp_pending();
$otpMode = $pending !== null;
$deliveryHint = $otpMode ? (string) ($pending['delivery_hint'] ?? 'your email') : '';

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow, noarchive');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow,noarchive" />
  <title>Secure sign in | Apex Business Consultancy</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <style>
    :root { --navy:#07152f; --green:#58c748; --mist:#eaf9fb; --line:#c9dfe2; --muted:#607881; --danger:#b42318; }
    * { box-sizing:border-box; }
    body { min-height:100vh; margin:0; display:grid; place-items:center; padding:24px; background:linear-gradient(135deg,#eefafb,#e1f3ed); color:var(--navy); font-family:Manrope,sans-serif; }
    main { width:min(100%,430px); padding:38px; background:#fff; border:1px solid var(--line); border-radius:8px; box-shadow:0 18px 48px rgba(7,21,47,.12); }
    .brand { display:inline-flex; align-items:center; gap:9px; color:var(--navy); font-weight:800; font-size:20px; text-decoration:none; }
    .mark { width:15px; height:15px; background:var(--green); transform:rotate(45deg); }
    h1 { margin:34px 0 8px; font-size:24px; }
    .intro { margin:0 0 28px; color:var(--muted); font-size:14px; line-height:1.6; }
    label { display:block; margin:0 0 7px; font-size:12px; font-weight:800; }
    input { width:100%; margin:0 0 18px; padding:12px 13px; border:1px solid var(--line); border-radius:5px; color:var(--navy); font:inherit; }
    input:focus { border-color:#397d89; outline:3px solid rgba(69,197,220,.18); }
    #otp { text-align:center; font-size:24px; letter-spacing:.3em; font-weight:800; }
    button { width:100%; border:0; border-radius:5px; padding:13px; background:var(--green); color:#fff; cursor:pointer; font:800 14px Manrope,sans-serif; }
    button.secondary { margin-top:10px; background:#eef5f6; color:var(--navy); border:1px solid var(--line); }
    button.link { margin-top:6px; background:transparent; color:#2f6670; padding:9px; }
    button:disabled { cursor:wait; opacity:.65; }
    #message,#otp-message { min-height:20px; margin:14px 0 0; color:var(--danger); font-size:13px; line-height:1.5; }
    .note { margin-top:15px; color:var(--muted); font-size:12px; line-height:1.55; }
    [hidden] { display:none !important; }
  </style>
</head>
<body>
<main>
  <a class="brand" href="/"><span class="mark" aria-hidden="true"></span>apex</a>

  <section id="password-step" <?= $otpMode ? 'hidden' : '' ?>>
    <h1>Sign in to the CMS</h1>
    <p class="intro">Step 1 of 2 — enter your authorized email address and password.</p>
    <form id="login-form">
      <label for="email">Email address</label>
      <input id="email" name="email" type="email" autocomplete="username" required />
      <label for="password">Password</label>
      <input id="password" name="password" type="password" minlength="8" autocomplete="current-password" required />
      <button type="submit">Continue securely</button>
      <p id="message" role="alert"></p>
    </form>
  </section>

  <section id="otp-step" <?= $otpMode ? '' : 'hidden' ?>>
    <h1>Verify your sign-in</h1>
    <p class="intro">Step 2 of 2 — enter the 6-digit code sent to <strong id="delivery-hint"><?= e($deliveryHint ?: 'your email') ?></strong>.</p>
    <form id="otp-form">
      <label for="otp">6-digit verification code</label>
      <input id="otp" name="otp" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required />
      <button type="submit">Verify &amp; open dashboard</button>
      <button type="button" class="secondary" id="resend-button">Send a new code</button>
      <button type="button" class="link" id="restart-button">Use a different account</button>
      <p id="otp-message" role="alert"></p>
      <p class="note">Never share this code. Apex staff should never ask you to send a login code by chat or phone.</p>
    </form>
  </section>
</main>
<script>
  const passwordStep = document.getElementById('password-step');
  const otpStep = document.getElementById('otp-step');
  const loginForm = document.getElementById('login-form');
  const otpForm = document.getElementById('otp-form');
  const message = document.getElementById('message');
  const otpMessage = document.getElementById('otp-message');
  const deliveryHint = document.getElementById('delivery-hint');
  const resendButton = document.getElementById('resend-button');
  const restartButton = document.getElementById('restart-button');

  async function apiJson(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const text = await response.text();
    let data = {};
    if (text) {
      try { data = JSON.parse(text); }
      catch (_) { throw new Error('The server returned an invalid response. Please try again.'); }
    }
    if (!response.ok) throw new Error(data.error || `Request failed (${response.status}).`);
    return data;
  }

  async function csrfToken() {
    const data = await apiJson('public/api/auth/csrf.php');
    if (!data.csrf_token) throw new Error('Unable to start a secure session. Refresh the page.');
    return data.csrf_token;
  }

  function showOtp(hint) {
    passwordStep.hidden = true;
    otpStep.hidden = false;
    if (hint) deliveryHint.textContent = hint;
    document.getElementById('otp').focus();
  }

  loginForm.addEventListener('submit', async event => {
    event.preventDefault();
    message.textContent = '';
    const button = loginForm.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const csrf = await csrfToken();
      const result = await apiJson('public/api/auth/login.php', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-CSRF-Token':csrf },
        body: JSON.stringify({ email: loginForm.email.value.trim(), password: loginForm.password.value })
      });
      loginForm.password.value = '';
      if (result.otp_required) {
        showOtp(result.delivery_hint);
      } else {
        location.assign('dashboard.php');
      }
    } catch (error) {
      message.textContent = error.message || 'Unable to sign in.';
    } finally {
      button.disabled = false;
    }
  });

  otpForm.addEventListener('submit', async event => {
    event.preventDefault();
    otpMessage.textContent = '';
    const button = otpForm.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const csrf = await csrfToken();
      await apiJson('public/api/auth/verify-otp.php', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-CSRF-Token':csrf },
        body: JSON.stringify({ code: otpForm.otp.value.trim() })
      });
      location.assign('dashboard.php');
    } catch (error) {
      otpMessage.textContent = error.message || 'Unable to verify the code.';
      otpForm.otp.select();
    } finally {
      button.disabled = false;
    }
  });

  resendButton.addEventListener('click', async () => {
    otpMessage.textContent = '';
    resendButton.disabled = true;
    try {
      const csrf = await csrfToken();
      const result = await apiJson('public/api/auth/resend-otp.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token':csrf }
      });
      if (result.delivery_hint) deliveryHint.textContent = result.delivery_hint;
      otpMessage.style.color = '#176b3a';
      otpMessage.textContent = 'A new verification code was sent.';
      setTimeout(() => { otpMessage.style.color = ''; }, 2500);
    } catch (error) {
      otpMessage.style.color = '';
      otpMessage.textContent = error.message || 'Unable to resend the code.';
    } finally {
      resendButton.disabled = false;
    }
  });

  restartButton.addEventListener('click', () => {
    // A fresh password submission replaces any pending OTP session.
    otpStep.hidden = true;
    passwordStep.hidden = false;
    otpForm.reset();
    loginForm.email.focus();
  });
</script>
</body>
</html>
