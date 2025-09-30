# EPSS Self-Assessment Web App

This is a LAMP-based web application for the Ethiopia EPSS staff self-assessment.

## Features
- Authentication (staff & admin)
- Self-assessment submission
- Admin panel with user/questionnaire management
- FHIR-compliant API (Questionnaire, QuestionnaireResponse, metadata)
- Export data as CSV
- Looker Studio integration

## Setup
1. Import `init.sql` into MySQL
2. Update DB credentials in `config.php`
3. Download AdminLTE into `assets/adminlte/`
4. Deploy to Apache server with PHP

## Looker Studio
Connect the MySQL database to Google Looker Studio for visualization.


Here’s a **Server Configuration & Deployment Guide** for your **EPSS Self-Assessment Web App** on a standard LAMP stack with HTTPS and Looker Studio integration.

---

# 🌍 EPSS Self-Assessment Deployment Guide

## 1. Server Setup

### Recommended Environment

* **OS:** Ubuntu 22.04 LTS (or any Linux distribution with LAMP support)
* **Stack:** Apache 2.4+, PHP 8.1+, MySQL 8+
* **Domain:** `https://epss.systemsdelight.com`
* **SSL:** Let’s Encrypt (Certbot)

---

## 2. Install LAMP Stack

```bash
# Update server
sudo apt update && sudo apt upgrade -y

# Install Apache
sudo apt install apache2 -y

# Install MySQL
sudo apt install mysql-server -y

# Secure MySQL
sudo mysql_secure_installation

# Install PHP + extensions
sudo apt install php libapache2-mod-php php-mysql php-xml php-mbstring php-curl unzip -y
```

Verify versions:

```bash
php -v
mysql --version
apache2 -v
```

---

## 3. Database Setup

1. Log into MySQL:

   ```bash
   sudo mysql -u root -p
   ```

2. Create database and user:

   ```sql
   CREATE DATABASE epss_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'epss_user'@'localhost' IDENTIFIED BY 'StrongPassword123!';
   GRANT ALL PRIVILEGES ON epss_db.* TO 'epss_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. Import schema:

   ```bash
   mysql -u epss_user -p epss_db < init.sql
   ```

---

## 4. Application Deployment

1. Clone or upload repo:

   ```bash
   cd /var/www/
   sudo git clone https://github.com/khoppenworth/HRassess.git epss-self-assessment
   ```

2. Set permissions:

   ```bash
   sudo chown -R www-data:www-data /var/www/epss-self-assessment
   sudo chmod -R 755 /var/www/epss-self-assessment
   ```

3. Update DB credentials in `config.php`.

---

## 5. Apache Configuration

Create a new site config:

```bash
sudo nano /etc/apache2/sites-available/epss.conf
```

Add:

```apache
<VirtualHost *:80>
    ServerName epss.systemsdelight.com
    DocumentRoot /var/www/epss-self-assessment

    <Directory /var/www/epss-self-assessment>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/epss_error.log
    CustomLog ${APACHE_LOG_DIR}/epss_access.log combined
</VirtualHost>
```

Enable and reload:

```bash
sudo a2ensite epss.conf
sudo a2enmod rewrite
sudo systemctl restart apache2
```

---

## 6. Enable HTTPS with Let’s Encrypt

```bash
sudo apt install certbot python3-certbot-apache -y
sudo certbot --apache -d epss.systemsdelight.com
```

Auto-renewal:

```bash
sudo systemctl enable certbot.timer
```

---

## 7. AdminLTE Integration

1. Download AdminLTE 3:

   ```bash
   cd /var/www/epss-self-assessment/assets/
   wget https://github.com/ColorlibHQ/AdminLTE/archive/refs/heads/master.zip
   unzip master.zip
   mv AdminLTE-master adminlte
   rm master.zip
   ```

2. Ensure assets load correctly via `/assets/adminlte/`.

---

## 8. Looker Studio Integration

1. Ensure MySQL is exposed for secure remote access:

   ```bash
   sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
   ```

   Change:

   ```
   bind-address = 0.0.0.0
   ```

2. Allow external connection for Looker Studio:

   ```sql
   GRANT SELECT ON epss_db.* TO 'epss_user'@'%' IDENTIFIED BY 'StrongPassword123!';
   FLUSH PRIVILEGES;
   ```

3. Restart MySQL:

   ```bash
   sudo systemctl restart mysql
   ```

4. Connect Looker Studio:

   * Data Source → MySQL
   * Host: `<server-ip-or-domain>`
   * Database: `epss_db`
   * User: `epss_user`
   * Password: `StrongPassword123!`

---

## 9. Security Hardening

* Disable root login for MySQL remote connections.
* Use **UFW firewall**:

  ```bash
  sudo ufw allow 'Apache Full'
  sudo ufw enable
  ```
* Regularly patch system:

  ```bash
  sudo apt update && sudo apt upgrade -y
  ```

---

## 10. Verification Checklist

✅ Login works (`/index.php`)
✅ Staff can submit assessment (`/submit_assessment.php`)
✅ Admin can manage users & questionnaires (`/admin/`)
✅ FHIR endpoints functional:

* `/fhir/metadata.php`
* `/fhir/Questionnaire.php`
* `/fhir/QuestionnaireResponse.php`

✅ Looker Studio pulls data from `epss_db`

---

Would you like me to **embed this guide into your repo’s README.md** (so it appears directly on GitHub), or keep it as a separate file like `DEPLOYMENT.md`?

