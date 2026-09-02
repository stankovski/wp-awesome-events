# Awesome Calendar Events — Public Events Query API

A read-only JSON API for 3rd-party plugins and consumers to query posts
that carry event data, filtered by category, tags and datetime.

```
GET /wp-json/awecal/v1/events
```

- **Authentication:** none required. Only `publish` status posts are ever
  returned, so the route is public read by default (parity with the
  plugin's public ICS feeds). Sites can lock the route down via the
  `awesome_calendar_events_api_permission_callback` filter (e.g. require
  Application Passwords).
- **Media type:** `application/json; charset=UTF-8`
- **Pagination:** cursor tokens (see [Pagination](#pagination)).

---

## Query parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `categories` | CSV of slugs | — | Category slugs; posts matching **any** of them. Max 50. |
| `tags` | CSV of slugs | — | Tag slugs; combined per `tag_operator`. Max 50. |
| `tag_operator` | `any` \| `all` | `any` | `all` requires posts to carry every listed tag. |
| `date_from` | `YYYY-MM-DD` | — | Inclusive lower bound of the datetime filter. |
| `date_to` | `YYYY-MM-DD` | — | Inclusive upper bound of the datetime filter. |
| `expand_recurring` | bool | `false` | Expand recurring events into occurrence instances (see [Expansion mode](#expansion-mode-expand_recurringtrue)). |
| `search` | string | — | Standard WordPress search term. |
| `include_details` | bool | `false` | Include post details (`excerpt`, `imageUrl`, `imageAlt`, `fullBody`, `categories`, `tags`). |
| `page_token` | string | — | Opaque pagination cursor from the `X-WP-NextPageToken` header. |
| `per_page` | int 1–100 | `20` | Number of items per page. |
| `orderby` | `event_date` \| `date` \| `title` | `event_date` | Sort key. In expansion mode this is the secondary sort (occurrence date always sorts first). |
| `order` | `asc` \| `desc` | `asc` | Sort direction. |

Invalid values are rejected with `400 rest_invalid_param`. `date_from`
must not be after `date_to`.

### Datetime filter semantics

- **Collapsed mode** (default): the filter applies to the **original
  event date** (`_awecal_event_date`, legacy `_icob_event_date`) for both
  one-off and recurring events. Recurring events are returned **once** —
  occurrences are never expanded server-side.
- **Expansion mode** (`expand_recurring=true`): the filter applies to
  **occurrences**. A weekly event that started years ago still matches,
  and yields one item per occurrence inside the window.

Events that can no longer produce results are always excluded:

- Recurring events whose `_awecal_event_recurrence_end_date` (legacy
  `_icob_event_recurrence_end_date`) has already passed (filtered in SQL).
- Count-limited recurring events whose occurrences are exhausted
  (`_awecal_event_recurrence_count`, resolved in memory).

---

## Response

The body is a JSON **array of items**. Metadata is delivered in headers:

| Header | Description |
|---|---|
| `X-WP-Total` | Total number of items across all pages. |
| `X-WP-TotalPages` | Total number of pages. |
| `X-WP-NextPageToken` | Cursor for the next page. **Absent on the last page.** |

### Minimal item (default)

```json
{
  "postId": 123,
  "title": "Summer Concert",
  "snippet": "An evening of jazz in the park…",
  "url": "https://example.org/summer-concert/",
  "publishedAt": "2026-08-20T14:05:00+00:00",
  "announcement": false,
  "announcementEndDateTime": null,
  "event": {
    "date": "2026-09-04",
    "startTime": "18:30",
    "durationHours": 2.0,
    "location": "City Park",
    "recurrenceRule": "FREQ=WEEKLY;BYDAY=FR"
  }
}
```

| Field | Description |
|---|---|
| `postId` | Post ID. |
| `title` | Post title. |
| `snippet` | Post excerpt if set, otherwise the first words of the stripped content. |
| `url` | Permalink. |
| `publishedAt` | ISO 8601 publication timestamp (from `post_date_gmt`). |
| `announcement` | Whether the post is flagged as an announcement. |
| `announcementEndDateTime` | ISO 8601 expiration, or `null` when the announcement runs indefinitely. Legacy `_icob_announcement_expiration` values are read transparently. |
| `event` | Event payload, or `null` when the event-date feature is disabled for the post. |
| `event.date` | Original event date, `YYYY-MM-DD` (site timezone). |
| `event.startTime` | `HH:MM` 24h, or empty string. |
| `event.durationHours` | Duration in hours (may be fractional), `0` when unset. |
| `event.location` | Location text, or empty string. |
| `event.recurrenceRule` | ICS RRULE string for recurring events (e.g. `FREQ=WEEKLY;BYDAY=FR;UNTIL=20261231T235959Z`), or `null` for one-off events. Combined with `event.date`, consumers can expand occurrences themselves. |

### Detailed item (`include_details=true`)

Adds:

```json
{
  "excerpt": "Custom excerpt.",
  "imageUrl": "https://example.org/wp-content/uploads/photo-1024x683.jpg",
  "imageAlt": "A photo",
  "fullBody": "<p>Rendered post content…</p>",
  "categories": ["music"],
  "tags": ["jazz", "outdoor"]
}
```

- `fullBody` is the post content rendered through the `the_content`
  filter (shortcodes and blocks resolved). It is authored HTML for
  published posts only — consumers should treat it as trusted-ish content
  and escape according to their own context.
- `imageUrl` is the featured image at the `large` size (`null`/`false`
  when none), `imageAlt` its alt text.

---

## Expansion mode (`expand_recurring=true`)

Recurring events are materialized into one item per occurrence inside
the datetime window, sorted by occurrence date (then by `orderby`).
One-off events become single instances. Each item carries the full base
payload plus an `occurrence` block:

```json
{
  "postId": 123,
  "title": "Summer Concert",
  "…": "…",
  "event": {
    "date": "2026-09-04",
    "startTime": "18:30",
    "durationHours": 2.0,
    "location": "City Park",
    "recurrenceRule": "FREQ=WEEKLY;BYDAY=FR"
  },
  "occurrence": {
    "date": "2026-09-11",
    "start": "2026-09-11T18:30:00+00:00",
    "end": "2026-09-11T20:30:00+00:00",
    "isRecurring": true
  }
}
```

| Field | Description |
|---|---|
| `occurrence.date` | Occurrence date, `YYYY-MM-DD`. |
| `occurrence.start` / `occurrence.end` | ISO 8601 UTC instants derived from `startTime` + `durationHours`; `null` when no start time is set. |
| `occurrence.isRecurring` | `false` for one-off instances (where `occurrence.date` equals `event.date`). |

### Window and safety bounds

- **Window:** `date_from`/`date_to` bound occurrences. When omitted, the
  window defaults to `[today, today + 90 days]`.
- **Per-event cap:** at most 366 occurrences are materialized per event
  (filter `awesome_calendar_events_api_max_occurrences_per_event`).
- **Scan cap:** at most 1000 event posts are scanned per request (filter
  `awesome_calendar_events_api_max_posts_scan`).
- **Pagination:** still bounded by `per_page` ≤ 100; `X-WP-Total` /
  `X-WP-TotalPages` report occurrence totals.

`COUNT`-limited recurrences are enumerated from their original first
occurrence, so occurrences before the window (or before a pagination
cursor) still consume the count budget — expansion never disagrees with
the ICS feeds.

---

## Pagination

Page numbers are not used. Responses carry an opaque, HMAC-signed cursor
in the `X-WP-NextPageToken` header; pass it back as `page_token` to fetch
the next page. Absent header = last page.

```http
GET /wp-json/awecal/v1/events?expand_recurring=true&per_page=3
X-WP-Total: 5
X-WP-TotalPages: 2
X-WP-NextPageToken: eyJwIjo…

GET /wp-json/awecal/v1/events?expand_recurring=true&per_page=3&page_token=eyJwIjo…
```

- Tokens are self-contained (page number, and for expansion mode the
  occurrence date where the next page starts plus the running total), so
  cached token pages remain valid even if intermediate pages expire.
- Malformed or tampered tokens are rejected with
  `400 rest_invalid_param`.
- Tokens contain no sensitive data and cannot be forged (HMAC-SHA256
  keyed with `wp_hash()`).

---

## Errors

Standard WordPress REST error envelopes:

| Status | Code | Cause |
|---|---|---|
| 400 | `rest_invalid_param` | Invalid date/slugs/enums, `date_from > date_to`, or an invalid `page_token`. |
| 403 | — | The site has locked the route down via the permission callback filter. |

---

## Caching

Responses are cached server-side for **300 seconds** by default (one
entry per parameter set, including token pages). The cache is invalidated
whenever any post is saved or deleted.

Developers can tune this with the `awesome_calendar_events_api_cache_ttl`
filter (seconds; `0` disables caching):

```php
add_filter( 'awesome_calendar_events_api_cache_ttl', fn() => 600 );
```

For heavy expansion-mode traffic, prefer also caching/limiting at the
server or CDN edge; core WordPress REST responses are cache-friendly for
anonymous users.

---

## Developer filters

| Filter | Default | Description |
|---|---|---|
| `awesome_calendar_events_api_permission_callback` | `__return_true` | Replace to require authentication for the route. |
| `awesome_calendar_events_api_cache_ttl` | `300` | Response cache TTL in seconds; `0` disables. |
| `awesome_calendar_events_api_max_occurrences_per_event` | `366` | Expansion cap per event. |
| `awesome_calendar_events_api_max_posts_scan` | `1000` | Maximum event posts scanned per expanded request. |
| `awesome_calendar_events_api_expansion_default_window` | `90` | Default expansion window length (days) when no date filter is given. |

---

## Legacy data compatibility

All event/announcement meta reads transparently fall back to the
historical `_icob_` prefix (`_icob_event_date`,
`_icob_announcement_expiration`, …), so posts written before the
`_awecal_` migration are served identically. New writes always use the
canonical `_awecal_` keys.

---

## Examples

```bash
# Upcoming events in the "music" category, minimal payload
curl 'https://example.org/wp-json/awecal/v1/events?categories=music&per_page=50'

# Jazz AND outdoor events, with full post details
curl 'https://example.org/wp-json/awecal/v1/events?tags=jazz,outdoor&tag_operator=all&include_details=true'

# Everything tagged "jazz" originally scheduled in September 2026
curl 'https://example.org/wp-json/awecal/v1/events?tags=jazz&date_from=2026-09-01&date_to=2026-09-30'

# Expand recurring events into occurrences for October 2026
curl 'https://example.org/wp-json/awecal/v1/events?expand_recurring=true&date_from=2026-10-01&date_to=2026-10-31&per_page=100'

# Follow the cursor
curl 'https://example.org/wp-json/awecal/v1/events?expand_recurring=true&date_from=2026-10-01&date_to=2026-10-31&page_token=<X-WP-NextPageToken>'

# Active announcements only (client-side filter on announcement fields)
curl 'https://example.org/wp-json/awecal/v1/events?per_page=100'
```
