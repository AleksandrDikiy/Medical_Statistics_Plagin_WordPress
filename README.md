# 🏥 Medical Statistics — WordPress Plugin

> A specialized WordPress tool for healthcare professionals to track, analyze, and visualize medical statistics with secure data handling.

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## 📋 Table of Contents
- [About](#about)
- [Key Features](#key-features)
- [Architecture & Security](#architecture--security)
- [Roadmap](#-project-roadmap)
- [Installation](#installation)
- [License](#license)

---

## About
**Medical Statistics** is designed to transform WordPress into a lightweight medical reporting system. It allows clinics or individual practitioners to log patient metrics, track treatment dynamics, and generate statistical reports.

Built with **PHP 8.x** and optimized for the WordPress admin environment, the plugin ensures that complex medical data is structured, searchable, and visually interpretable.

---

## Key Features

### 📊 Statistical Analysis
- Record and categorize medical metrics and patient data.
- Dynamic filtering by timeframes, medical departments, or specific indicators.
- Visual reporting dashboard for tracking healthcare trends.

### 🔐 Healthcare Data Security
- Role-based access control (RBAC) to ensure only authorized personnel view sensitive data.
- Sanitized data entry to prevent common web vulnerabilities.
- Optimized MySQL tables with indexing for rapid reporting on large datasets.

---

## 🚀 Project Roadmap

### Phase 1: Core Reporting (In Progress)
- [ ] **Data Export:** Implementation of PDF/Excel report generation for clinical documentation.
- [ ] **Advanced Filtering:** Multi-layered filters for complex medical queries.
- [ ] **PHP 8.2+ Refactoring:** Leveraging modern PHP features for better memory management.

### Phase 2: Visualization & UI
- [ ] **Chart Integration:** Adding interactive graphs for patient recovery dynamics.
- [ ] **Admin UI Overhaul:** Creating a more intuitive, "clean room" interface for medical staff.

---

## 🚀 Installation

1. **Upload**: Upload the `medical-statistics` folder to the `/wp-content/plugins/` directory.
2. **Activate**: Activate the plugin through the 'Plugins' menu in WordPress.
3. **Usage**: Access the 'Medical Stats' menu in the WP admin dashboard to start logging data.

---

## Architecture & Security
The plugin follows the **Singleton pattern** for core initialization and uses a dedicated **Database Wrapper** for all medical record interactions to ensure integrity. All queries are strictly prepared using `$wpdb->prepare()`.

---

## License
Released under the [GPL-2.0 License](https://www.gnu.org/licenses/gpl-2.0.html).