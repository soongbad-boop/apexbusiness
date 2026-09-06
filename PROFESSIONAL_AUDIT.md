# Apex Business Consultancy — Professional Audit & Hardening Report

Audit date: 6 September 2026

## What was improved

### Security & backend
- Removed a UTF-8 BOM that could break PHP `strict_types` execution.
- Hardened PHP sessions, cookies, CSRF handling, security headers, JSON request limits, and production error handling.
- Replaced session-only login throttling with cross-session file-backed rate limiting.
- Prevented public API exposure of draft, archived, and future-scheduled posts.
- Added allow-list rich-HTML sanitization on the server and in browser rendering to reduce stored-XSS risk.
- Hardened media uploads with MIME/image verification, pixel/dimension limits, randomized names, and executable-file blocking.
- Restricted `.git`, config, database, scripts, runtime logs/rate-limit data, backup/config files, and deploy tooling from web access.
- Converted `deploy.php` to CLI-only operation instead of accepting a web-triggered deployment secret.
- Fixed authenticated CMS routing so `/dashboard`, `/dashboard.html`, and `dashboard.php` cannot fall through to the public SPA.
- Removed third-party analytics from the CMS login screen and added no-cache/no-index headers for authentication/admin pages.
- Forced `APP_DEBUG` off whenever `APP_ENV=production`, even if the environment is misconfigured.

### CMS & article publishing
- Fixed CMS article save requests so CSRF is sent correctly.
- Fixed article editing so existing database posts are updated with `PATCH` instead of creating duplicates.
- Fixed post deletion so the database and browser state stay in sync.
- Added CMS hydration from MySQL so post IDs/status/content reflect the server rather than only localStorage.
- Public article listings now hydrate from the published-post API, while retaining bundled content as an offline/static fallback.
- Added server-side rendering for `/insights/{slug}` for stronger article SEO and social metadata.
- Corrected category-slug generation for categories containing characters such as `&`.

### Contact & lead forms
- Replaced browser-only “success” messages with real CSRF-protected submissions.
- Added an `inquiries` database table and `/api/inquiries.php` endpoint.
- Added validation, rate limiting, a honeypot field, metadata limits, and hashed IP storage.
- Contact, consultation, visa, automation, advisory-session, and newsletter submissions now report success only after the server accepts them.

### Mobile responsiveness & accessibility
- Added a dedicated `mobile-hardening.css` layer across the public site and server-rendered articles.
- Improved small-screen containers, typography, one-column grid fallbacks, form widths, tables, code blocks, article media, footer layout, and navigation behavior.
- Increased practical touch-target sizing and prevented common horizontal-overflow patterns.
- Added iOS-friendly 16px form controls and `prefers-reduced-motion` handling.
- Improved mobile navigation `aria-expanded` state and focus visibility.
- Added an off-screen accessible-safe honeypot that does not disturb mobile layout.

### Editorial / factual quality
Five high-risk market/travel articles were rewritten to remove unsupported or outdated claims and use a more professional “confirmed / pending / practical implications” structure:
1. GCC Unified Tourist Visa status guide.
2. GCC Unified Tourist Visa for residents readiness guide.
3. UAE–Bahrain Single Entry Point first-phase pilot explainer.
4. GCC tourism-integration / hospitality preparedness guide.
5. Saudi market-entry guide updated from legacy “MISA licensing” language to the current investment-registration framework.

The UAE–Bahrain article now reflects the GCC General Secretariat’s February 2026 description of the first-phase pilot. The Saudi article reflects MISA’s updated Investment Law and 2026 Investor Guide, including the move from foreign-investor licensing to investment registration.

### SEO & production polish
- Added/updated article URLs in `sitemap.xml` and refreshed modification dates.
- Prevented API/admin/private paths from being crawled.
- Improved server-rendered page canonical/robots behavior and safe rich-text rendering.
- Removed unverified marketing claims such as a hard-coded `1,200+` visa count, a fabricated-looking “14 years” advisor claim, and an overly specific KAFD floor/address claim.
- Updated visible service language from legacy MISA licensing terminology to investment registration / market-entry wording.

## Automated checks completed
- All PHP files: `php -l` passed.
- `app.js`: `node --check` passed.
- `dashboard-cms.js`: `node --check` passed.
- `sitemap.xml`: parsed successfully as XML.
- Main CSS files: opening/closing brace integrity checked.
- BOM scan completed and BOMs removed from runtime/config-sensitive files.
- Security/claim scan shows no remaining matches for the obsolete high-risk phrases targeted in this audit.

## Deployment checklist
1. Make a database and file backup before replacing a live site.
2. Copy `.env.production.example` to `.env` on the server and enter real DB/SMTP values. Never commit `.env`.
3. Set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE=1`, and the correct HTTPS `APP_URL`.
4. For a fresh database, import `database/mysql-schema.sql`, then run `php database/seed.php` from the server shell.
5. Create the first admin from the server shell with `php database/create-admin.php`.
6. Ensure `storage/media`, `storage/logs`, and `storage/rate_limits` are writable by PHP but not publicly listable.
7. Run `php deploy.php` only from the server CLI if using the deployment helper.
8. Test form submission against the production database and configure your preferred SMTP/notification workflow if email alerts are required.
9. After deployment, manually test iPhone/Android widths, Safari/Chrome, login, CMS create/edit/delete, media upload, all forms, and article URLs on the real HTTPS host.
10. Run an external production scan (for example OWASP ZAP passive scan and security-header/TLS checks) after DNS/SSL and server configuration are live.

## Important limitation
No website can be guaranteed “1000% secure.” This package materially reduces the code-level attack surface and fixes the issues found in the audit, but final security also depends on cPanel/Apache/PHP/MySQL versions, server permissions, TLS, credentials, backups, plugins/services, and future maintenance.
