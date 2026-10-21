# ESS IV Accommodation CRM: developer guide

This guide is for the web developer who will install, host and maintain the system. It covers:

- **Section 1:** what the system is and how it is built
- **Section 2:** running it on your own computer
- **Section 3:** deploying it to production
- **Sections 4–9:** configuration, email, the scheduled job, upgrades, the live demo, and security

For a click-by-click cPanel walkthrough written for non-developers, see `DEPLOY.md`. `CHECKLIST.md` is a printable checklist.

---

## 1. Overview

**What it does:**

- reservations, including bulk group bookings, and check-in/out for 600 rooms
- guest profiles with saved special requests
- room status and maintenance
- an Operations module: kitchen and catering, stock and purchasing, cleaning and housekeeping
- per-user access control
- email alerts and notifications
- a Profit & Loss statement

**Technology:**

| Layer | Details |
|---|---|
| Server | **PHP 8.1+** (tested on 8.3 and 8.5), plain PHP with no framework and no Composer dependencies |
| Database | **MySQL 5.7+/8.x or MariaDB 10.3+**, accessed through PDO (`pdo_mysql`), InnoDB, utf8mb4 |
| Front end | One vanilla-JavaScript single-page app (`index.php` + `assets/js/app.js`) for bookings and rooms. Operations, Finance, Users and Settings are server-rendered PHP pages. No build step, and no npm at runtime. |
| Web server | Apache 2.4 (rules in `.htaccess`) or Nginx (rules in section 3.3) |
| Email | Built-in SMTP client (SSL/STARTTLS, AUTH LOGIN) or PHP `mail()`. Emails are queued in the database and then sent. |
| Fonts | Google Fonts (IBM Plex Sans/Mono, Bricolage Grotesque), with system-font fallbacks |

### 1.1 Folder layout

```
.htaccess               Denies config/SQL/docs; disables directory listing; optional HTTPS redirect
index.php               Main app shell (SPA). Requires sign-in. Injects window.APP (CSRF token, today, user, permissions)
api.php                 JSON API used by the SPA (see 1.3)
login.php / logout.php  Session sign-in (bcrypt, lockout after 5 failures for 15 min)
users.php               Users & access (admins); change own password (everyone)
settings.php            Email recipients, test email, email log / Demo mailbox (admins)
finance.php             Profit & Loss, cancellations, operating expenses, VAT, CSV export (Finance access)
operations.php          Operations dashboard (catering / stock / cleaning)
catering.php            Meal planner, meals served, food-safety temperature log
stock.php               Stock items, movements, purchase orders, usage report, stock-alert settings
cleaning.php            Housekeeping board, common-area cleaning schedule, cleaning supplies
cron.php                Scheduled job: alert emails, daily summary, email queue (CLI or ?key=)
install.php             One-time web installer (creates tables, rooms, first admin, config.php); locks itself
config.sample.php       Configuration template (install.php writes config.php)
app/                    PHP code, not web-accessible (app/.htaccess denies)
  bootstrap.php           Loads config, timezone, security headers, h(), today()
  db.php                  PDO connection, run_schema() + migrate(), the 600-room catalogue, seed_rooms()
  auth.php                Sessions, CSRF, login/lockout, access areas, can()/user_perms()
  repo.php                Records ↔ tables mapping, validation and business rules, saving, group bookings, guest profiles, example data
  alerts.php              Alert rules (bookings, maintenance, operations), alert/digest emails, change notifications
  mailer.php              Email queue, HTML/text rendering, SMTP client, settings helpers
  ops.php                 Operations rules: stock movements, purchase orders, meal forecasts, temperature rules, stock alerts, example ops data
  layout.php              Page shell helpers for the server-rendered pages
assets/css/app.css      All styles (light/dark themes via CSS custom properties)
assets/js/shared.js     Constants (rooms, types, rates), date helpers, example-data generator
assets/js/app.js        The SPA: dashboard, calendar, reservations, front desk, rooms, maintenance, guests, dialogs
database/schema.sql     Full schema (CREATE TABLE IF NOT EXISTS; run_schema() executes it)
database/rooms_seed.sql The 600 rooms, for a manual install only
demo/                   Live-demo container (Dockerfile + start.sh) used by render.yaml
scripts/                CLI helpers for demos (demo-setup.php, gen-demo.js, seed-demo.php)
tools/build-deployment.sh  Builds an upload-ready production package
.devcontainer/          GitHub Codespaces demo (PHP + MySQL + Mailpit)
docs/index.html         Browser-only static demo, served by GitHub Pages
render.yaml             Render Blueprint for the one-click live demo
```

