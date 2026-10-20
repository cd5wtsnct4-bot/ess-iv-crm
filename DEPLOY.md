# ESS IV Accommodation CRM: deployment guide

This guide takes you from the zip file to a live system on your web server, with alert emails going to **sean.h.petersen@gmail.com**, per-person access control, and a Profit & Loss statement for the finance department.

- **Part A** covers cPanel or other shared hosting. Use it if you manage your site through a hosting control panel.
- **Part B** covers your own Linux server (VPS) with command-line access.

Both end with the same checks in **Part C**.

---

## What you need

| Requirement | Details |
|---|---|
| PHP | 8.1 or newer, with the **pdo_mysql**, **mbstring** and **openssl** extensions (most hosts have these on by default) |
| Database | MySQL 5.7+ / 8.x, or MariaDB 10.3+ |
| Web server | Apache (the included `.htaccess` files protect private folders) or Nginx (see Part B) |
| Email | A mailbox on your domain, such as `crm@yourdomain.co.za`, with its SMTP details. PHP `mail()` also works, but its messages often land in spam. |
| SSL | An HTTPS certificate. This is strongly recommended because staff sign in and guest ID numbers are stored. Let's Encrypt is free on most hosts. |

## What's in the zip

```
ess-iv-crm/
├── index.php            The CRM (dashboard, calendar, reservations, check-in/out, rooms, maintenance)
├── login.php, logout.php
├── users.php            Users & access: who can see or edit each area (admins); change my password (everyone)
├── finance.php          Profit & Loss statement and operating expenses (Finance access only)
├── settings.php         Email alerts: recipients, test email, email log (admins)
├── api.php              Saves and loads every record in MySQL
├── cron.php             Scheduled job that emails alerts and the daily summary
├── install.php          One-time setup wizard (delete it after installing)
├── config.sample.php    Settings template (the installer writes config.php for you)
├── .htaccess            Blocks web access to private files
├── app/                 PHP code (not web-accessible)
├── assets/              CSS and JavaScript
└── database/            schema.sql and rooms_seed.sql (for a manual install)
```

---

## Part A: cPanel or shared hosting (no command line needed)

### A1. Create the database

1. Log in to cPanel and open **MySQL® Databases**.
2. Under **Create New Database**, enter `ess4crm` and click **Create Database**. cPanel adds your account prefix, so the full name looks like `cpuser_ess4crm`. **Write down the full name.**
3. Under **MySQL Users → Add New User**, create the user `ess4crm` with a strong password, made with the Password Generator. **Write down the full username (for example `cpuser_ess4crm`) and the password.**
4. Under **Add User To Database**, choose that user and that database and click **Add**. Tick **ALL PRIVILEGES**, then click **Make Changes**.

### A2. Create the email mailbox for sending alerts

1. In cPanel, open **Email Accounts → Create**.
2. Create `crm@yourdomain.co.za` with a strong password.
3. Click **Connect Devices** next to the new mailbox and note the **Outgoing Server**, for example `mail.yourdomain.co.za`, **SMTP Port 465** with SSL.

### A3. Upload the files

1. Open **File Manager** and go to `public_html`.
2. Decide on the address you want:
   - **`https://yourdomain.co.za/crm/`**: create a folder named `crm` inside `public_html` and open it. *(This is the recommended option.)*
   - **A subdomain such as `https://crm.yourdomain.co.za/`**: first create it under **Domains** (or **Subdomains**), then open its folder.
