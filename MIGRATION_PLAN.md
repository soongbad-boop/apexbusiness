# PHP + MySQL Migration Plan

## Current state

The browser application currently uses the `apex-content-v1` localStorage object. `app.js` renders the public hash routes and consumes settings, articles, countries, logs, and CMS navigation. `dashboard.html` contains the existing dashboard UI and `dashboard-cms.js` extends it with CMS sections using the same object. `schema.sql` is PostgreSQL-oriented and is not suitable for the requested MySQL deployment.

## Target state

- Public PHP entry points render SEO-critical content server-side.
- Protected PHP admin endpoints provide CRUD and authentication.
- PDO uses MySQL with prepared statements.
- The existing HTML/CSS dashboard remains the presentation layer.
- A small JavaScript data adapter can switch from localStorage to API responses without changing dashboard views.
- Development fallback data remains available only until API parity is verified.

## Stages

1. Add environment configuration, PDO bootstrap, secure session helpers, CSRF helpers, validation, and permission middleware.
2. Import the MySQL schema and seed roles, permissions, the first administrator, settings, menus, and migrated content.
3. Add repository/service classes for posts, categories, pages, media, menus, settings, users, and logs.
4. Add protected authentication and CRUD endpoints.
5. Replace dashboard storage calls with an API adapter while preserving the current dashboard markup and routes.
6. Add public PHP renderers for home, services, about, insights, contact, blog posts, and pages.
7. Add secure media storage and image metadata.
8. Test migration parity before disabling the localStorage fallback.

## Security gates

- Set credentials through server environment variables only.
- Use `password_hash()` and `password_verify()`.
- Rotate sessions after login and use secure, HttpOnly, SameSite cookies.
- Require CSRF tokens for state-changing requests.
- Check permissions in PHP for every protected action.
- Escape all output and validate all input.
- Store uploads outside executable web paths and validate MIME, extension, and size.
- Rate-limit login attempts and record security events.

## Current limitation

PHP and MySQL are not installed in the current terminal environment, so runtime integration cannot be certified here. The added PHP foundation must be smoke-tested on PHP 8.2+ with MySQL 8 or MariaDB on the target cPanel account before localStorage is removed.