### 1.2 Data model (main tables)

| Table | Holds |
|---|---|
| `rooms` | 600 fixed rooms: block A–F, floor 1–10, rooms 01–10. Room 01–04 Single, 05–07 Double, 08–09 Twin, 10 Suite; floor-1 rooms 01–02 are Accessible. Also holds `service` (in / maint / oos), `hk` (Clean / Dirty / Inspected), reason, since and expected return |
| `bookings` | Reservations: guest, stay, billing and status (Provisional, Confirmed, Checked-in, Checked-out, Cancelled, No-show), plus `cancellation_fee`, `cancelled_on`, `group_ref` and `guest_id` |
| `guest_profiles` | Returning guests: saved `standing_requests`, `dietary`, `room_preference`, VIP flag, number of stays |
| `maintenance_tickets`, `maintenance_schedules` | Repairs, which can take a room offline, and preventive maintenance |
| `stock_items`, `stock_movements` | Stock with reorder levels and weighted average cost; a signed movement ledger (receive / issue / adjust / waste) |
| `purchase_orders`, `purchase_order_lines` | Draft → Ordered → Received / Cancelled. Receiving adds stock and writes `finance_expenses` rows |
| `meal_plans`, `kitchen_checks` | Menu, planned and served portions per day and meal; food-safety temperatures |
| `cleaning_tasks`, `cleaning_log` | Common-area schedule and sign-offs |
| `finance_expenses` | Operating expenses entered by Finance, plus automatic supply purchases (`source` = PO number) |
| `users` | Accounts: `role` admin/staff, plus `perm_<area>` = none / view / edit for 7 areas |
| `email_queue`, `alerts_sent`, `settings`, `audit_log` | Outgoing email; sent-alert de-duplication; key/value settings; a full change history |

Every table that example data can touch has an `example` flag. **Load / Clear example data** only ever removes rows with `example = 1`.

### 1.3 API (`api.php`)

All endpoints need a signed-in session. Every `POST` must include the header `X-CSRF-Token` (the token comes from `window.APP.csrf`).

