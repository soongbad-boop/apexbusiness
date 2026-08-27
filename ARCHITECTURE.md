# Production Architecture

The project now contains a PHP + MySQL migration foundation alongside the existing browser demo. The current HTML dashboard and frontend remain the compatibility UI until the PHP adapter is enabled on a PHP-capable host.

The current browser implementation is a client-side demonstration. It stores sample content in `localStorage` so that routes, article search, the CMS layout, and settings changes can be exercised without a server. It is not a secure authentication system and must not be deployed as an admin application. The PHP foundation lives in `app/`, `config/`, `public/api/`, and `database/`.

## Recommended stack

- Next.js or Laravel for server rendering and route handling
- MySQL 8 or MariaDB using `database/mysql-schema.sql`
- Filesystem or object storage for images and favicon uploads
- Secure HTTP-only session cookies with CSRF protection
- Argon2id password hashing
- Rate limiting for login and lead endpoints

## Roles

| Capability | Admin | Editor |
| --- | --- | --- |
| Manage users and roles | Yes | No |
| Update site settings and navigation | Yes | No |
| Manage pages, articles, testimonials, cases | Yes | Yes |
| View activity logs and analytics | Yes | No |

## REST API

### Public

- `GET /api/public/settings`
- `GET /api/public/navigation?location=header`
- `GET /api/public/pages/:slug`
- `GET /api/public/articles?query=&category=&page=&limit=`
- `GET /api/public/articles/:slug`
- `GET /api/public/articles/:slug/related`
- `GET /api/public/case-studies`
- `GET /api/public/testimonials`
- `POST /api/public/leads` (rate limited and CAPTCHA protected)
- `POST /api/public/events/page-view`

The initial PHP endpoints are available under `/api/`: `auth/csrf.php`, `auth/login.php`, `auth/logout.php`, `settings.php`, `posts.php`, and `media.php`. They are intentionally additive until the existing dashboard adapter is switched from localStorage to `fetch()`.

### Authentication

- `POST /api/auth/login`
- `POST /api/auth/logout`
- `GET /api/auth/me`
- `POST /api/auth/password-reset`

### Admin

- `GET|POST /api/admin/articles`
- `GET|PATCH|DELETE /api/admin/articles/:id`
- `POST /api/admin/media/presign`
- `GET|POST|PATCH|DELETE /api/admin/categories/:id?`
- `GET|PATCH /api/admin/settings`
- `GET|PATCH /api/admin/pages/:slug`
- `GET|POST|PATCH|DELETE /api/admin/navigation/:id?`
- `GET|POST|PATCH|DELETE /api/admin/case-studies/:id?`
- `GET|POST|PATCH|DELETE /api/admin/testimonials/:id?`
- `GET|POST|PATCH|DELETE /api/admin/users/:id?` (admin only)
- `GET /api/admin/activity-logs` (admin only)
- `GET /api/admin/analytics` (admin only)

## Security requirements

1. Authorize every admin endpoint on the server; never rely on hidden UI controls.
2. Validate all input with a schema validator and sanitize rich text before rendering.
3. Issue upload URLs only after checking MIME type, file size, and user role.
4. Store passwords only as Argon2id hashes. Use HTTPS, secure cookies, session rotation, and login throttling.
5. Write activity log records in the same transaction as every content, setting, and user mutation.
6. Generate page title, canonical URL, description, Open Graph image, and JSON-LD from published records server-side.