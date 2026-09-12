/**
 * Custom SVG block icons for Awesome Calendar Events.
 *
 * Exposed globally as `window.awecalBlockIcons`. Each icon is an SVG
 * element (24x24, stroke-based, inherits color via `currentColor`) passed
 * as the `icon` setting to registerBlockType() / registerBlockVariation().
 *
 * The dashicon slugs in blocks/<name>/block.json remain as the documented
 * fallback for non-JS contexts; in the editor these elements always take
 * precedence because client-side settings are merged over the server
 * metadata.
 *
 * The plugin has no build step and deliberately avoids the @wordpress/icons
 * package (it is not exposed as a core script handle), so the icons are
 * built with wp.element.createElement (see event-meta-blocks-shared.js for
 * the original rationale).
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.element) {
		return;
	}

	var el = wp.element.createElement;

	var STROKE = {
		fill: 'none',
		stroke: 'currentColor',
		strokeWidth: 1.5,
		strokeLinecap: 'round',
		strokeLinejoin: 'round'
	};

	function icon(children) {
		return el('svg', {
			xmlns: 'http://www.w3.org/2000/svg',
			viewBox: '0 0 24 24',
			width: 24,
			height: 24,
			'aria-hidden': true,
			focusable: false
		}, children);
	}

	function path(d) {
		return el('path', Object.assign({ d: d }, STROKE));
	}

	function outlineCircle(cx, cy, r) {
		return el('circle', Object.assign({ cx: cx, cy: cy, r: r }, STROKE));
	}

	function dot(cx, cy, r) {
		return el('circle', { cx: cx, cy: cy, r: r, fill: 'currentColor' });
	}

	function outlineRect(x, y, width, height, rx) {
		return el('rect', Object.assign({ x: x, y: y, width: width, height: height, rx: rx }, STROKE));
	}

	// Shared calendar page: rounded body, header rule and binding rings.
	var CALENDAR_BASE = [
		outlineRect(3.5, 4.75, 17, 15.75, 2),
		path('M3.5 9.25h17M8 3.25v3.75M16 3.25v3.75')
	];

	window.awecalBlockIcons = {
		// Calendar: month grid of event dots.
		calendar: icon(CALENDAR_BASE.concat([
			dot(8, 13.25, 1.05),
			dot(12, 13.25, 1.05),
			dot(16, 13.25, 1.05),
			dot(8, 17, 1.05),
			dot(12, 17, 1.05)
		])),
		// Calendar Date: calendar page with the day numeral.
		calendarDate: icon(CALENDAR_BASE.concat([
			path('M11.15 13.9L12.45 12.9v4.25')
		])),
		// Event Date: calendar page with a marked day.
		eventDate: icon(CALENDAR_BASE.concat([
			dot(12, 15, 1.9)
		])),
		// Event Time: clock face with hands.
		eventTime: icon([
			outlineCircle(12, 12, 8.25),
			path('M12 7.5V12l2.75 1.75')
		]),
		// Event Location: map pin.
		eventLocation: icon([
			path('M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z'),
			outlineCircle(12, 10, 3)
		]),
		// Event Countdown: stopwatch (top button, winding stem, hand).
		eventCountdown: icon([
			path('M9.75 3h4.5M12 3v4.75'),
			outlineCircle(12, 14.25, 6.5),
			path('M12 14.25v-4')
		]),
		// Event Details: calendar page with detail lines.
		eventDetails: icon(CALENDAR_BASE.concat([
			path('M7.75 13.25h8.5M7.75 16.5h5')
		])),
		// Event Announcement: megaphone with handle.
		announcement: icon([
			path('M20 5.5 6.5 9H4a1.5 1.5 0 0 0-1.5 1.5v2A1.5 1.5 0 0 0 4 14h2.5L20 18.5Z'),
			path('M8.75 14.75v3.85')
		]),
		// Add to Calendar: calendar page with a plus sign.
		addToCalendar: icon(CALENDAR_BASE.concat([
			path('M12 12.5v5.5M9.25 15.25h5.5')
		])),
		// Event List: calendar page with two event rows (dot + line).
		eventList: icon(CALENDAR_BASE.concat([
			dot(8, 13.25, 1),
			path('M11 13.25h5.75'),
			dot(8, 16.75, 1),
			path('M11 16.75h5.75')
		]))
	};
})(window.wp);