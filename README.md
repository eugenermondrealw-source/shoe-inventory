# Footwear Inventory & Size Availability System

A small web app for a shoe retail business. The merchant tracks every shoe model and how many pairs are left in each US size. Sizes at 0 are highlighted in red and low stock in amber. Staff can update quantities; the owner can also add and delete shoes and manage user accounts.

Built with **PHP 8.1+ (PDO)**, **MySQL / MariaDB** and **Bootstrap 5**. It runs entirely on localhost.

**Repository:** https://github.com/eugenermondrealw-source/shoe-inventory

---

## Features

- **Inventory dashboard** (`admin.php`): every shoe with brand, price and a grid of sizes showing pairs left.
  - Red = out of stock (0), amber = low stock (1-3). Sizes are also labelled **OUT** / **LOW**, so it isn't colour-only.
  - Summary cards, live search, category and stock filters, sorting, and "Edit stock" with +/- steppers.
- **Add shoe** (`add-shoe.php`): details plus starting stock for US 7.0-11.0, custom sizes, quick-fill, live pair total, image preview and duplicate detection.
  - Inserts into `shoes` first, then loops over `shoe_sizes` using the new shoe's ID, all inside one transaction.
- **Login and roles**: Admin and Staff accounts, with first-run setup for the first admin.
- **User management** (`users.php`, admin only): add users, change roles, reset passwords, enable or disable accounts.

### Roles

| Capability                      | Admin | Staff |
|---------------------------------|:-----:|:-----:|
| View inventory                  |   ✓   |   ✓   |
| Update stock quantities         |   ✓   |   ✓   |
| Add new shoes                   |   ✓   |       |
| Delete shoes                    |   ✓   |       |
| Manage users                    |   ✓   |       |

Roles are enforced on the server for every request, not only hidden in the menu.

---

## Project structure

```
shoe-inventory/
├── README.md        This file
├── schema.sql       Creates the `users` table (login + roles)
├── db.php           Database credentials, session settings, helper functions
├── auth.php         Roles, login checks (require_login / require_role), validators
├── layout.php       Shared page header, navbar, flash banner and script includes
├── login.php        Sign-in page + one-time "create first admin" form
├── logout.php       Sign-out (POST only, CSRF protected)
├── admin.php        Inventory dashboard (Admin + Staff)
├── add-shoe.php     Add a new shoe with starting stock (Admin only)
└── users.php        Manage accounts and roles (Admin only)
```

---

## Requirements

| Item | Version |
|------|---------|
| PHP | **8.1 or newer** with the `pdo_mysql` extension (enabled by default in XAMPP) |
| Database | MySQL 5.7+ or MariaDB 10.4+ |
| Web server | Apache (XAMPP) or PHP's built-in server |
| Browser | Any modern browser |
| Internet | Needed on first load. Bootstrap is loaded from a CDN (see *Working offline*) |

