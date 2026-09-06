# Tokmart

Multi-file PHP storefront with a MySQL backend, a customer-facing store, and
a separate admin panel.

## Requirements

- PHP 8.1+ with the `pdo_mysql`, `gd`, and `fileinfo` extensions
- MySQL or MariaDB
- Apache with `mod_rewrite`/`.htaccess` support (or an equivalent Nginx config
  that denies direct access to `config/`, `includes/`, `database/`, and blocks
  script execution under `data/`)

`curl` is optional - Google sign-in works without it (falls back to a plain
HTTP request) but is recommended.

## Installation

1. Upload the contents of this folder to your web server (or a subdirectory
   of it).
2. Create an empty MySQL database and a user with privileges on it (the
   installer can also create the database itself if the user you give it has
   `CREATE DATABASE` privileges).
3. Visit `install.php` in your browser. It will:
   - Connect to your database and import `database/schema.sql`
   - Seed a few starter categories and payment methods (cash, electronic
     balance, bank transfer)
   - Create your admin account with the name/email/password you choose
   - Write `config/config.php` for you
4. You're redirected straight into the admin panel at `admin/index.php`,
   already logged in as the admin account you just created.

`install.php` refuses to run a second time once `config/config.php` exists,
so it's safe to leave on the server.

After installing, open **Settings** in the admin panel to configure Google
sign-in and outgoing email (SMTP) if you want those features - neither ships
with real credentials.

## Project structure

```
index.php              Single entry point for the storefront (home, cart,
                        account, etc. - no separate landing page)
install.php             First-run setup wizard
admin/                  Admin panel (dashboard, products, categories, orders,
                        users, payments, recharges, chat, settings)
api/                    JSON API the storefront and admin panel both call
                        (index.php routes ?action=... to api/*_handlers.php)
includes/               Shared PHP: db connection, session/auth guards,
                        helpers, email, uploads, notifications, settings
assets/css/, assets/js/ Storefront styles/scripts, split by concern
assets/css/admin-panel.css, assets/js/admin-panel.js
                        Admin panel styles/scripts (reuse the storefront's
                        CSS variables and .btn system for a consistent look)
database/schema.sql     MySQL schema (also imported automatically by install.php)
config/                 config.php (generated, git-ignored) + config.sample.php
data/uploads/           User-uploaded images (products, avatars, receipts, ...)
```

## Notes

- All money columns are `DECIMAL`; the API casts them back to numbers before
  they reach the frontend (`includes/helpers.php::castNumericFields`) since
  PDO returns `DECIMAL` values as strings.
- Uploaded files are re-encoded through GD where possible, and everywhere
  else the stored extension is derived from the server-verified MIME type,
  never from the client-supplied filename - `data/.htaccess` also blocks
  script execution under `data/` as a second layer of defense.
