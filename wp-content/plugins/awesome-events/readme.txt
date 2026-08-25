=== Awesome Events ===
Contributors: stankovski
Tags: events, calendar, ics, recurrence, countdown
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.0.0
Requires PHP: 8.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage event dates and recurring schedules, publish calendar feeds, display countdowns, and let visitors add events to their calendar.

== Description ==

Awesome Events turns ordinary posts into full-featured events. Give a post a date, time, and optional recurrence rule, and Awesome Events takes care of the rest: subscribable calendar feeds, downloadable single-event invites, live countdowns, and one-click "Add to Calendar" links.

= Features =
* Event date field with optional start/end time, added to any post
* Flexible recurrence rules: daily, weekly, monthly, or yearly, with custom intervals and end conditions (until a date or after a number of occurrences)
* Weekly recurrence supports specific weekdays (e.g. every Monday and Wednesday)
* Site-wide `/events.ics` calendar feed that subscribers can add to Google Calendar, Apple Calendar, or Outlook
* Per-event `.ics` download so visitors can add a single event to their calendar
* "Add to Calendar" button block with Google, Apple, Outlook, and Office 365 options
* Event date block and event countdown block for the block editor
* Shortcodes for displaying event dates and countdowns outside the block editor
* REST API endpoint for querying event posts programmatically

== Installation ==

1. Upload the `awesome-events` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Edit a post and set an event date (and optional recurrence) in the Event Date panel
4. Subscribe to `yourdomain.com/events.ics` in a calendar app to see all events

== Frequently Asked Questions ==

= How do I make an event recurring? =

When setting the event date on a post, enable recurrence and choose a frequency (daily, weekly, monthly, or yearly), then set an end condition such as an end date or a number of occurrences.

= Where can people subscribe to all events at once? =

Point any calendar app at `yourdomain.com/events.ics`. The feed updates automatically as events are added, changed, or removed.

= Can visitors download a single event instead of subscribing to the whole feed? =

Yes, each event with a date has a downloadable `.ics` file that can be linked to individually.

== Changelog ==

= 1.0.0 =
* Initial release
