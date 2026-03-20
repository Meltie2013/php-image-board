Installer / Updater
===================

Entry point:
- /install/index.php

What it does:
- Provides a separate login for installation and updates (stored in /config/installer_auth.php)
- Runs the first-time installer as a guided step-by-step flow:
  1. Requirements
  2. Filesystem preparation
  3. Configuration / database connection testing
  4. Base database installation
- Creates required folders:
  - /storage/cache/images
  - /storage/cache/templates
  - /storage/packages/updater
  - /images/original
- Writes /config/config.php based on /config/config.php.dist while preserving comments
- Installs /install/base_database.sql
- Writes /install/installer.lock after a successful base install so the first-run installer stays blocked
- Keeps the updater available after install for:
  - Live runtime configuration that still reflects the board directly
  - Config merges from config.php.dist so new keys can be added without overwriting existing values
  - SQL updates from /database/updates/*.sql
  - Package archive staging (zip / tar / tar.gz / tgz) for future file-based upgrade workflows

Security model:
- Dedicated installer/updater login separate from the main site users
- CSRF protection for all POST actions
- Basic installer login rate limiting
- Lock file blocks first-run installer pages after successful installation
- Package uploads are staged in non-public storage under /storage/packages/updater

After install:
- REMOVE or RESTRICT /install for security where possible
- Keep /config/installer_auth.php only if you still want updater access
- Keep installer.lock in place so the first-run install flow stays disabled
