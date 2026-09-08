/**
 * Awesome Calendar Events - frontend month navigation.
 *
 * Re-renders the calendar grid client-side when the visitor moves to the
 * previous or next month. Events are fetched from the existing public
 * events query endpoint (awecal/v1/events) in expanded mode so recurring
 * events are unwrapped into the occurrences of the requested window.
 *
 * The markup produced here mirrors the server-rendered structure in
 * includes/class-calendar-block.php, driven by the config embedded in the
 * block wrapper's data-awecal-config attribute.
 */
(function() {
    'use strict';

    /**
     * Maximum events fetched for the displayed month. The events query API
     * caps a single request at 100 items, so cursor pagination
     * (X-WP-NextPageToken) is followed until this quota is reached or the
     * displayed month is fully covered.
     */
    const MAX_EVENTS_PER_MONTH = 100;

    /**
     * Maximum pages fetched per month (safety bound).
     */
    const MAX_PAGES = 10;

    function pad2(n) {
        return String(n).padStart(2, '0');
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Grid bounds for a Y-m month string: UTC start date and total cells
     * (leading/trailing cells of adjacent months included).
     */
    function gridBounds(ym, startOfWeek) {
        const parts = ym.split('-').map(Number);
        const year = parts[0];
        const month = parts[1]; // 1-12
        const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();
        const firstDow = new Date(Date.UTC(year, month - 1, 1)).getUTCDay();
        const lastDow = new Date(Date.UTC(year, month - 1, daysInMonth)).getUTCDay();
        const leading = (firstDow - startOfWeek + 7) % 7;
        const trailing = (startOfWeek + 6 - lastDow + 7) % 7;
        return {
            daysInMonth: daysInMonth,
            start: new Date(Date.UTC(year, month - 1, 1 - leading)),
            total: leading + daysInMonth + trailing
        };
    }

    function isoDate(date) {
        return date.getUTCFullYear() + '-' + pad2(date.getUTCMonth() + 1) + '-' + pad2(date.getUTCDate());
    }

    /**
     * Format an ISO start instant in the site timezone using Intl (falls
     * back to the raw HH:MM substring when Intl cannot honor the timezone).
     */
    function formatTime(start, cfg) {
        const hour12 = /[aA]/.test(cfg.timeFormat || '');
        try {
            return new Intl.DateTimeFormat(undefined, {
                hour: hour12 ? 'numeric' : '2-digit',
                minute: '2-digit',
                hour12: hour12,
                timeZone: cfg.timezone
            }).format(new Date(start));
        } catch (e) {
            return start.substring(11, 16);
        }
    }

    /**
     * Render one event item; mirrors render_event_details() in PHP.
     */
    function renderEvent(event, cfg) {
        const o = cfg.eventItem || {};
        let inner = '';
        const title = event.title || '';

        if (o.showTitle && title) {
            const t = escapeHtml(title);
            if (o.linkTitle && event.url) {
                inner += '<' + o.titleTag + ' class="awecal-calendar-event-title"><a href="' + escapeHtml(event.url) + '">' + t + '</a></' + o.titleTag + '>';
            } else {
                inner += '<' + o.titleTag + ' class="awecal-calendar-event-title">' + t + '</' + o.titleTag + '>';
            }
        }

        if (o.showTime && event.occurrence && event.occurrence.start) {
            inner += '<span class="awecal-calendar-event-time">' + escapeHtml(formatTime(event.occurrence.start, cfg)) + '</span>';
        }

        if (o.showLocation && event.event && event.event.location) {
            inner += '<span class="awecal-calendar-event-location">' + escapeHtml(event.event.location) + '</span>';
        }

        if (o.showSnippet && event.snippet) {
            inner += '<span class="awecal-calendar-event-snippet">' + escapeHtml(event.snippet) + '</span>';
        }

        return '<div class="awecal-calendar-event">' + inner + '</div>';
    }

    /**
     * Render the full grid for a month; mirrors render_calendar() in PHP.
     */
    function renderGrid(grid, cfg, ym, items) {
        const byDate = {};
        (items || []).forEach(function(item) {
            const d = item && item.occurrence && item.occurrence.date;
            if (!d) {
                return;
            }
            (byDate[d] = byDate[d] || []).push(item);
        });

        const displayMonth = Number(ym.slice(5, 7));
        let html = '';
        (cfg.weekdayNames || []).forEach(function(name) {
            html += '<div class="awecal-calendar-weekday">' + escapeHtml(name) + '</div>';
        });

        const bounds = gridBounds(ym, cfg.startOfWeek);
        for (let i = 0; i < bounds.total; i++) {
            const day = new Date(bounds.start.getTime() + i * 86400000);
            const ymd = isoDate(day);
            const events = byDate[ymd] || [];
            const classes = ['awecal-calendar-day'];
            if (ymd === cfg.today) {
                classes.push('is-today');
            }
            if (day.getUTCMonth() + 1 !== displayMonth) {
                classes.push('is-other-month');
            }
            classes.push(events.length ? 'has-events' : 'is-empty');

            html += '<div class="' + classes.join(' ') + '" data-date="' + ymd + '">';
            if (!cfg.dateCell || cfg.dateCell.showDateNumber) {
                html += '<div class="awecal-calendar-day-number">' + day.getUTCDate() + '</div>';
            }
            let shown = 0;
            for (let j = 0; j < events.length; j++) {
                if (cfg.maxEvents > 0 && shown >= cfg.maxEvents) {
                    break;
                }
                html += renderEvent(events[j], cfg);
                shown++;
            }
            html += '</div>';
        }

        grid.innerHTML = html;
    }

    function fetchMonth(root, cfg, ym) {
        const bounds = gridBounds(ym, cfg.startOfWeek);
        const from = isoDate(bounds.start);
        const to = isoDate(new Date(bounds.start.getTime() + (bounds.total - 1) * 86400000));
        const monthStart = ym + '-01';
        const monthEnd = ym + '-' + pad2(bounds.daysInMonth);
        const baseParams = {
            expand_recurring: 'true',
            date_from: from,
            date_to: to,
            per_page: '100',
            include_details: 'false'
        };
        if (cfg.categories) {
            baseParams.categories = cfg.categories;
        }
        if (cfg.tags) {
            baseParams.tags = cfg.tags;
        }

        root.classList.add('awecal-calendar-is-loading');

        const items = [];
        let token = null;

        function onePage(pageIndex) {
            const params = new URLSearchParams(baseParams);
            if (token) {
                params.set('page_token', token);
            }
            return window.fetch(cfg.restUrl + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    const nextToken = response.headers.get('X-WP-NextPageToken');
                    return response.json().then(function(pageItems) {
                        (pageItems || []).forEach(function(item) { items.push(item); });

                        let inMonth = 0;
                        let lastDate = '';
                        (pageItems || []).forEach(function(item) {
                            const d = item && item.occurrence && item.occurrence.date;
                            if (!d) {
                                return;
                            }
                            lastDate = d;
                            if (d >= monthStart && d <= monthEnd) {
                                inMonth++;
                            }
                        });

                        token = nextToken;

                        // Stop when the API is exhausted, the displayed month
                        // reached its quota, or the window extends past the
                        // month's end (trailing adjacent cells covered).
                        if (!token || inMonth >= MAX_EVENTS_PER_MONTH || (lastDate && lastDate > monthEnd)) {
                            return items;
                        }
                        if (pageIndex + 1 >= MAX_PAGES) {
                            return items;
                        }
                        return onePage(pageIndex + 1);
                    });
                });
        }

        return onePage(0).finally(function() {
            root.classList.remove('awecal-calendar-is-loading');
        });
    }

    function initCalendar(root) {
        if (root.dataset.awecalInit) {
            return;
        }
        root.dataset.awecalInit = '1';

        let config;
        try {
            config = JSON.parse(root.dataset.awecalConfig);
        } catch (e) {
            return;
        }

        const label = root.querySelector('[data-awecal-label]');
        const grid = root.querySelector('[data-awecal-grid]');
        const prev = root.querySelector('[data-awecal-nav="prev"]');
        const next = root.querySelector('[data-awecal-nav="next"]');
        if (!grid || !label || (!prev && !next)) {
            return;
        }

        let ym = config.month;
        let loading = false;

        function setLabel() {
            const parts = ym.split('-').map(Number);
            label.textContent = (config.monthNames[parts[1] - 1] || '') + ' ' + parts[0];
        }

        function go(delta) {
            if (loading) {
                return;
            }
            const parts = ym.split('-').map(Number);
            let year = parts[0];
            let month = parts[1] + delta;
            while (month > 12) { month -= 12; year++; }
            while (month < 1) { month += 12; year--; }
            ym = year + '-' + pad2(month);

            setLabel();
            loading = true;
            if (prev) { prev.disabled = true; }
            if (next) { next.disabled = true; }

            fetchMonth(root, config, ym)
                .then(function(items) {
                    renderGrid(grid, config, ym, items);
                })
                .catch(function(err) {
                    console.error('Awesome Calendar Events:', err);
                })
                .then(function() {
                    loading = false;
                    if (prev) { prev.disabled = false; }
                    if (next) { next.disabled = false; }
                });
        }

        if (prev) {
            prev.addEventListener('click', function() { go(-1); });
        }
        if (next) {
            next.addEventListener('click', function() { go(1); });
        }
    }

    function boot() {
        document.querySelectorAll('.awecal-calendar[data-awecal-config]').forEach(initCalendar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
