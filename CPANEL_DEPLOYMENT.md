# Production Deployment Guide: cPanel & GitHub Auto-Deploy

This guide outlines how to deploy **Apex Business Consultancy** to cPanel hosting using either **Option A: GitHub Auto-Deploy (Git Version Control)** or **Option B: Direct cPanel Upload (File Manager)**.

---

## 1. Environment Setup

Create a `.env` file in your production root directory (e.g. `/home/username/public_html/.env`) based on `.env.production.example`:

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
MAIL_FROM_ADDRESS=info@apexbusinessksa.com
MAIL_FROM_NAME="Apex Business Consultancy"
MAIL_TO_ADDRESS=info@apexbusinessksa.com
```

> **Security Rule**: Never commit `.env` to GitHub or make it accessible publicly. `.htaccess` automatically denies public access to `.env*` files.

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
4. Enter Clone URL: `https://github.com/your-org/your-repo.git` (or SSH `git@github.com:...`).
5. Set Repository Path: `/home/username/repositories/apexco`
6. Set Repository Name: `apexco`
7. Click **Create**.

### Step 3: Verify `.cpanel.yml`
The project root includes `.cpanel.yml`:
```yaml
---
deployment:
  tasks:
    - export DEPLOYPATH=/home/username/public_html
    - /bin/cp -R * $DEPLOYPATH
    - /bin/chmod 755 $DEPLOYPATH
    - /bin/chmod 644 $DEPLOYPATH/.htaccess
    - /bin/chmod -R 755 $DEPLOYPATH/storage
```
*(Make sure to adjust `/home/username/public_html` to your exact cPanel path if using an addon domain or subfolder).*

### Step 4: Deploy
Click **Manage** in cPanel Git Version Control → Go to the **Pull or Deploy** tab → Click **Deploy HEAD Commit**.

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

### Step 2: Upload & Extract via File Manager
1. In cPanel, open **File Manager**.
2. Navigate to `public_html` (or your domain directory).
3. Click **Upload** and upload `apex-production-deploy.zip`.
4. Right-click the uploaded `.zip` file → Select **Extract** → Extract to `public_html/`.
5. Create or upload your production `.env` file.

### Step 3: Run Post-Deploy Script in cPanel Terminal
In cPanel **Terminal** (or SSH):
```bash
cd ~/public_html
php deploy.php
```

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
- [ ] **CMS Dashboard**: Access `https://apexbusinessksa.com/dashboard.html` — test branding controls, article editor, and settings synchronization.
- [ ] **PHP API Endpoints**:
  - `GET /api/settings.php` → Returns production JSON settings.
  - `POST /api/auth/login.php` → Authenticates authorized users.
- [ ] **Media Uploads**: Upload a sample image or logo in CMS → Verify it saves to `storage/media/` and displays correctly.
- [ ] **SEO Meta Tags & Social Share**: Inspect `<title>`, `<meta name="description">`, Open Graph, and Twitter tags on home and blog pages.
- [ ] **Debug Isolation**: Verify that `APP_DEBUG=false` hides sensitive stack traces and error details from end users.
