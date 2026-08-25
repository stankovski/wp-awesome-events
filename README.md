# Awesome Events

## About

Awesome Events is a WordPress plugin for turning ordinary posts into scheduled events. It adds event date, time, duration, location, and recurrence fields without introducing a separate event post type.

The plugin provides:

- Daily, weekly, monthly, and yearly recurrence, including custom intervals, selected weekdays, end dates, and occurrence limits.
- Gutenberg blocks for event dates, live countdowns, and add-to-calendar actions.
- Shortcodes for displaying event dates, times, and locations in themes and other content.
- A site-wide `/events.ics` subscription feed and downloadable calendar files for individual events.
- An authenticated `GET /wp-json/icob/v1/event-posts` endpoint for editor integrations.

Awesome Events requires WordPress 6.0 or newer and PHP 8.0 or newer.

## Dev Start

The local environment uses Docker Compose and includes WordPress, MySQL, phpMyAdmin, and Xdebug.

Prerequisites:

- Docker Desktop
- `docker-compose`

From the repository root, bootstrap the environment:

```sh
./scripts/bootstrap.sh
```

Once setup completes, open:

- WordPress: <http://localhost:8000>
- phpMyAdmin: <http://localhost:8080>

Activate **Awesome Events** from the WordPress Plugins screen if it is not already active. Plugin source is bind-mounted from `wp-content/plugins/awesome-events`, so PHP, JavaScript, and CSS edits are reflected in the running container.

Useful commands:

```sh
# Check PHP syntax and the official WordPress plugin review rules.
./scripts/validate.sh

# Build the WordPress.org upload archive at package.zip.
./scripts/build-package.sh

# Stop the development environment.
docker-compose down
```

The validation script uses the official [Plugin Check](https://wordpress.org/plugins/plugin-check/) ruleset installed at `wp-content/plugins/plugin-check`. Set `PLUGIN_CHECK_DIR` when Plugin Check is installed elsewhere. Run validation before building a release package.

## Design

`awesome-events.php` is the plugin bootstrap. It loads the feature classes from `wp-content/plugins/awesome-events/includes` during WordPress initialization and registers the plugin lifecycle and block-category hooks.

The plugin is organized around these responsibilities:

- **Event model:** `Awesome_Events_Event_Meta` registers post metadata, renders and saves the editor controls, and calculates upcoming recurring occurrences. Existing `_icob_event_*` meta keys are intentionally retained for backward compatibility.
- **Presentation:** server-registered event date and countdown blocks are paired with editor and frontend assets under `assets`. Shortcodes expose the same event data to classic content and templates.
- **Calendar output:** the ICS generator is shared by the site-wide `/events.ics` feed, single-event downloads, and add-to-calendar links.
- **Editor integration:** the `icob/v1/event-posts` REST route returns published event posts to authenticated users who can edit posts. The historical namespace is retained for existing consumers.
- **WordPress integration:** features are registered through standard actions, filters, rewrite rules, block APIs, REST APIs, and post-meta APIs. Activation and deactivation flush rewrite rules for calendar endpoints.

The plugin does not create a custom post type or duplicate event records. WordPress posts and their metadata are the canonical data source, and recurrence instances are calculated when needed.

