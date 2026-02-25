Installer / Updater
===================

Entry point:
- /install/index.php

What it does:
- Provides a separate login for installation and updates (stored in /config/installer_auth.php)
- Creates required folders:
  - /cache/images
  - /cache/templates
  - /images/original
- Writes /config/config.php based on /config/config.php.dist while preserving comments
- Installs /install/base_database.sql
- Applies updates found in /updates/*.sql (tracks applied updates in app_updates)

After install:
- REMOVE or RESTRICT /install for security.
- You may also remove /config/installer_auth.php if you no longer need the updater.