| Request | Purpose |
|---|---|
| `GET ?r=state` | Everything the SPA needs: bookings (last 60 days plus all active), rooms, tickets, schedules, guests (each filtered by the user's access) and `ops` alerts. It also triggers the alert check in the background after the reply is sent, at most every 10 minutes. |
| `POST ?r=save` `{coll, id, mode: set/update, data}` | Create or update one record. `coll` is bookings, rooms, tickets, schedules or guests. The server validates the record, applies the business rules (see 1.4), writes an audit entry and queues a notification. Returns `{record, also, note}`, where `also` holds knock-on changes, such as a room set to Dirty. |
| `POST ?r=delete` | Bookings are never deleted (they are cancelled instead). Deleting a room record resets it. Tickets and schedules can only be deleted by an admin. |
| `POST ?r=bulk_preview` | Allocates rooms for a group: preferred blocks first, then keeping the group together |
| `POST ?r=bulk_book` | Books every room for a group in one transaction: all of them, or none |
| `POST ?r=group_status` | Checks in, checks out or cancels a whole group |
| `POST ?r=demo_load` / `demo_clear` | Loads or clears example data (admin only) |

### 1.4 Business rules the server enforces

- **No double bookings.** The room row is locked (`SELECT … FOR UPDATE`) and checked for overlapping active bookings. Rooms that are offline for the dates are refused.
- **Check-out sets the room to Dirty.** A ticket marked "takes room offline" puts its room *Under maintenance*. When the last such ticket is completed, the room goes back into service as Dirty.
- **Cancellations and no-shows** record the cancellation date and keep the deposit as the fee, unless a fee was entered.
- **Guest profiles** are matched by ID/passport number, then email, then phone plus surname. South African numbers `0XX…` and `+27XX…` count as the same number. A new booking gets the guest's saved requests added automatically.
- **Stock:**
  - Issues and waste cannot take stock below zero.
  - Deliveries update the weighted average cost.
  - Receiving a purchase order writes expenses by category: *Catering: food & beverage*, *Cleaning supplies*, *Guest amenities & linen* or *Maintenance spares*.
- **Access control** applies to every request: `require_area()` on the server, `can()` on the pages, and the screens hide what a user can't use. **Finance access is never automatic, not even for admins.**

### 1.5 Access areas

There are seven areas: `reservations`, `rooms`, `maintenance`, `finance`, `catering`, `stock`, `cleaning`.

- Each user has *none*, *view* or *edit* for each area, set on **Users**.
- Admins get *edit* everywhere except `finance`, which must be granted explicitly.
- Choosing a department on the Users page pre-fills suggested access.

---

## 2. Running it on your own computer

### Option A: Docker (one command, includes example data)

```bash
docker build -f demo/Dockerfile -t ess-iv-crm-demo .
docker run --rm -p 8080:8080 ess-iv-crm-demo
```

Open http://localhost:8080 and sign in using the **Use** buttons on the sign-in page (for example `admin` / `Demo-Admin-2026`). Emails are kept in the in-app Demo mailbox (**Email alerts**). The data resets every time the container restarts.

### Option B: native PHP + MySQL (best for editing code)

macOS with Homebrew. On Linux, use your package manager instead.

```bash
brew install php mysql && brew services start mysql
mysql -uroot -e "CREATE DATABASE ess4crm CHARACTER SET utf8mb4; CREATE USER 'ess4crm'@'localhost' IDENTIFIED BY 'devpass12345'; GRANT ALL ON ess4crm.* TO 'ess4crm'@'localhost';"
php -d post_max_size=16M -S 127.0.0.1:8000
```

1. Open http://127.0.0.1:8000/install.php.
2. Enter host `127.0.0.1` and the database details above. For email, choose *PHP mail()*, or point SMTP at a local catcher such as Mailpit on port 1025.
3. Sign in, then click **Load example data** on the Dashboard.
4. To run the scheduled job by hand, use `php cron.php`.

To see PHP errors on screen while developing, set `'debug' => true` in `config.php`.

### Checks before committing

```bash
for f in *.php app/*.php scripts/*.php; do php -l "$f"; done
cat assets/js/shared.js assets/js/app.js > /tmp/all.js && node --check /tmp/all.js
```

---

## 3. Deploying to production

### 3.1 Build the upload package

```bash
tools/build-deployment.sh ../deployment
```

This produces `ess-iv-crm-<version>.zip` and `upload/ess-iv-crm/`. They contain the production files only, with no demo, Docker, GitHub or tool files, and never `config.php`. It also produces the HTML guide, the checklist and SHA-256 checksums. The script stops if any PHP file has a syntax error.

### 3.2 Shared hosting / cPanel

Follow `DEPLOY.md` **Part A**. In short:

1. Create the MySQL database and a user with ALL PRIVILEGES on it.
2. Create the mailbox that will send alerts.
3. Upload the files and extract them into, for example, `public_html/crm/`.
4. Select PHP 8.1 or later.
5. Run `install.php`, then delete it.
6. Add a cron job every 10 minutes: `php /home/USER/public_html/crm/cron.php`.
7. Turn on AutoSSL and uncomment the HTTPS redirect in `.htaccess`.

### 3.3 VPS (Ubuntu, Apache or Nginx)

Follow `DEPLOY.md` **Part B**. Points to note:

- **Apache** needs `AllowOverride All` on the document root, plus `a2enmod rewrite expires`.
- **Nginx** ignores `.htaccess`, so deny the private paths yourself:

  ```nginx
  location ~ ^/(app|database|scripts|demo|tools)/ { deny all; }
  location ~ ^/(config\.php|config\.sample\.php|install\.lock|DEPLOY\.md|DEVELOPER\.md|README\.md|render\.yaml)$ { deny all; }
  location ~ \.(sql|lock|log)$ { deny all; }
  ```

- **PHP settings:** `post_max_size` of at least 16M (example data is about 3.5 MB), `memory_limit` of 256M, and a timezone. The app sets its own timezone from `config.php`.
- **Cron** should run as the web user: `*/10 * * * * /usr/bin/php /var/www/ess-iv-crm/cron.php`.
- **File permissions:** directories 755 and files 644. `config.php` should be 640 and owned by the web user.

### 3.4 After deploying

1. Sign in as the administrator.
2. Go to **Email alerts**, click **Send a test email**, and check that it arrives (look in spam too).
3. Create users and set their access.
4. Check that `/config.php`, `/app/db.php` and `/database/schema.sql` return **403** or a blank page.
5. Set up daily database backups:

   ```
   mysqldump --single-transaction ess4crm | gzip > /var/backups/ess4crm-$(date +\%F).sql.gz
   ```

---

## 4. Configuration (`config.php`)

`install.php` writes this file. A template is in `config.sample.php`. **Never commit `config.php`**; it is listed in `.gitignore`.

```php
return [
  'db' => ['host' => 'localhost', 'port' => 3306, 'name' => '…', 'user' => '…', 'pass' => '…'],
  'timezone' => 'Africa/Johannesburg',      // defines "today" for arrivals and departures
  'app_url'  => 'https://example.co.za/crm/', // used for links in emails
  'cron_key' => '…random…',                  // for cron.php?key=… when only URL cron is available
  'debug'    => false,                       // true shows PHP errors (never in production)
  'demo'     => false,                       // true shows demo logins on the sign-in page
  'mail' => ['transport' => 'smtp',          // 'smtp' | 'mail' | 'log' (log = keep in the in-app mailbox only)
             'from' => 'crm@example.co.za', 'from_name' => 'ESS IV CRM',
             'smtp_host' => 'mail.example.co.za', 'smtp_port' => 465, 'smtp_secure' => 'ssl',
             'smtp_user' => 'crm@example.co.za', 'smtp_pass' => '…'],
];
```

**Settings stored in the database** (`settings` table, changed in the app):

- alert recipients (default **sean.h.petersen@gmail.com**)
- which types of email are switched on, and the hour the daily summary is sent
- the stock-alert margin (25%) and cover days (5)
- meal take-up percentages
- VAT rate and whether rates include VAT

## 5. Email, alerts and notifications

- Everything is written to `email_queue` first, then sent by `flush_email_queue()`. That runs after an API reply and on every cron run. Failed emails are retried up to 5 times within 2 days.
- **Alerts** are emailed once, when they first appear. `alerts_sent` remembers which ones have gone out. Overdue items are repeated daily. Alerts cover:
  - check-ins and check-outs due today and in the next 7 days, overdue check-outs, missed arrivals
  - overdue maintenance, rooms due back in service, preventive maintenance due
  - food shortages (below or close to reorder level), supplies low, expiring stock
  - temperature failures, overdue cleaning, late deliveries
- **Daily summary** is sent from the configured hour (default 06:00).
- **Notifications** are sent for every change: bookings, check-in/out, cancellations, group bookings, rooms going offline or back, tickets, purchase orders and deliveries, wastage, expenses.
- **Deliverability:** use SMTP with a mailbox on the client's own domain, and repair SPF/DKIM in cPanel's **Email Deliverability**. PHP `mail()` often lands in spam.

## 6. Scheduled job (`cron.php`)

- **Command line:** `php cron.php`. Add `--digest` to force the daily summary.
- **Web:** `https://…/cron.php?key=<cron_key>`.
- The job is safe to run often, because it uses MySQL `GET_LOCK` to prevent overlapping runs.
- Without cron, alerts still go out every 10 minutes while somebody has the CRM open.

## 7. Upgrading

1. Back up the database.
2. Upload the new files over the old ones, **keeping `config.php`**.
3. Apply database changes, either:
   - delete `install.lock`, run `install.php` with the same details, then delete it, or
   - from the command line: `php -r 'require "app/bootstrap.php"; run_schema(db());'`

`run_schema()` creates any missing tables and `migrate()` adds any missing columns. Both are idempotent and never drop data. When you add a column in future, add it to `schema.sql` **and** to `migrate()`.

## 8. Demos

- **Live demo (Render):** `render.yaml` builds `demo/Dockerfile`, a single container running PHP, Apache and MariaDB.
  - Example data loads automatically on start.
  - Email uses the `log` transport and appears in the in-app Demo mailbox.
  - Render's free plan sleeps the demo after 15 minutes idle, and each wake starts with fresh data.
  - To deploy: `https://render.com/deploy?repo=<repo URL>`.
- **Codespaces:** `.devcontainer/` (PHP, MySQL and Mailpit).
- **Static demo:** `docs/index.html` on GitHub Pages, running in the browser only.
- Demo passwords such as `Demo-Admin-2026` exist **only** in these demos. They are created by `scripts/demo-setup.php`, which refuses to run from the web. **Never run the demo scripts or use `demo/` on the production server.** The upload package leaves them out.

## 9. Security notes

- **Passwords:** PHP `password_hash` (bcrypt) with automatic rehashing. A lockout applies after 5 failed attempts.
- **Sessions:** HttpOnly, SameSite=Lax, and Secure on HTTPS. The session ID is regenerated at login, and users are signed out after 10 hours idle.
- **CSRF:** a token is checked on every POST, in both the forms and the API.
- **Database:** every query uses prepared statements, with emulation turned off.
- **Output escaping:** `h()` on the server and `esc()` in the SPA.
- **Security headers:** `X-Content-Type-Options`, `X-Frame-Options SAMEORIGIN`, `Referrer-Policy same-origin`.
- **Private files:** `app/`, `database/`, `scripts/` and `demo/` each contain `.htaccess` with `Require all denied`. Remember to add the equivalent Nginx rules.
- **Personal data:** guest ID numbers and contact details are personal information under POPIA. Use HTTPS, restrict database access, and keep backups encrypted.

## 10. Troubleshooting

| Symptom | Fix |
|---|---|
| Blank page or HTTP 500 | Set `'debug' => true` briefly, or read the PHP error log. Check the PHP version (8.1+) and that `pdo_mysql` and `mbstring` are installed. |
| Installer: "Access denied for user" | The user is not linked to the database with ALL PRIVILEGES, or the cPanel prefix is missing from the names. |
| "Load example data" fails | Raise `post_max_size` to 16M. |
| Emails stay *pending* | Cron is not running. Check the command, path and PHP binary. |
| Emails *failed* | The error is shown in the email log. It is usually the SMTP port/encryption pairing (465 with SSL, 587 with TLS) or the password. |
| Someone can't see a tab | Their access is *none* for that area. Change it on **Users → Change**; they then reload the page. |
| Locked out | Wait 15 minutes, or have an admin reset the password. |

## Ownership and source

- **Repository:** https://github.com/cd5wtsnct4-bot/ess-iv-crm
- **Default branch:** `main`

To rebuild the upload package for a release, run `tools/build-deployment.sh`.
