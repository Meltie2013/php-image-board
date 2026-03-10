# PHP Image Gallery

A security-focused PHP image gallery and image board application built with PHP 8.1+, MySQL, moderation tooling, duplicate image detection, age-restricted content controls, administrative security tools, and a background maintenance server.

This project is designed for practical real-world deployment, with a strong emphasis on security, maintainability, and operational control.

---

## Overview

PHP Image Gallery provides a complete image-sharing platform with:

- User registration and authentication
- Public gallery browsing and pagination
- Image uploads with duplicate detection
- Favorites and up-voting
- Comment support
- Profile and avatar management
- Moderation workflows
- Administrative controls
- Request protection and security logging
- A CLI maintenance server for real-time housekeeping and maintenance state management

The project follows a defense-in-depth approach, combining application security, controlled uploads, rate limiting, administrative review tools, and a dedicated background process.

---

## Features

### User Features
- User registration and login
- Secure password hashing and verification
- Change account e-mail
- Change account password
- User avatar uploads
- Profile management
- Birthday / age verification workflow for restricted content
- Favorites and voting support
- Image commenting

### Gallery Features
- Public image gallery
- Pagination
- Individual image pages
- Image metadata and interaction support
- Cached image delivery for improved responsiveness

### Duplicate Image Detection
Uploads are checked with multiple hashing methods to help detect exact duplicates and near-duplicates:

- **aHash**
- **dHash**
- **pHash**

This helps reduce spam, repeated uploads, and low-value duplicate content while still allowing moderator review before final action.

### Moderation Features
- Moderation dashboard
- Pending image review queue
- Duplicate comparison workflow
- Approve / reject workflows
- Sensitive-content review support
- Rehash tooling for image maintenance

### Administrative Features
- Admin dashboard
- User management
- Site settings management
- Security log review
- Block list review and management
- Runtime maintenance controls

### Background Maintenance Server
A dedicated CLI maintenance server is included to handle recurring housekeeping and runtime state:

- Expired session cleanup
- Request-guard counter cleanup
- Temporary block cleanup
- Old security log cleanup
- Expired image cache cleanup
- Heartbeat management for site availability
- Runtime maintenance mode control
- Interactive and command-line control through `serverctl.php`

---

## Security Highlights

Security is a major focus of this project.

### Authentication and Session Security
- Strong password hashing with Argon2id
- Hardened session handling
- Session timeout support
- Secure cookie settings
- Session regeneration for sensitive flows
- Device-aware session and request tracking support

### Request Protection
- CSRF protection for state-changing actions
- Output escaping and input sanitization helpers
- Request validation across controllers
- Rate limiting and abuse controls

### Upload Security
Uploads are validated with layered checks, including:

- File size validation
- Extension allowlists
- Server-side MIME detection
- Image validation
- Dangerous file rejection
- Hash generation for duplicate detection
- Controlled file storage paths

### Abuse Resistance
The request guard system helps reduce abuse through:

- Rate limiting
- Temporary deny / block behavior
- IP-aware enforcement
- Device fingerprint support
- Security event logging
- Administrative review tools

### Browser Security
The project is structured to support stronger browser-side protections such as:

- Content Security Policy
- Clickjacking protection
- MIME sniffing protection
- Referrer policy controls
- Permissions policy controls

### Sensitive Content Controls
- Birthday-based age verification
- Restricted content handling
- Safer content delivery rules
- Moderator review for sensitive uploads

---

## Requirements

Minimum environment:

- **PHP 8.1+**
- **MySQL / MariaDB**
- **PDO MySQL**
- **Fileinfo**
- **GD**
- **Imagick**

Recommended:

- Linux-based hosting
- HTTPS-enabled web server
- Dedicated database user for the application
- Process supervision for the maintenance server

---

## Project Structure

```text
/config                  Configuration files
/controllers             Application controllers
/core                    Core classes and security/runtime systems
/helpers                 Helper classes
/templates               HTML templates
/assets                  CSS and JavaScript assets
/images/original         Stored uploaded originals
/cache/images            Generated image cache
/cache/templates         Compiled template cache
/install                 Installer / updater
/server.php              Background maintenance server
/serverctl.php           Maintenance server control client
/index.php               Main web entry point
```

---

## Installation

### 1. Extract the Project
Place the project in your web root or application directory.

Example:

```bash
unzip PHP-Image-Gallery.zip
mv PHP-Image-Gallery /var/www/html/php-image-gallery
```

### 2. Point Your Web Server to the Project
Configure your web server so the application is served from the project directory and `index.php` is the public entry point.

### 3. Open the Installer
Visit:

```text
/install/index.php
```

The installer / updater is used to:

- Write `config/config.php` from `config/config.php.dist`
- Install the base database schema from `install/base_database.sql`
- Apply update SQL files when present
- Merge new config keys from future versions

### 4. Verify Requirements
Before continuing, make sure the installer checks pass:

