# 🛠️ PHP Dev Toolkit (Database & Utility Tools)

**Author:** Khurshid ([urbanisuz](https://github.com/urbanisuz))

A collection of lightweight, standalone, and secure PHP (7.4+) utilities designed for database administration, data migration, and optimization tasks (specifically tailored for OpenCart, custom CMS, and bespoke PHP backends).

> **⚠️ Note:** This toolkit is **actively growing** with new scripts and utilities being added regularly as projects evolve!

---

## 📂 Tools & Structure

### 1. 🔄 Find & Replace (`database/find_and_replace.php`)
* **Purpose:** Bulk find and replace text within a specific table column with advanced filtering via `WHERE` clauses.
* **Features:** Supports both standard `LIKE` search and regular expressions (**PCRE** via `REGEXP`), two-step preview with diff visualization, and full control over database updates.

### 2. 🛠️ SEO Slug Generator (`database/seo_slug_generator.php`)
* **Purpose:** Automatically generates and fixes clean, URL-friendly slugs based on text columns (e.g., product or article names).
* **Features:** Includes reliable Cyrillic-to-Latin transliteration, special character stripping, and an option to process **only** empty slugs to protect existing URL structures.

### 3. 🗑️ Log & Session Cleaner (`database/database_log_and_session_cleaner.php`)
* **Purpose:** Cleans up database bloat caused by accumulated system trash, old sessions, activity logs, and history tables.
* **Features:** Automatic age-based calculation using date/time columns (in days) or custom `WHERE` conditions, with a safe two-step `COUNT` preview before deletion.

### 4. 🚚 Column Migrator (`database/column_migrator.php`)
* **Purpose:** Migrates or duplicates data seamlessly from one table column to another.
* **Features:** Handy for database schema restructuring or changing data storage logic without writing manual one-off console scripts.

### 5. 🔍 Orphan File Scanner (`database/orphan_file_scanner.php`)
* **Purpose:** Scans the server filesystem for files that are no longer linked to database records (or vice versa).
* **Features:** Helps clean up unreferenced media files and keeps storage optimized.

---

## ⚙️ Requirements
* **PHP:** 7.4 or higher (tested up to PHP 8.x).
* **Extensions:** PDO, MySQL.

## 🚀 Usage
Each script is completely standalone. Simply configure your database credentials directly inside the utility file, upload it to your server, and open it in your browser.
