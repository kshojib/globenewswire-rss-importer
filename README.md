# GlobeNewswire RSS Importer

A WordPress plugin that automatically imports press releases from a GlobeNewswire RSS feed into a custom post type, with full duplicate prevention and [Page Links To](https://wordpress.org/plugins/page-links-to/) support.

## Features

- Imports items from any GlobeNewswire RSS feed into a `press-releases` custom post type
- Each imported post is automatically linked to the original GlobeNewswire article via **Page Links To** (opens in a new tab)
- Configurable **feed URL** and **auto-import schedule** from the admin settings page
- **Duplicate prevention** across three layers:
  - GUID matching (for previously imported posts)
  - Source URL matching (`_gnw_source_url`, `_links_to`)
  - Exact post title matching (catches manually-added posts with no meta)
- **Import log** — last 50 runs shown in the admin page with status, imported/skipped counts, and error messages
- Manual "Run Import Now" button in the admin UI
- Scheduled import via WP-Cron (hourly, twice daily, daily, or weekly)
- CSRF protection (nonces) and capability checks on all admin actions

## Requirements

- WordPress 5.0+
- PHP 7.4+
- The `press-releases` custom post type registered by your theme or another plugin
- [Page Links To](https://wordpress.org/plugins/page-links-to/) plugin (optional — meta fields are saved regardless)

## Installation

1. Upload the `globenewswire-rss-importer` folder to `/wp-content/plugins/`
2. Activate the plugin in **Plugins → Installed Plugins**
3. Go to **Tools → GlobeNewswire Import** to configure settings

## Configuration

Navigate to **Tools → GlobeNewswire Import**:

| Setting | Description |
|---|---|
| **Feed URL** | The GlobeNewswire RSS feed URL to import from |
| **Auto-Import Schedule** | How often WP-Cron runs the import (hourly / twice daily / daily / weekly) |

The next scheduled cron run time is displayed below the schedule dropdown.

## How Duplicate Prevention Works

When an import runs, each feed item is checked against existing `press-releases` posts in three ways (any match skips the item):

1. `_gnw_guid` meta — matches the RSS item's GUID
2. `_gnw_source_url` / `_links_to` meta — matches the item's URL
3. Exact `post_title` match — catches manually-created posts that have no importer meta

## Post Meta Saved

| Meta Key | Value |
|---|---|
| `_gnw_guid` | RSS item GUID |
| `_gnw_source_url` | Original GlobeNewswire article URL |
| `_links_to` | Same URL (Page Links To plugin) |
| `_links_to_target` | `custom` |
| `_links_to_target_new_window` | `1` (opens in new tab) |

## Import Log

The admin page shows the last 50 import runs with:
- Date / time
- Status (Success / Error)
- Number of posts imported
- Number of posts skipped as duplicates
- Full message (including error details if the feed failed to load)

Use the **Clear Log** button to reset the log.

## License

GPL-2.0-or-later