- PHP 8.1+
- PDO MySQL
- Fileinfo
- GD
- Imagick

### 5. Configure the Application
In the installer, complete the configuration and save it to:

```text
/config/config.php
```

Important values to review:

- Database host
- Database name
- Database user
- Database password
- Timezone
- Site name
- Maintenance server settings
- Request guard settings
- Security settings

### 6. Create / Verify Writable Folders
Make sure the following paths are writable by both your web server user and the CLI user that will run `server.php`:

```text
/cache/images
/cache/templates
/images/original
```

### 7. Install the Database
Use the installer to install the base schema from:

```text
/install/base_database.sql
```

It will also apply files in `/updates` when needed.

### 8. Secure the Installer After Setup
After installation is complete, remove or restrict installer access:

- Remove or block `/install`
- Remove or restrict installer-only access if you do not need updater access exposed

This is strongly recommended for production deployments.

---

## Running the Site

Once installation is complete:

1. Make sure `config/config.php` exists
2. Make sure the database is reachable
3. Make sure writable directories are writable
4. Make sure the maintenance server is running

Then browse to the site normally through your web server.

---

## Running the Back-End Maintenance Server

The project includes a background maintenance server that keeps housekeeping jobs running and refreshes the site heartbeat.

If maintenance server support is enabled in `config/config.php`, the public site can enter maintenance mode when the heartbeat is missing or stale.

### Start the Server
From the project root:

```bash
php server.php
```

Useful options:

```bash
php server.php --interval=1
php server.php --once
```

### What the Server Does
The maintenance server handles:

- Expired application session cleanup
- Request guard cleanup
- Security log cleanup
- Image cache cleanup
- Heartbeat updates
- Runtime maintenance state
- Local control socket commands

### Production Recommendation
For production, run the server under a process manager such as:

- systemd
- supervisor
- tmux
- screen

This helps ensure the server automatically restarts after crashes or reboots.

---

## Controlling the Back-End Server

Use `serverctl.php` to control the running maintenance server without restarting it.

### Basic Examples

```bash
php serverctl.php status
php serverctl.php pause
php serverctl.php resume
php serverctl.php run-cleanup-now
php serverctl.php maintenance-on
php serverctl.php maintenance-off
php serverctl.php set-verbose 1
php serverctl.php set-tick-interval 2
php serverctl.php set-log-retention-days 30
```

### Job Controls

```bash
php serverctl.php enable-job image_cache
php serverctl.php disable-job security_logs
php serverctl.php set-job sessions 1
```

### Interactive Mode
Run without a command:

```bash
php serverctl.php
```

This opens an authenticated interactive shell for maintenance commands.

### Control Socket Security
The maintenance control socket is intended for local use and should be reviewed carefully in `config/config.php`.

Important settings include:

- `maintenance_server.required`
- `maintenance_server.heartbeat_timeout_seconds`
- `maintenance_server.control.bind_address`
- `maintenance_server.control.port`
- `maintenance_server.control.allowed_ips`
- `maintenance_server.control.auth_token`

You should always replace the default control token with a long random secret before using the control socket.

---

## Example Deployment Flow

A typical setup flow looks like this:

1. Extract the project
2. Open `/install/index.php`
3. Save `config/config.php`
4. Install the database schema
5. Confirm writable cache and image folders
6. Remove or restrict `/install`
7. Start `php server.php`
8. Verify with `php serverctl.php status`
9. Open the site in your browser

---

## Duplicate Detection Workflow

1. User uploads an image
2. The system generates image hashes
3. The upload is compared against existing records
4. Potential duplicates are flagged
5. Moderators review the match
6. The image is approved, rejected, or handled as sensitive content

This combines automated detection with human review for better accuracy and fairness.

---

## Why These Systems Matter

### For Users
- Cleaner gallery browsing
- Better account safety
- Reduced duplicate spam
- Safer handling of restricted content

### For Moderators
- Easier duplicate review
- Better enforcement visibility
- Cleaner approval and rejection workflows

### For Administrators
- Better operational control
- Built-in security visibility
- Background cleanup outside page requests
- Live runtime maintenance control

---

## Production Notes

Before going live, review all security-sensitive configuration values in `config/config.php`, especially:

- Database credentials
- Password hashing options
- Device fingerprint secret
- Maintenance control token
- Allowed maintenance control IPs
- Request guard thresholds

Also ensure:

- HTTPS is enabled
- `/install` is restricted or removed
- File permissions are correct
- The maintenance server starts automatically after reboot

---

## License

This project is licensed under the **GNU Affero General Public License v3.0 (AGPLv3)**.  
See the `LICENSE` file for details.

### Summary
- You may use, modify, and distribute this software under AGPLv3
- Contributions via pull requests are welcome
- You may not relicense it under a closed-source or proprietary license
- You may not copy parts of this project into a non-AGPL-compatible project
- Public server deployments must provide source code to users as required by AGPLv3