3. Click **Upload** and upload `ess-iv-crm.zip`.
4. Right-click the zip, choose **Extract**, and extract it into the current folder.
5. The zip contains an `ess-iv-crm` folder. Open it, select all of its contents with **Select All** (this includes the hidden `.htaccess`; turn on **Settings → Show Hidden Files** if you can't see it), and use **Move** to put them one level up, directly in `crm`. Then delete the empty `ess-iv-crm` folder and the zip.
6. Check that `index.php` and `.htaccess` are now directly inside `public_html/crm/`.

### A4. Check the PHP version

1. In cPanel, open **Select PHP Version** or **MultiPHP Manager**.
2. Choose **PHP 8.1, 8.2 or 8.3** for your domain.
3. Under **Extensions**, make sure `pdo_mysql`, `mbstring` and `openssl` are ticked.

### A5. Run the installer

1. In your browser, go to `https://yourdomain.co.za/crm/install.php`.
2. The page lists requirements. Every line should show a ✓.
3. Fill in the form:
   - **MySQL database:** host `localhost`, port `3306`, and the full database name, user and password from step A1.
   - **First administrator:** your username, full name and a password of 10 or more characters. This is the account you sign in with.
   - **Email alerts:**
     - *Send all alerts and notifications to:* already filled in with `sean.h.petersen@gmail.com`. You can add more addresses, separated by commas.
     - *From address:* `crm@yourdomain.co.za`
     - *Send using:* SMTP. *Server:* `mail.yourdomain.co.za`. *Port:* `465`. *Encryption:* SSL. *Username:* `crm@yourdomain.co.za`. *Password:* the mailbox password.
   - **Timezone:** `Africa/Johannesburg`.
4. Click **Install**. The installer will:
   - create the database tables
   - load all **600 rooms**: blocks A–F, floors 1–10, rooms 1–10. Rooms 1–4 are Single, 5–7 Double, 8–9 Twin and 10 Suite. On floor 1, rooms 1–2 are Accessible.
   - create your administrator account
   - save the settings to `config.php`
5. **Copy the cron URL** shown on the completion page. You need it in step A6.
6. **Delete `install.php`** in File Manager.

> If the completion page says it could not write **config.php**, create a new file called `config.php` in the `crm` folder in File Manager, paste in the text the installer shows, and save it.

### A6. Schedule the alert emails (cron job)

Alerts are emailed automatically every 10 minutes while someone has the CRM open. A cron job keeps them going around the clock, including at night, on weekends, and before staff sign in.

1. In cPanel, open **Cron Jobs**.
2. Under **Cron Email**, enter your address if you want to be told when the job fails, or leave it blank.
3. **Add New Cron Job**:
   - **Common Settings:** *Once Per Five Minutes*, or set Minute to `*/10` for every ten minutes.
   - **Command** (replace `cpuser` and the folder to match your account):

     ```
     /usr/local/bin/php /home/cpuser/public_html/crm/cron.php > /dev/null 2>&1
     ```

     If your host only allows URL cron jobs, use this instead, with the cron URL from step A5:

     ```
     wget -q -O /dev/null "https://yourdomain.co.za/crm/cron.php?key=YOUR_CRON_KEY"
     ```

4. Click **Add New Cron Job**.

The cron key is also stored in `config.php` as `'cron_key'`.

### A7. Turn on HTTPS

1. In cPanel, open **SSL/TLS Status** and run **AutoSSL** for your domain. On some hosts this is called **Let's Encrypt SSL**.
2. Edit `.htaccess` in the `crm` folder and remove the `#` from the three lines under *"Uncomment to force HTTPS"*. Visitors are then always redirected to `https://`.

Now go to **Part C**.

---

## Part B: your own Linux server (Ubuntu 22.04 / 24.04)

Run these commands as a user with `sudo`.

### B1. Install the software

```bash
sudo apt update
sudo apt install -y apache2 mysql-server php php-mysql php-mbstring php-xml unzip libapache2-mod-php
```

(For Nginx, install `nginx php-fpm` instead of `apache2 libapache2-mod-php`.)

### B2. Create the database and user

```bash
sudo mysql
```

```sql
CREATE DATABASE ess4crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ess4crm'@'localhost' IDENTIFIED BY 'a-strong-password-here';
GRANT ALL PRIVILEGES ON ess4crm.* TO 'ess4crm'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### B3. Upload and unpack

From your computer:

```bash
scp ess-iv-crm.zip you@your-server:/tmp/
```

On the server:

```bash
sudo unzip /tmp/ess-iv-crm.zip -d /var/www/
sudo chown -R www-data:www-data /var/www/ess-iv-crm
sudo find /var/www/ess-iv-crm -type d -exec chmod 755 {} \;
sudo find /var/www/ess-iv-crm -type f -exec chmod 644 {} \;
```

### B4. Configure the web server

**Apache.** Create `/etc/apache2/sites-available/ess4crm.conf`:

```apache
<VirtualHost *:80>
    ServerName crm.yourdomain.co.za
    DocumentRoot /var/www/ess-iv-crm
    <Directory /var/www/ess-iv-crm>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

```bash
sudo a2ensite ess4crm && sudo a2enmod rewrite expires && sudo systemctl reload apache2
```

**Nginx.** Nginx ignores `.htaccess`, so these rules block the private folders instead:

```nginx
server {
    listen 80;
    server_name crm.yourdomain.co.za;
    root /var/www/ess-iv-crm;
    index index.php;

    location ~ ^/(app|database)/ { deny all; }
    location ~ ^/(config\.php|config\.sample\.php|install\.lock|DEPLOY\.md)$ { deny all; }
    location ~ \.(sql|lock|log)$ { deny all; }

    location / { try_files $uri $uri/ =404; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # match your PHP version
    }
}
```

### B5. HTTPS

```bash
sudo apt install -y certbot python3-certbot-apache   # or python3-certbot-nginx
sudo certbot --apache -d crm.yourdomain.co.za        # or --nginx
```

### B6. Run the installer

Open `https://crm.yourdomain.co.za/install.php` and fill it in as in **step A5**, using database host `localhost`. Then remove the installer:

```bash
sudo rm /var/www/ess-iv-crm/install.php
```

### B7. Cron job

```bash
sudo crontab -u www-data -e
```

Add this line:

```
*/10 * * * * /usr/bin/php /var/www/ess-iv-crm/cron.php >> /var/log/ess4crm-cron.log 2>&1
```

Then create the log file and give the web user ownership of it:

```bash
sudo touch /var/log/ess4crm-cron.log && sudo chown www-data /var/log/ess4crm-cron.log
```

---

## Part C: first sign-in and checks

1. Open the CRM address and **sign in** with the administrator account.
2. Click **Email alerts** at the top right, then click **Send a test email**.
   - Check the inbox at **sean.h.petersen@gmail.com**, including the spam folder. If the email is in spam, mark it **Not spam**.
   - If the log shows **failed**, the error message explains why. The usual causes are a wrong SMTP password, port or encryption. Fix them in `config.php` under `'mail'`, then click **Retry failed emails**.
3. **Optional: try the system with example data.** On the Dashboard, click **Load example data**. This loads about 4,900 records: three months of bookings including cancellations and no-shows, offline rooms, maintenance tickets and operating expenses, so the Profit & Loss has figures to show. You'll receive one alert email listing the current alerts. When you've finished, click **Clear example data**. Only example records are removed.
4. Click **Users** to create an account for each staff member and choose their access (see *Users and access* below).
5. Make a test reservation, check it in, then check it out. Each step sends a notification email.

### Users and access

Every user has an access level of **No access**, **View only** or **Can edit** for each of four areas:

| Area | What it covers | Default for new staff |
|---|---|---|
| Reservations, calendar & check-in/out | Booking calendar, Reservations, Check-in / Check-out | Can edit |
| Room status | The 600-room status board, taking rooms out of service, housekeeping status | **No access** until approved |
| Room maintenance | Maintenance tickets and the preventive maintenance schedule | **No access** until approved |
| Finance (Profit & Loss) | The Finance page: P&L, cancellations, operating expenses, VAT | **No access**. This is never automatic, not even for admins. |
| Kitchen & catering | Meal planner, meals served, food-safety temperature log | **No access** until approved |
| Stock & purchasing | Stock items, movements, purchase orders, usage reports | **No access** until approved |
| Cleaning & housekeeping | Housekeeping board, cleaning schedule, cleaning supplies | **No access** until approved |

- When you add a user, choosing a **Department** fills in suggested access, which you can change before saving. For example, Finance gets Finance *Can edit*, and Maintenance gets Room status and Room maintenance *Can edit*.
- To change someone's access later, click **Change** next to their name.
- Tabs and buttons a person can't use are hidden. The server also refuses those actions, so access can't be bypassed.
- **Admins** always have full access to reservations, room status and maintenance, and can manage users and email settings. Admins only see Finance if they're given Finance access. The installer gives the first administrator Finance access.
- Some changes happen automatically, even for users without Room status access. Checking a guest out always marks the room *Dirty*. A maintenance ticket set to take its room offline puts the room *Under maintenance*, and completing it returns the room to service.

### Finance: Profit & Loss

Click **Finance** at the top right (visible only to people with Finance access). Choose a period: this month, last month, this quarter, last quarter, year to date, the last 12 months, or custom dates. Each figure is compared with the previous period of the same length.

- **Income:**
  - Room revenue: each night actually stayed in the period, at the booking's rate.
  - Cancellation fees and no-show fees retained, counted on the cancellation date. When a booking is cancelled, the deposit is kept as the fee automatically unless a different fee was entered on the booking.
- **Expenses:**
  - Maintenance: the actual cost of tickets completed in the period.
  - Operating expenses entered on the Finance page: salaries, utilities, cleaning, security, insurance and other categories.
- **Net profit / (loss)**, plus occupancy, average rate per night (ADR), revenue per available room (RevPAR), the cancellation rate and revenue lost to cancellations.
- **For information:** booking value lost to cancellations, and confirmed stays not yet checked in.
- Breakdowns by month, by block and by company, a list of every cancellation and no-show, and **Export CSV** to open the statement in Excel.
- **VAT:** by default, rates are treated as VAT-inclusive at 15%, so income is shown excluding VAT. You can change this at the bottom of the Finance page.

### What gets emailed

| Email | When |
|---|---|
| **Alert** | As soon as an item first appears. Overdue items are repeated once a day until resolved. Alerts cover: check-ins due today; check-outs due today; overdue check-outs; missed arrivals; check-ins and check-outs in the next 7 days, with their dates; overdue maintenance; rooms due back in service; preventive maintenance due. |
| **Daily summary** | Once a day, from 06:00 by default (changeable under Email alerts). Lists occupancy, plus every pending check-in, check-out and maintenance item with dates. |
| **Notification** | Every change: new or changed reservation, check-in, check-out, cancellation, no-show, room taken out of service or under maintenance (with reason), room back in service, maintenance ticket logged or updated, preventive task done. |

Every alert also appears in the system: on the **Dashboard** alerts panel, as the red count on the Dashboard tab, and on the **Check-in / Check-out** page. You can switch any type of email on or off, and change the recipients, under **Email alerts**.

---

### Operations: catering, kitchen, stock and cleaning

Click **Operations** at the top right. It's visible to anyone with access to Kitchen & catering, Stock & purchasing, or Cleaning & housekeeping.

- **Stock alerts** are shown on the dashboards and emailed to the alert recipients:
  - *below reorder level* (a shortage)
  - *getting close*: within 25% above the reorder level, or due to run out within 5 days at the last 14 days' usage
  - Food items are listed first, as **Food shortages**.
  - You can change the 25% and the 5 days under **Stock & purchasing → Stock alert settings**.
- **Receiving a purchase order** adds the stock and records the cost as an expense in the P&L. Food goes under *Catering: food & beverage* and cleaning products under *Cleaning supplies*.
- **Temperature checks** outside the safe range (fridges 0 to 5 °C, freezers −18 °C or colder, hot holding 63 °C or hotter) need an action recorded, and send an immediate alert email.

### Bulk bookings and returning guests

- **Bulk booking** (top right) books many rooms for one client:
  1. Enter the client, the dates and how many rooms of each type, plus any preferred blocks and guest names.
  2. Click **Find rooms** to see the allocation.
  3. Click **Book**.
- All of the group's rooms share a group reference, such as `GRP-261020-AB12C`. Choose the group in **Reservations** to check in, check out or cancel the whole group at once.
- **Guests:** a guest profile is created automatically from the first booking that has an ID number, email or phone. When the guest books again, their saved special requests, dietary needs and room preference are added to the new booking. Manage profiles in the **Guests** tab.

### Updating an existing installation

Upload the new files over the old ones, **keeping `config.php`**. Then add the new tables and columns in one of these ways:

- **Easiest:** delete `install.lock`, open `install.php`, and enter the same database details and admin username. The installer only adds what's missing and keeps all your data. Delete `install.php` again afterwards.
- **Command line:**

  ```
  php -r 'require "app/bootstrap.php"; run_schema(db());'
  ```

## Backups

All records are in the MySQL database. Back it up daily:

- **cPanel:** use **Backup → Download a MySQL Database Backup**, or turn on JetBackup or automatic backups if your host offers them.
- **VPS:** add this to root's crontab:

  ```
  30 2 * * * mysqldump --single-transaction ess4crm | gzip > /var/backups/ess4crm-$(date +\%F).sql.gz
  ```

## Troubleshooting

| Problem | Fix |
|---|---|
| "Load example data" fails | The example set is about 3.5 MB. Make sure PHP's `post_max_size` is at least 8M (in cPanel: **Select PHP Version → Options**). |
| Someone can't see a tab | Their access for that area is *No access*. An admin can change it under **Users → Change**. They need to reload the page afterwards. |
| Blank page or "500 Internal Server Error" | Check the PHP version (8.1+) and the server's error log. To see the error on screen briefly, set `'debug' => true` in `config.php`, then set it back to `false`. |
| "Already installed" when you want to reinstall | Delete `install.lock` and `config.php`, then open `install.php` again. Your data is kept. |
| The installer says "Access denied for user" | The database user isn't linked to the database with ALL PRIVILEGES (step A1.4), or the name or password is wrong. Remember the cPanel prefix. |
| Emails don't arrive | Check **Email alerts → Email log**. A "failed" entry shows the server's reason. If entries stay "pending", the cron job isn't running, so check the command and path in step A6. |
| Emails go to spam | Use SMTP with a mailbox on your own domain, not PHP mail(). In cPanel **Email Deliverability**, repair SPF and DKIM for the domain. |
| Locked out after failed sign-ins | Wait 15 minutes, or have an admin reset your password under **Users**. |
| Forgot the only admin password | In phpMyAdmin, set the `users` row's `role` to `admin` for another account, or delete `install.lock` and run `install.php` again with the same admin username. That resets its password and keeps all data. |
