# LinkHawk — Broken Link Checker

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)
![Version](https://img.shields.io/badge/Version-1.1.0-brightgreen)
![License](https://img.shields.io/badge/License-GPLv2-orange)
![Author](https://img.shields.io/badge/Author-Kamlesh%20Kumar%20Jangir-informational)

> Automatically scan your WordPress site for broken links and images, then fix them with 301 redirects — no coding required.

---

## Overview

**LinkHawk** is a lightweight, production-ready WordPress plugin that crawls all your published posts and pages, detects broken links and broken images (404s, timeouts, server errors), and lets you fix them instantly by adding 301 redirects — right from the WordPress admin.

No external API. No SaaS subscription. Runs entirely on your own server.

---

## Features

### Broken Link & Image Scanner
- Scans all published **posts and pages** automatically
- Extracts every `<a href>` link **and** `<img src>` image from post content (including shortcode output)
- Checks each URL using `wp_remote_head()` with GET fallback for 405 responses
- Detects: `404 Not Found`, `5xx Server Errors`, `Timeouts`, `Connection Errors`
- Skips: `mailto:`, `tel:`, `#anchors`, `javascript:`, `data:` — no false positives
- Configurable **request timeout** (5–60 seconds)
- **Excluded domains** — skip trusted CDNs or internal domains
- **URL Ignore List** — permanently skip specific URLs from all future scans

### Real-Time Scan Progress
- **Chunked AJAX scan** — scans one post at a time, no PHP timeout risk
- Live progress bar showing current post being scanned
- Final summary: posts scanned · broken links found

### Admin Dashboard
- **Premium stat cards**: Total Broken · Affected Pages · 404s · Timeouts/Errors · Broken Images · Last Scan
- Full broken links table with **Type badge** (Link / Image) column
- **Colour-coded status badges**: red (404), orange (timeout/error), dark red (5xx)
- **Scan Now** button — real-time AJAX progress, no page reload
- **Bulk dismiss** selected rows in one click
- **Export to CSV** — UTF-8 BOM for Excel compatibility
- **Ignore URL** — skip a link in all future scans (one click)
- Paginated results (configurable rows per page)

### 301 Redirect Manager
- Add **permanent redirects** from broken URLs to working ones
- Works for both **broken links and broken images**
- One-click **"Add 301"** from the broken links table — source URL auto-fills
- Tracks **hit count** per redirect so you know which ones are active
- **Search redirects** by source or target URL
- **Sortable columns**: Source URL · Target URL · Hits · Created date
- **Paginated** results (15 per page)
- Delete redirects with instant AJAX removal

### Auto Cron
- Daily automatic scan using WordPress `wp_schedule_event`
- Runs silently in the background — zero manual work
- Settings page shows next scheduled run countdown

### Settings Page
- Request timeout (seconds)
- Rows per page
- Excluded domains list (one per line)
- Email notifications — get alerted when broken links are found
- Custom notification email address
- Live cron schedule status + next run countdown
- **Ignored URLs** table — view and remove ignored URLs

### Developer Friendly
- Pure PHP + vanilla JS — no React, no npm, no build step
- WordPress nonces on every AJAX action
- All inputs sanitized, all outputs escaped
- `wpdb` with `$wpdb->prepare()` for all database queries — no raw SQL
- `uninstall.php` — clean removal of all tables and options

---

## Screenshots

| Dashboard | Redirect Manager | Settings |
|-----------|-----------------|----------|
| Premium stat cards + broken links table with type badges, bulk actions, and ignore | Add/manage 301 redirects with search, sort, pagination, and hit tracking | Configure timeout, notifications, excluded domains, and ignored URLs |

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 or higher |
| PHP | 8.2 or higher |
| MySQL | 5.7 or higher |
| Browser | Any modern browser |

---

## Installation

### Method 1 — Upload via WordPress Admin
1. Download the latest release ZIP from [GitHub Releases](../../releases)
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Choose the ZIP file and click **Install Now**
4. Click **Activate Plugin**

### Method 2 — Manual FTP/cPanel Upload
1. Unzip the downloaded file
2. Upload the `LinkHawk` folder to `/wp-content/plugins/`
3. Go to **WordPress Admin → Plugins**
4. Find **LinkHawk - Broken Link Checker** and click **Activate**

### Method 3 — LocalWP / XAMPP (Development)
1. Copy the `LinkHawk` folder into your local site's `/wp-content/plugins/` directory
2. Open WordPress admin and activate the plugin under **Plugins**

> On activation, the plugin automatically creates three database tables and schedules a daily cron scan.

---

## Usage

### Run Your First Scan
1. Go to **WordPress Admin → LinkHawk**
2. Click the **Scan Now** button
3. Watch the real-time progress bar as each post is scanned
4. Review broken links and images in the table

### Fix a Broken Link or Image with a 301 Redirect
1. Find the broken item in the dashboard table
2. Click **Add 301** on that row
3. You'll be taken to the Redirect Manager with the source URL pre-filled
4. Enter the target URL and click **Add Redirect** — done

### Ignore a URL
1. Click the **Ignore** button on any broken link row
2. The URL is added to the Ignored List and skipped in all future scans
3. To re-enable it, go to **Settings → Ignored URLs** and click **Remove**

### Configure Settings
Go to **LinkHawk → Settings** to adjust:
- Scan timeout
- Email notifications
- Excluded domains
- Rows per page
- View / remove ignored URLs

### Export Broken Links
Click the **Export CSV** button at the top of the dashboard to download all broken links as a spreadsheet.

---

## Database Tables

The plugin creates three tables on activation:

```sql
-- Stores detected broken links and broken images
wp_linkhawk_links (
    id, post_id, post_title, post_url,
    broken_url, anchor_text, http_status, link_type, detected_at
)

-- Stores 301 redirect rules
wp_linkhawk_redirects (
    id, source_url, target_url, hit_count, created_at
)

-- Stores permanently ignored URLs
wp_linkhawk_ignored (
    id, url, url_hash, ignored_at
)
```

All three tables are **completely removed** when you delete the plugin (via `uninstall.php`).

---

## File Structure

```
LinkHawk/
├── linkhawk.php                  # Main plugin file (bootstrap + constants)
├── uninstall.php                 # Clean removal on delete
├── includes/
│   ├── database.php              # DB table creation, CRUD helpers
│   ├── scanner.php               # Link/image extraction + HTTP checking
│   └── redirects.php             # 301 redirect handler (init hook)
├── admin/
│   ├── menu.php                  # Admin menu + asset enqueue
│   ├── ajax.php                  # All AJAX action handlers
│   └── views/
│       ├── dashboard.php         # Broken links/images page
│       ├── redirects.php         # Redirect manager page
│       └── settings.php          # Settings + ignored URLs page
└── assets/
    ├── css/admin.css             # Admin styles
    └── js/admin.js               # AJAX + UI interactions
```

---

## Frequently Asked Questions

**Does this plugin slow down my site?**
No. The scanner only runs when you click "Scan Now" or via the daily cron — never on frontend page loads.

**Does it work with external URLs?**
Yes. LinkHawk checks all `http://` and `https://` links and images — both internal and external.

**Does it check images too?**
Yes. From v1.1.0, LinkHawk scans `<img src>` tags in post content in addition to `<a href>` links. Broken images appear in the dashboard with an "Image" badge.

**What happens to anchors like `#section`?**
They are automatically skipped — anchor-only links are never checked.

**Can I skip links from specific domains like my CDN?**
Yes. Go to **Settings → Excluded Domains** and add one domain per line.

**Can I permanently ignore a specific URL?**
Yes. Click **Ignore** on any row in the dashboard. The URL is added to the Ignored List and will never be flagged again. Remove it from **Settings → Ignored URLs** to re-enable checking.

**Are redirects permanent?**
Yes. All redirects send a `301 Moved Permanently` header, which is correct for SEO.

**Will deleting the plugin remove my data?**
Yes. The `uninstall.php` file drops all three database tables and removes all plugin options when you delete the plugin from the admin.

**My scan finds 0 results — what's wrong?**
Make sure you have at least one published post or page with `<a href>` links or `<img src>` images in the content.

---

## Changelog

### v1.1.0 — 2026-04-08
- **New**: Real-time chunked AJAX scan with live progress bar (no PHP timeout)
- **New**: Broken image detection — `<img src>` tags now scanned alongside links
- **New**: URL Ignore List — permanently skip specific URLs from all scans
- **New**: Search, sortable columns, and pagination for the Redirect Manager
- **New**: "Add 301" button works for broken images too
- **New**: Premium stat cards with gradient accents and hover animations
- **Fix**: 301 redirect not firing when source URL was stored as full URL but request came as path (or vice versa) — now matches 4 URL variants
- **Fix**: Delete button on Redirect Manager not working — nonce now embedded directly on each row
- **Refactor**: Full rename from LinkGuard → LinkHawk across all constants, DB tables, options, page slugs, and text domain

### v1.0.0 — Initial Release
- Broken link scanner with HEAD + GET fallback
- Admin dashboard with stats, badges, bulk actions
- 301 Redirect Manager with hit tracking
- CSV export
- Paginated results
- Settings page (timeout, excluded domains, email alerts)
- Daily auto-scan via WP cron
- `uninstall.php` for clean removal

---

## Contributing

Pull requests are welcome! For major changes, please open an issue first to discuss what you would like to change.

1. Fork the repository
2. Create your feature branch: `git checkout -b feature/my-feature`
3. Commit your changes: `git commit -m "Add: my feature"`
4. Push to the branch: `git push origin feature/my-feature`
5. Open a Pull Request

---

## License

This plugin is licensed under the [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html) — the same license as WordPress itself.

---

## Author

**Kamlesh Kumar Jangir**
- Website: [trsoftech.com](https://trsoftech.com)
- GitHub: [@bharatkabharman-pixel](https://github.com/bharatkabharman-pixel)

---

<p align="center">Built with ❤️ for the WordPress community</p>
