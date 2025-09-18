# SATORI Audit Plugin

![Version](https://img.shields.io/badge/version-3.7.4.8-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.8%2B-green.svg)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)
![License](https://img.shields.io/badge/license-Proprietary-red.svg)
![Build](https://github.com/satoridev-web/satori-audit/actions/workflows/php.yml/badge.svg)


**Author:** SATORI Graphics Pty Ltd

---

## Overview
SATORI Audit is a WordPress plugin designed for **monthly site health and performance audits**.
It generates structured reports, provides export options (PDF/CSV/JSON), and helps site managers track speed, security, optimization, and stability over time.

---

## Features
- ✅ **Run Audit Now** and **Test Audit** buttons under **Tools → SATORI Audit**
- ✅ **Scheduling**: Daily, Weekly, or Monthly audit runs (WP-Cron based)
- ✅ **Audit Log**: Displays last 5 runs (with 12-month retention fallback)
- ✅ **Export**: JSON, PDF (via DOMPDF), and CSV
- ✅ **Integration**: Hooks for Beaver Builder, WooCommerce, Gravity Forms, FIFU
- ✅ **Debug Badge** (toggleable)

---

## Folder Structure
See [`folder-structure.txt`](./folder-structure.txt) for a snapshot of the plugin layout.

Key directories:
- `inc/` → Core audit logic and add-ons
- `satori-audit-lib/` → Libraries (DOMPDF and dependencies)
- `docs/` → Project documentation (CHANGELOG, ROADMAP, etc.)

---

## Requirements
- WordPress 6.8+
- PHP 8.1+
- LiteSpeed / Apache server (recommended, but works broadly)

---

## Installation
1. Copy `satori-audit/` into your `wp-content/plugins/` directory.
2. Activate via **Plugins → Installed Plugins**.
3. Navigate to **Tools → SATORI Audit** to run your first audit.

---

## Development
- GitHub Repo: [satoridev-web/satori-audit](https://github.com/satoridev-web/satori-audit)
- Branching model:
  - `main` → stable releases
  - `develop` → in-progress features/fixes

---

## Roadmap
See [ROADMAP.md](./docs/ROADMAP.md) for upcoming features.

---

## License
This plugin is part of the SATORI Suite. All rights reserved © SATORI Graphics Pty Ltd.