The easiest route on Windows or macOS is **[XAMPP](https://www.apachefriends.org/)**, which bundles Apache, PHP, MySQL and phpMyAdmin.

---

## Setup (XAMPP)

### 1. Install and start XAMPP
1. Install XAMPP (choose a version that ships PHP 8.1+).
2. Open the **XAMPP Control Panel** and click **Start** next to **Apache** and **MySQL**. Both should turn green.

### 2. Get the project from GitHub
The project must end up inside XAMPP's web root (`htdocs`), in a folder named `shoe-inventory`:

- Windows: `C:\xampp\htdocs\shoe-inventory\`
- macOS: `/Applications/XAMPP/htdocs/shoe-inventory/`
- Linux: `/opt/lampp/htdocs/shoe-inventory/`

**Option A: clone with Git (recommended).** Install [Git](https://git-scm.com/downloads) if you don't have it (check with `git --version`), then open a terminal in `htdocs` and clone:

```bash
# Windows (Command Prompt or PowerShell)
cd C:\xampp\htdocs

# macOS
# cd /Applications/XAMPP/htdocs

# Linux
# cd /opt/lampp/htdocs

git clone https://github.com/eugenermondrealw-source/shoe-inventory.git
```

This creates `htdocs/shoe-inventory/` with all the files. (On macOS and Linux you may need `sudo` because `htdocs` is a protected folder.)

**Option B: download a ZIP (no Git needed).**
1. Open https://github.com/eugenermondrealw-source/shoe-inventory
2. Click the green **Code** button, then **Download ZIP**.
3. Extract it into `htdocs`. GitHub names the folder `shoe-inventory-main`, so **rename it to `shoe-inventory`**. Otherwise the URLs below won't match.

When you're done, `htdocs/shoe-inventory/` should contain `login.php`, `admin.php`, `db.php` and the other files listed under *Project structure*.

### 3. Create the database
1. Open **http://localhost/phpmyadmin**.
2. Click **New**, name the database `shoe_inventory`, choose collation `utf8mb4_general_ci` and click **Create**.
3. Select the new database, open the **SQL** tab, paste the statements below and click **Go**.

**If you already have the `shoes` and `shoe_sizes` tables, skip this block** and run only `schema.sql`. If you're starting fresh, run this first. The column types are sensible defaults. The one that matters is `size_number`, which must be a decimal so that 7.5 isn't cut to 7.

```sql
CREATE TABLE IF NOT EXISTS shoes (
    id        INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name      VARCHAR(150)  NOT NULL,
    brand     VARCHAR(100)  NOT NULL,
    category  VARCHAR(100)  NOT NULL,
    price     DECIMAL(10,2) NOT NULL,
    image_url VARCHAR(500)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shoe_sizes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shoe_id     INT UNSIGNED NOT NULL,
    size_number DECIMAL(3,1) NOT NULL,
    quantity    INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_shoe_size (shoe_id, size_number),
    CONSTRAINT fk_sizes_shoe FOREIGN KEY (shoe_id) REFERENCES shoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Then run the contents of **`schema.sql`** the same way. It creates the `users` table.

### 4. Check the database settings
Open `db.php` and confirm the values at the top match your setup. XAMPP defaults already work:

```php
const DB_HOST = 'localhost';
const DB_NAME = 'shoe_inventory';
const DB_USER = 'root';
const DB_PASS = '';           // XAMPP's root user has no password by default
const APP_TIMEZONE = 'Asia/Manila';
```

### 5. Open the app
Go to **http://localhost/shoe-inventory/login.php**

Because no users exist yet, you'll see **First-time setup**. Enter your name, a username and a password (8+ characters). That creates the first **Admin** account, and you can then sign in.

### 6. Add your staff
Sign in as Admin, open **Users**, and add accounts with the **Staff** role for anyone who should only update stock.

---

## Keeping your copy up to date

If you cloned with Git, pull the latest changes at any time:

```bash
cd C:\xampp\htdocs\shoe-inventory
git pull
```

If you edited `db.php` for your own database password and Git complains about local changes, run `git stash`, then `git pull`, then `git stash pop`, which re-applies your edits.

To push your own changes back to GitHub:

```bash
git add .
git commit -m "Describe what you changed"
git push
```

---

## Alternative: PHP's built-in server

If you already have PHP 8.1+ and MySQL installed and don't want Apache:

```bash
cd shoe-inventory
php -S localhost:8000
```

Then open **http://localhost:8000/login.php**. MySQL must be running, and `php -m | grep pdo_mysql` should list the extension.

---

## Using the system

**Update stock** (Admin or Staff): on the dashboard click **Edit stock** on a shoe, change quantities with the +/- buttons or by typing, then **Save changes**. Changed fields turn yellow, and Save stays disabled until something changes.

**Add a shoe** (Admin): **Add Shoe** → fill in name, brand, category and price → enter starting quantities (use **Quick fill** to set every size at once) → **Save shoe**. Sizes left at 0 are still saved, so they show as out of stock. Use **+ Add another size** for sizes like 6.5 or 12.

**Find problems fast**: click the red or amber summary card, or use the **Stock** filter, to see only shoes with out-of-stock or low-stock sizes.

**Delete a shoe** (Admin): **Delete** on its row, then confirm. This removes the shoe and all of its sizes.

---

## Configuration

| What | Where | Default |
|------|-------|---------|
| Database credentials | `db.php` | `root`, no password |
| Time zone | `APP_TIMEZONE` in `db.php` | `Asia/Manila` |
| Low-stock threshold | `$lowLimit` in `admin.php` | `3` pairs |
| Standard sizes on the add form | `STANDARD_SIZES` in `add-shoe.php` | 7.0-11.0 |
| Auto sign-out when idle | `IDLE_TIMEOUT` in `auth.php` | 3600 s (1 hour) |
| Failed logins before lock | `MAX_FAILED` in `auth.php` | 5 |
| Lock duration | `LOCK_SECONDS` in `auth.php` | 300 s (5 min) |

---

## Security notes

- All database access uses **PDO prepared statements** (real server-side prepares).
- Passwords are stored with `password_hash()`. Plain-text passwords are never saved.
- Every form carries a **CSRF token**, and all output is HTML-escaped.
- Login errors don't reveal whether a username exists. Repeated failures temporarily lock the account.
- Disabling a user or changing their role takes effect on their next click, even if they're signed in.
- Admins cannot demote or disable their own account.
- **Never commit real credentials.** `db.php` in the repository contains only the XAMPP defaults (`root`, empty password). If you set a real database password locally, don't push it to GitHub, especially if the repository is public. Likewise, don't commit database exports that contain real accounts or business data.
- This is built for **localhost**. Before putting it on a public server: use HTTPS, set a strong MySQL password (not the empty `root` one), create a dedicated database user instead of `root`, and turn off PHP error display.

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| "Database connection failed" | Make sure **MySQL** is started in XAMPP, and that `DB_NAME`, `DB_USER` and `DB_PASS` in `db.php` are correct. |
| "The users table is missing" | Run `schema.sql` in the `shoe_inventory` database (step 3). |
| `could not find driver` | Enable `pdo_mysql`: in `php.ini` remove the `;` before `extension=pdo_mysql`, then restart Apache. |
| Apache won't start (port 80 in use) | Another program (Skype, IIS) is using it. Stop it, or change Apache's port in XAMPP > Config > `httpd.conf`, then use `http://localhost:8080/shoe-inventory/`. |
| Page looks unstyled / plain | Bootstrap loads from the internet (jsDelivr CDN). Check your connection, or see *Working offline*. |
| "Security token invalid" | Your session expired or cookies are blocked. Reload the page and try again. |
| "Too many failed attempts" | Wait 5 minutes, or have an admin open **Users > Edit > Save** on your account, which clears the lock. |
| Locked out as the only admin | In phpMyAdmin run `DELETE FROM users;`, then open `login.php` to re-run first-time setup. |
| Sizes show as `7` instead of `7.5` | `shoe_sizes.size_number` is an integer. Change it with `ALTER TABLE shoe_sizes MODIFY size_number DECIMAL(3,1) NOT NULL;` |
| Blank white page | Check Apache's `error.log` (XAMPP > Apache > Logs), or temporarily add `ini_set('display_errors','1');` at the top of `db.php`. |

### Working offline
Download `bootstrap.min.css` and `bootstrap.bundle.min.js` (version 5.3.x) into an `assets/` folder inside the project, and change the two CDN URLs in `layout.php` to `assets/bootstrap.min.css` and `assets/bootstrap.bundle.min.js`.

---

## Backup

Export the database regularly. In phpMyAdmin choose **Export**, or from a terminal:

```bash
mysqldump -u root shoe_inventory > shoe_inventory_backup.sql
```

---

## Possible next steps

- A public, read-only **size availability page** for customers.
- Edit shoe details (name, brand, price) after creation.
- Photo upload instead of image URLs.
- Stock-change history (who changed what, and when).
- CSV export of current stock.
