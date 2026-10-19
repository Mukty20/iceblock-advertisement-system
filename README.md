# Ice Block Production Advertisement System

A responsive PHP and MySQL web application for advertising ice block producers and helping customers find and contact local suppliers. It provides separate customer, producer, and administrator workflows, following the implementation requirements in the project document.

## Requirements

- PHP 8.1+ with `PDO`, `pdo_mysql`, `fileinfo`, and `mbstring` extensions.
- MySQL 8.0+ or a compatible MariaDB server.
- Apache with PHP (XAMPP is the setup described in the project document).

## Install with XAMPP

1. Install XAMPP and start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Copy this project folder into `C:\xampp\htdocs\IceBlock_Advertisement_System`.
3. Open phpMyAdmin at [http://localhost/phpmyadmin](http://localhost/phpmyadmin), select **Import**, and import `schema.sql`.
4. Open PowerShell in the project folder and run:

   ```powershell
   php seed.php
   ```

5. Open [http://localhost/IceBlock_Advertisement_System/](http://localhost/IceBlock_Advertisement_System/) in a browser.

The default MySQL connection is `127.0.0.1:3306`, database `ice_block_advertisement_system`, user `root`, and an empty password, matching a standard local XAMPP setup. To use another MySQL account, set `ICEBLOCK_DB_HOST`, `ICEBLOCK_DB_PORT`, `ICEBLOCK_DB_NAME`, `ICEBLOCK_DB_USER`, and `ICEBLOCK_DB_PASSWORD` in the PHP server environment before starting Apache.

The web server must be able to write to the `uploads` folder so producers can upload advertisement images. Images are limited to JPG, PNG, or WebP and 3 MB. The application also works with PHP's built-in server for local development after configuring MySQL:

```powershell
php -S 127.0.0.1:8000
```

If PHP rejects a valid 3 MB image before the application receives it, increase `upload_max_filesize` and `post_max_size` in `php.ini` and restart Apache.

## Demonstration accounts

| Role | Email | Password |
| --- | --- | --- |
| Producer | `producer@example.com` | `IceProducer123!` |
| Customer | `customer@example.com` | `IceCustomer123!` |
| Administrator | `admin@example.com` | `IceAdmin123!` |

These accounts are created by `seed.php` only when they do not already exist. You can choose different seed passwords using `ICEBLOCK_DEMO_PRODUCER_PASSWORD`, `ICEBLOCK_DEMO_CUSTOMER_PASSWORD`, and `ICEBLOCK_DEMO_ADMIN_PASSWORD`. Change the public sample passwords before any shared deployment.

## Included workflows

- **Customers:** register, search producers by business/product/location, view advertisements and contact information, submit enquiries, and read producer responses.
- **Producers:** manage their business profile and password; publish, edit and delete advertisements; provide product, size, price, quantity, image, contact and operating-hour information; monitor views; and respond to enquiries.
- **Administrators:** review users and activity, suspend or reactivate accounts, approve or deactivate advertisements, and delete inappropriate advertisements or inactive accounts.

New advertisements start as **pending** and become visible in public search after an administrator approves them. Payments and delivery are arranged directly between customers and producers; the system does not process transactions or delivery.

## Security and project files

- `index.php` renders the responsive user interface and routes public pages.
- `actions.php` validates form submissions, enforces roles, and performs account, advertisement, and enquiry operations.
- `config.php` configures PHP sessions and the PDO MySQL connection.
- `schema.sql` creates the relational tables, indexes, and foreign-key relationships.
- `seed.php` creates hashed demo accounts and sample offers.
- Passwords use PHP's `password_hash` / `password_verify`; database operations use prepared statements and forms use CSRF tokens.
- Uploaded images are checked by MIME type and size, assigned random filenames, and stored separately from executable PHP files.

For a production deployment, configure HTTPS and a restricted MySQL user, replace demo credentials, back up the database and uploaded images, and maintain current PHP/MySQL security updates.

## Validation

Check PHP syntax from PowerShell:

```powershell
php -l config.php
php -l includes\functions.php
php -l actions.php
php -l index.php
php -l seed.php
```

Run the local helper smoke checks with:

```powershell
php tests\smoke.php
```
