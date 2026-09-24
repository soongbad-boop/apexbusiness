# Production Deployment Guide: cPanel & GitHub Auto-Deploy

This guide outlines how to deploy **Apex Business Consultancy** to cPanel hosting using either **Option A: GitHub Auto-Deploy (Git Version Control)** or **Option B: Direct cPanel Upload (File Manager)**.

---

## 1. Environment Setup

Create a `.env` file in your production root directory (e.g. `/home/username/public_html/.env`) based on `.env.production.example`. The deployment packages intentionally exclude `.env`, so this file must be created directly on the server and retained during updates:

```dotenv
# Application Settings
APP_NAME="Apex Business Consultancy"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://apexbusinessksa.com

# Database Connection (from cPanel MySQL Database Wizard)
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=cpaneluser_apex_business
DB_USER=cpaneluser_apex_admin
DB_PASSWORD=YourSecureDatabasePasswordHere

# Session Configuration
SESSION_NAME=apex_production_session
SESSION_SECURE=1
SESSION_LIFETIME=7200
TRUSTED_PROXY_IPS=

# Media Uploads & Storage
UPLOAD_DIRECTORY=/home/username/public_html/storage/media
UPLOAD_PUBLIC_PATH=/storage/media
UPLOAD_MAX_BYTES=10485760

# Mail / SMTP Configuration
MAIL_MAILER=smtp
MAIL_HOST=mail.apexbusinessksa.com
MAIL_PORT=465
MAIL_USERNAME=info@apexbusinessksa.com
MAIL_PASSWORD=YourEmailPasswordHere
MAIL_ENCRYPTION=ssl
MAIL_TIMEOUT=20
MAIL_FROM_ADDRESS=info@apexbusinessksa.com
MAIL_FROM_NAME="Apex Business Consultancy"
MAIL_TO_ADDRESS=info@apexbusinessksa.com
```

> **Security Rule**: Never commit `.env` to GitHub or make it accessible publicly. `.htaccess` automatically denies public access to `.env*` files.
> Leave `TRUSTED_PROXY_IPS` blank unless your site is behind a known reverse proxy or load balancer; if used, list only the proxy IPs that are allowed to send trusted `X-Forwarded-*` headers.

### cPanel mailbox and SMTP values

1. In cPanel, open **Email Accounts** and create the mailbox used by `MAIL_USERNAME` (for example, `info@apexbusinessksa.com`). Set a strong mailbox password. The username must be the full email address; it is not necessarily the cPanel login password.
2. Open **Email Accounts → Connect Devices** for that mailbox. Use the outgoing SMTP hostname shown there, not a guessed hostname. Common settings are port `465` with `MAIL_ENCRYPTION=ssl`, or port `587` with `MAIL_ENCRYPTION=tls` (STARTTLS). Confirm the host, port, and encryption from the cPanel panel or hosting provider.
3. Set `MAIL_FROM_ADDRESS` to the authenticated mailbox or another sender explicitly authorized by the SMTP provider. Set `MAIL_TO_ADDRESS` to the admin inbox. Do not put the visitor's address in From; replies go to the validated visitor address using `Reply-To`.
4. Set the real SMTP username/password in `.env`; do not copy the example placeholders. Keep `APP_DEBUG=false` in production. The app logs failed delivery details to the PHP/server error log and does not log the SMTP password.
5. Ensure the hosting plan permits outbound SMTP to the configured host and port. If it blocks outbound SMTP, ask the host to allow its own cPanel SMTP server or use an approved transactional provider and its SMTP credentials. No PHP `mail()` fallback is used.

### SPF, DKIM, and DMARC

In cPanel, open **Email Deliverability** and select **Manage** for the sending domain. Install the exact SPF and DKIM records it recommends at the domain's authoritative DNS provider. DNS values differ by host; do not use generic values from another cPanel account. Keep only one SPF TXT record for the domain and merge authorized senders as directed by the DNS provider. If DNS is external, copy the records into that provider's DNS zone.

Publish a DMARC TXT record at `_dmarc` (or `_dmarc.apexbusinessksa.com`) after confirming the reporting mailbox exists. A cautious monitoring policy is `v=DMARC1; p=none; rua=mailto:dmarc@apexbusinessksa.com; adkim=r; aspf=r; pct=100`. Review aggregate reports, then move to `quarantine`/`reject` when all legitimate senders pass SPF or DKIM alignment. Allow DNS time to propagate. SPF/DKIM/DMARC improve domain authentication but cannot guarantee inbox placement; also check spam/quarantine and provider reputation.

### PHPMailer dependency

Contact submissions use PHPMailer over authenticated SMTP. Do not remove `composer.json`, `composer.lock`, or `vendor/`. The direct-upload packaging scripts run `composer install --no-dev` before creating the zip. Git deployment installs production dependencies through Composer on cPanel, so Composer must be available to the deployment task.

---

## 2. Database Setup

1. Log in to your **cPanel** account.
2. Open **MySQL Database Wizard**:
   - Create Database (e.g., `username_apex_business`).
   - Create User (e.g., `username_apex_admin`) with a strong password.
   - Assign User to Database with **ALL PRIVILEGES**.
3. Import Initial Schema & Seed Data:
   - **Option 1 (phpMyAdmin)**: Open phpMyAdmin, select your database, and import `database/mysql-schema.sql`.
   - **Option 2 (Terminal / Script)**: Run `php deploy.php` via cPanel Terminal.

---

## 3. Directory Structure & Permissions

