# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin Overview

**Medical Statistics** — a WordPress plugin (v1.5.4) for healthcare professionals to track, analyze, and visualize lab results. Requires WordPress 6.0+ and PHP 8.0+.

No build system, no Composer, no NPM. All external JS (Chart.js v4.4.3, chartjs-plugin-annotation v3) is loaded from CDN.

## Development Commands

There is no automated test suite or linting configuration in this project. Development is done directly against a WordPress installation.

To check PHP syntax manually:
```bash
php -l medical_statistics.php
php -l includes/db-setup.php
php -l includes/class-pdf-parser.php
php -l includes/class-import-csd.php
```

## Architecture

### Entry Point & Hooks

`medical_statistics.php` is the single entry point. It uses the `MedicalStatistics` namespace and registers all WordPress hooks directly (no class wrapper):

- **Activation:** creates DB tables + `medical_statistics` custom role
- **`wp_enqueue_scripts`:** enqueues CSS/JS only for users with `medical_statistics` capability; uses `filemtime()` for cache-busting
- **`wp_ajax_*`:** 10 AJAX handlers for all data operations
- **Shortcodes:** `[medical_statistics]` → `views/order.php`, `[medical_analit]` → `views/med_analit.php`

### Database (3 custom tables)

Defined in `includes/db-setup.php`:

- `wp_med_indicator` — catalog of medical indicators (name, min/max reference range, unit, category)
- `wp_med_ordering` — patient lab orders (order number, patient info, collection date)
- `wp_med_measurement` — individual results linking orders and indicators; FK with CASCADE delete on order removal

Migration logic runs on `plugins_loaded` via `med_stat_maybe_migrate()`.

### AJAX Security Pattern

Every AJAX handler calls `ajax_guard()` first, which verifies WordPress nonce (`med_stat_nonce`) and checks `current_user_can('medical_statistics')`. All DB queries use `$wpdb->prepare()`.

### PDF Import Pipeline

`includes/class-pdf-parser.php` → `PdfParser` (static methods): extracts text items with `[page, x, y, text]` coordinates from raw PDF streams; handles zlib-compressed streams and UTF-16/ISO-8859-1 encodings.

`includes/class-import-csd.php` → `ImportCsd`: uses positional data from `PdfParser` to detect 4-column layout (Indicator | Value | Unit | Reference), parse patient header, extract measurements, and insert into DB. Throws `DuplicateOrderException` (carrying the existing `$orderId`) if order number already exists.

Order numbers follow the pattern `CS[0-9]{6,}`.

### Frontend

`views/order.php` — full sidebar + detail UI. All interactions (pagination, search, inline edit, PDF upload, new order form) are handled via AJAX in `js/med_stat.js` using jQuery.

`views/med_analit.php` — minimal chart container; `js/med_analit.js` uses Chart.js to render a line chart with reference range annotation bands.

## Key Conventions

- All DB table names accessed as `$wpdb->prefix . 'med_indicator'` etc.
- `is_normal` in `wp_med_measurement` is computed at import time: `1` if value is within indicator's min/max range, `0` otherwise.
- Asset versions use `filemtime(MED_STAT_DIR . 'css/...')` — edit any CSS/JS file and the browser will pick up the change without manual version bumps.
- The `medical_statistics` WordPress capability is both the role name and the capability string checked throughout.