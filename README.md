# RentSphere — Setup Guide

## 1. What's in this project
```
rentsphere/
├── auth/           login, register, email verify, OTP 2FA, forgot/reset password, logout
├── admin/          dashboard, properties, units, tenants, leases, payments, maintenance,
│                   expenses, reports, notifications, settings, users, activity logs, profile
├── caretaker/       caretaker's own dashboard
├── tenant/          tenant self-service portal (needs the optional migration, see step 6)
├── includes/        bootstrap.php, functions.php, header.php, footer.php (shared layout)
├── config/          config.php (security/session/CSRF), database.php (PDO connection)
├── assets/css/      style.css (design system, dark mode, responsive)
├── assets/js/       app.js (sidebar, dark mode, OTP inputs, SweetAlert2 helpers)
├── database/        rentsphere.sql (main schema), migration_tenant_portal.sql (optional)
├── uploads/          tenant IDs, photos, logos (protected by .htaccess — no PHP execution)
└── index.php         landing page
```

## 2. Install prerequisites
- XAMPP/WAMP/MAMP with PHP 8+ and MySQL running
- Composer (for PHPMailer) — https://getcomposer.org

## 3. Copy the project
Put the whole `rentsphere` folder inside your web root:
- XAMPP (Windows): `C:\xampp\htdocs\rentsphere`
- XAMPP (Mac/Linux): `/Applications/XAMPP/htdocs/rentsphere` or `/opt/lampp/htdocs/rentsphere`

## 4. Create the database
1. Start Apache + MySQL from your XAMPP control panel.
2. Open phpMyAdmin → Import → choose `database/rentsphere.sql` → Go.
   (It creates the `rentsphere` database and all 17 tables for you.)

## 5. Configure the app
Open **`config/database.php`** and set your MySQL credentials (XAMPP default is username `root`, empty password):
```php
private static string $username = 'root';
private static string $password = '';
```
Open **`config/config.php`** and set:
```php
define('APP_URL', 'http://localhost/rentsphere'); // match your folder name
```
and your SMTP details under the "Mail" section (needed for verification emails, OTP codes, and password resets — a free option for testing is Mailtrap.io or a Gmail app password).

## 6. Install PHPMailer
From inside the `rentsphere` folder, run:
```
composer require phpmailer/phpmailer
```
This creates the `vendor/` folder that `includes/functions.php` (`send_email()`) already expects.

## 7. (Optional) Enable the tenant portal
By default only administrators and caretakers can log in. If you want tenants to log into `tenant/dashboard.php` themselves:
1. Import `database/migration_tenant_portal.sql`.
2. Create a login for a tenant (see the comment at the bottom of that file for the exact SQL), or build a "Send portal invite" button in `admin/tenants.php` that automates it.

## 8. First run
1. Visit `http://localhost/rentsphere/` — the landing page.
2. Click **Get started** → this is the **one-time** first-administrator setup (`auth/register.php`). Once one administrator exists, this page redirects everyone else to login.
3. Check your email for the verification link (or check `logs/php_errors.log` if mail fails while you're still configuring SMTP).
4. Log in → you'll be asked for a 6-digit OTP code emailed to you (2FA) → land on the dashboard.

## 9. Day-to-day usage flow
1. **Properties** → add your buildings.
2. **Units** → add rentable units inside each property (rent, deposit, beds/baths).
3. **Tenants** → register tenant profiles (with ID/photo upload).
4. **Leases** → move a tenant into a vacant unit (creates the lease, marks the unit occupied).
5. **Rent Collection** → record payments against active leases (auto-generates a receipt number).
6. **Maintenance** → tenants/admins log requests, assign to a caretaker, mark complete.
7. **Expenses** → track repairs/utilities/salaries/etc.
8. **Reports** → view + export CSV for revenue, expenses, occupancy, tenants.
9. **Users & Roles** → create caretaker or additional administrator accounts.
10. **Settings** → company name, address, logo, currency.

## 10. Known extension points (not fully built out, by design, to keep this reviewable)
- **PDF/Excel export**: reports currently export CSV natively. For PDF add `tecnickcom/tcpdf`, for Excel add `phpoffice/phpspreadsheet` via Composer, then wire them into `admin/reports.php`.
- **FullCalendar**: the dashboard shows expiring leases and maintenance in tables; if you want the visual calendar widget, add the FullCalendar CDN script to `admin/dashboard.php` and feed it the same query results as events.
- **Tenant self-registration / invite flow**: currently an admin creates tenant login accounts manually (step 7). A "Send Portal Invite" button that emails a set-password link is a natural next feature.
- **SMTP settings from the database**: `settings` table exists for this; currently mail config lives in `config/config.php` for simplicity.

## 11. Security features already built in
- PDO prepared statements everywhere (no raw SQL concatenation)
- CSRF tokens on every POST form
- `password_hash()` / `password_verify()`
- Email verification required before first login
- OTP-based two-factor authentication on every login
- Account lockout after 5 failed attempts (15 min)
- Secure, httponly, idle-timeout sessions with periodic ID regeneration
- Output escaping via the `e()` helper everywhere user data is printed
- `.htaccess` blocking PHP execution inside `/uploads` and denying direct access to `/config`
- Full activity log + login history tables

## 12. Test login flow
Because this is a fresh install, no admin exists — go straight to **Create your account** and follow the on-screen email verification + 2FA steps. Every login after that (including yours) goes through the OTP flow.