Ensure proper permissions on your server:

```bash
# Standard permissions
chmod 755 /home/username/public_html
chmod 644 /home/username/public_html/.htaccess
chmod 600 /home/username/public_html/.env

# Writable storage folders
chmod -R 755 /home/username/public_html/storage
chmod -R 755 /home/username/public_html/storage/media
chmod -R 755 /home/username/public_html/storage/logs
```

---

## 4. Option A: GitHub Auto-Deploy (Recommended)

### Step 1: Push Repository to GitHub
Push your repository to GitHub (ensure `.env` is ignored by `.gitignore`).

### Step 2: Configure cPanel Git Version Control
1. In cPanel, navigate to **Git™ Version Control** (under Files).
2. Click **Create**.
3. Toggle **Clone a Repository**.
4. Enter Clone URL: `git@github.com:OWNER/REPOSITORY.git` (preferred) or the equivalent HTTPS URL for the repository you are deploying.
5. Set Repository Path to a non-public working tree such as `/home/username/repositories/Apexbusiness`
6. Set Repository Name: `Apexbusiness`
7. Click **Create**.

### Step 3: Verify `.cpanel.yml`
The project root includes `.cpanel.yml`:
```yaml
---
deployment:
  tasks:
    - export DEPLOYPATH=/home/username/public_html
    - /usr/bin/git archive --format=tar HEAD | /bin/tar -x -C $DEPLOYPATH
    - command -v php >/dev/null 2>&1 || { echo "php CLI is required for deployment" >&2; exit 1; }; php $DEPLOYPATH/deploy.php
    - /bin/rm -f $DEPLOYPATH/database/create-admin.php
```
This flow preserves untracked files already present in production, such as `.env` and uploaded media, while still refreshing tracked application files from Git. Adjust `DEPLOYPATH` if the site is deployed to an addon-domain document root instead of `public_html`.

### Step 4: Deploy
Click **Manage** in cPanel Git Version Control → Go to the **Pull or Deploy** tab → Click **Update from Remote** first, then **Deploy HEAD Commit**.

*(Optional Webhook)*: In GitHub repository settings → Webhooks, add `https://cpanel.yourdomain.com:2083/cpsess.../git/deploy.cgi` to trigger automatic deploys upon push.

---

## 5. Option B: Direct cPanel Upload (File Manager)

### Step 1: Generate Clean Production Zip
Run the included packaging script locally:
- **Windows (PowerShell)**:
  ```powershell
  .\scripts\package-cpanel.ps1
  ```
- **Linux / macOS (Bash)**:
  ```bash
  chmod +x scripts/package-cpanel.sh
  ./scripts/package-cpanel.sh
  ```
This generates `apex-production-deploy.zip` excluding `.git`, `.env`, and temporary files.
Composer must be installed on the local packaging machine; the package includes the locked PHPMailer production dependency.

### Step 2: Upload & Extract via File Manager
1. In cPanel, open **File Manager**.
2. Navigate to `public_html` (or your domain directory).
3. Click **Upload** and upload `apex-production-deploy.zip`.
4. Right-click the uploaded `.zip` file → Select **Extract** → Extract to `public_html/`.
5. Create or upload your production `.env` file.
6. Confirm `vendor/autoload.php` is present in `public_html` after extracting the package; do not upload `.env` inside the zip.

### Step 3: Run Post-Deploy Script in cPanel Terminal
In cPanel **Terminal** (or SSH):
```bash
cd ~/public_html
php deploy.php
```
If you use Git Version Control deployment with the bundled `.cpanel.yml`, this command is already attempted automatically during deploy.

---

## 6. Post-Deployment Verification & Testing Checklist

- [ ] **Homepage Loads**: Visit `https://apexbusinessksa.com` — verify styles, fonts, and assets load via HTTPS (`200 OK`).
- [ ] **Favicon**: Verify `favicon.svg` appears on all browser tabs.
- [ ] **Dedicated Routing**:
  - `https://apexbusinessksa.com/conversation`
  - `https://apexbusinessksa.com/visa-assistance`
  - `https://apexbusinessksa.com/automation`
  - `https://apexbusinessksa.com/advisory-session`
  - `https://apexbusinessksa.com/advisors`
- [ ] **CMS Dashboard**: Access `https://apexbusinessksa.com/dashboard` (or `dashboard.php`) — test branding controls, article editor, and settings synchronization.
- [ ] **PHP API Endpoints**:
  - `GET /api/settings.php` → Returns production JSON settings.
  - `POST /api/auth/login.php` → Authenticates authorized users.
- [ ] **Media Uploads**: Upload a sample image or logo in CMS → Verify it saves to `storage/media/` and displays correctly.
- [ ] **SEO Meta Tags & Social Share**: Inspect `<title>`, `<meta name="description">`, Open Graph, and Twitter tags on home and blog pages.
- [ ] **Debug Isolation**: Verify that `APP_DEBUG=false` hides sensitive stack traces and error details from end users.
- [ ] **Mail Delivery**: Submit each contact/request form using a real test address; verify the admin receives one notification, the visitor address is the Reply-To, and failures appear in cPanel **Metrics → Errors** or the account's PHP error log. A successful API response means the configured SMTP server accepted the message, not that the recipient's inbox placement is guaranteed.
- [ ] **SMTP Failure**: Temporarily use an invalid SMTP port in a staging environment, submit once, verify the form shows a failure rather than success, then restore the working setting. Never test with production credentials in a public debug configuration.
