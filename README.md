# Data Retention Policy Manager

The Data Retention Policy Manager plugin allows site administrators to configure automatic retention policies for users, posts, and pages. Users can be disabled after a period of inactivity and later deleted, while content can be archived once it reaches a defined age.

## Features

* Admin settings page under **Settings → Data Retention** with configurable durations for:
  * Disabling inactive users.
  * Deleting users a set time after they have been disabled.
  * Archiving posts and pages after a chosen period.
* Daily cron task that processes users and content according to the configured rules.
* Prevents disabled accounts from signing in and records when content is archived.
* Adds retention status and last active columns to the user list and provides a quick action to re-enable accounts.
* Supports customisation through filters for excluded roles and batch sizes.

## Installation

1. Copy the plugin folder to `wp-content/plugins/` on your WordPress installation.
2. Activate **Data Retention Policy Manager** from the Plugins screen.
3. Navigate to **Settings → Data Retention** to configure the retention durations that suit your organisation.

## Configuration

Durations can be expressed in days, months (30 days), or years (365 days). Leave any value at zero to disable that specific policy. User deletion requires that a disable duration is also set. All times are calculated based on the last recorded user activity (login) and the original publish date for posts and pages.

### Filters

* `drp_excluded_roles` — Modify the list of user roles excluded from retention processing. Defaults to `['administrator']`.
* `drp_batch_size` — Adjust the batch size used when querying for eligible users or content. Receives the default value and a context string such as `disable_users`, `delete_users`, `archive_post`, or `archive_page`.

## Uninstalling

Deactivating the plugin stops the scheduled cron task. Removing the plugin will clear the stored plugin settings and associated user metadata.
