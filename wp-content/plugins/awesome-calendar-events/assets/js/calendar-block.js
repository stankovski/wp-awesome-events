(function() {
    'use strict';

    const { registerBlockType } = wp.blocks;
    const { createElement: el, Fragment, useState, useEffect } = wp.element;
    const {
        InspectorControls,
        InnerBlocks,
        useBlockProps
    } = wp.blockEditor;
    const { PanelBody, ToggleControl, SelectControl, FormTokenField, RangeControl } = wp.components;
    const { __ } = wp.i18n;
    const apiFetch = window.wp.apiFetch;

    /**
     * Taxonomy term filter control (autocomplete + multi-select), mirroring
     * the Query Loop block's taxonomy controls. Terms are fetched from the
     * REST API; tokens display term names while the attribute persists a
     * comma-separated slug list (the format consumed by the events query
     * API). Slugs without a matching term (e.g. removed terms) are kept and
     * displayed as-is so existing blocks are not silently mutated.
     */
    function TaxonomyFilter(props) {
        const { restBase, label, help, slugs, onChangeSlugs } = props;
        const [terms, setTerms] = useState([]);

        useEffect(function() {
            let active = true;
            apiFetch({ path: '/wp/v2/' + restBase + '?per_page=100&orderby=name&order=asc&_fields=id,name,slug' })
                .then(function(data) {
                    if (active && Array.isArray(data)) { setTerms(data); }
                })
                .catch(function() {
                    if (active) { setTerms([]); }
                });
            return function() { active = false; };
        }, [restBase]);

        const slugToName = {};
        const nameToSlug = {};
        terms.forEach(function(term) {
            if (!term || !term.slug) { return; }
            slugToName[term.slug] = term.name || term.slug;
            if (term.name) { nameToSlug[term.name] = term.slug; }
        });

        const selectedSlugs = (slugs || '').split(',').map(function(s) { return s.trim(); }).filter(Boolean);

        // Resolve a token value to a term slug: exact name match first, then
        // a case-insensitive match (FormTokenField suggestions match case
        // insensitively), otherwise keep the token as a raw slug.
        const resolveSlug = function(token) {
            if (Object.prototype.hasOwnProperty.call(nameToSlug, token)) {
                return nameToSlug[token];
            }
            const lower = token.toLocaleLowerCase();
            const match = terms.find(function(term) {
                return term && term.name && term.name.toLocaleLowerCase() === lower;
            });
            return match ? match.slug : token;
        };

        const value = selectedSlugs.map(function(slug) { return slugToName[slug] || slug; });
        const suggestions = terms
            .map(function(term) { return term.name; })
            .filter(function(name) { return name && !selectedSlugs.includes(nameToSlug[name]); });

        return el(FormTokenField, {
            label: label,
            help: help,
            value: value,
            suggestions: suggestions,
            onChange: function(tokens) {
                const next = [];
                tokens.forEach(function(token) {
                    const slug = String(token).trim();
                    if (slug === '') { return; }
                    const resolved = resolveSlug(slug);
                    if (!next.includes(resolved)) { next.push(resolved); }
                });
                onChangeSlugs(next.join(','));
            },
            maxSuggestions: 20,
            __experimentalExpandOnFocus: true,
            __experimentalShowHowTo: false
        });
    }

    /**
     * Build a static preview grid for the current month (editor only).
     *
     * The container has no border/shadow of its own: the block wrapper
     * (useBlockProps) already carries the border/shadow the user configures
     * via block supports, mirroring the server-rendered wrapper.
     */
    function buildPreviewGrid() {
        const now = new Date();
        const year = now.getFullYear();
        const month = now.getMonth();
        const label = now.toLocaleString(undefined, { month: 'long', year: 'numeric' });
        const firstDow = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const startOffset = (firstDow + 6) % 7; // Monday-start preview
        const weekdays = Array.from({ length: 7 }, function(_, i) {
            const d = new Date(2023, 0, 2 + i); // Jan 2, 2023 was a Monday
            return d.toLocaleString(undefined, { weekday: 'short' });
        });

        const cells = [];
        for (let i = 0; i < startOffset; i++) {
            cells.push(el('div', { className: 'awecal-calendar-day is-empty is-other-month', key: 'pad' + i }));
        }
        for (let day = 1; day <= daysInMonth; day++) {
            const isToday = day === now.getDate();
            cells.push(el(
                'div',
                { className: 'awecal-calendar-day' + (isToday ? ' is-today has-events' : ' is-empty'), key: 'd' + day },
                el('div', { className: 'awecal-calendar-day-number' }, day),
                isToday && el(
                    'div',
                    { className: 'awecal-calendar-event' },
                    el('div', { className: 'awecal-calendar-event-title' }, __('Sample Event', 'awesome-calendar-events'))
                )
            ));
        }

        return el(
            'div',
            {
                className: 'awecal-calendar-editor-preview',
                'aria-hidden': true
            },
            el(
                'div',
                { className: 'awecal-calendar-header' },
                el('div', { className: 'awecal-calendar-month-label' }, label),
                el('button', { type: 'button', className: 'awecal-calendar-nav', disabled: true, tabIndex: -1 }, '\u2039'),
                el('button', { type: 'button', className: 'awecal-calendar-nav', disabled: true, tabIndex: -1 }, '\u203a')
            ),
            el(
                'div',
                { className: 'awecal-calendar-grid' },
                weekdays.map(function(w, i) {
                    return el('div', { className: 'awecal-calendar-weekday', key: 'w' + i }, w);
                }),
                cells
            ),
            el(
                'p',
                { className: 'awecal-calendar-editor-note' },
                __('Preview - the calendar grid and its events are rendered on the frontend.', 'awesome-calendar-events')
            )
        );
    }

    registerBlockType('awesome-calendar-events/calendar', {
        title: __('Calendar', 'awesome-calendar-events'),
        description: __('Displays a month calendar grid of events with previous/next month navigation.', 'awesome-calendar-events'),
        icon: window.awecalBlockIcons.calendar,
        category: 'awesome-calendar-events',
        keywords: [
            __('calendar', 'awesome-calendar-events'),
            __('events', 'awesome-calendar-events'),
            __('month', 'awesome-calendar-events'),
            __('grid', 'awesome-calendar-events')
        ],
        supports: {
            align: ['wide', 'full'],
            anchor: true,
            html: false,
            className: true,
            color: { gradients: true, link: true, text: true, background: true },
            spacing: { margin: true, padding: true },
            __experimentalBorder: { radius: true, color: true, width: true, style: true },
            shadow: true,
            typography: { fontSize: true, lineHeight: true }
        },
        attributes: {
            startOfWeek: { type: 'integer', default: 0 },
            showNavigation: { type: 'boolean', default: true },
            initialOffset: { type: 'integer', default: 0 },
            maxEventsPerDay: { type: 'integer', default: 3 },
            categories: { type: 'string', default: '' },
            tags: { type: 'string', default: '' },
            mobileView: { type: 'string', default: 'list' }
        },

        edit: function(props) {
            const { attributes, setAttributes } = props;
            // "awecal-calendar" scopes the shared block styles inside the editor canvas.
            const blockProps = useBlockProps({ className: 'awecal-calendar awecal-calendar-editor' });

            return el(
                Fragment,
                null,
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: __('Calendar Settings', 'awesome-calendar-events'), initialOpen: true },
                        el(ToggleControl, {
                            label: __('Show Month Navigation', 'awesome-calendar-events'),
                            help: __('Let visitors move to the previous or next month.', 'awesome-calendar-events'),
                            checked: attributes.showNavigation,
                            onChange: function(value) { setAttributes({ showNavigation: value }); }
                        }),
                        el(SelectControl, {
                            label: __('Week Starts On', 'awesome-calendar-events'),
                            value: String(attributes.startOfWeek),
                            options: [
                                { label: __('Sunday', 'awesome-calendar-events'), value: '0' },
                                { label: __('Monday', 'awesome-calendar-events'), value: '1' },
                                { label: __('Tuesday', 'awesome-calendar-events'), value: '2' },
                                { label: __('Wednesday', 'awesome-calendar-events'), value: '3' },
                                { label: __('Thursday', 'awesome-calendar-events'), value: '4' },
                                { label: __('Friday', 'awesome-calendar-events'), value: '5' },
                                { label: __('Saturday', 'awesome-calendar-events'), value: '6' }
                            ],
                            onChange: function(value) { setAttributes({ startOfWeek: parseInt(value, 10) || 0 }); }
                        }),
                        el(RangeControl, {
                            label: __('Max Events per Day', 'awesome-calendar-events'),
                            help: __('Maximum events shown in a single date cell. 0 shows all.', 'awesome-calendar-events'),
                            value: attributes.maxEventsPerDay,
                            onChange: function(value) { setAttributes({ maxEventsPerDay: value }); },
                            min: 0,
                            max: 10
                        }),
                        el(SelectControl, {
                            label: __('Mobile View', 'awesome-calendar-events'),
                            help: __('How the calendar appears on small screens.', 'awesome-calendar-events'),
                            value: attributes.mobileView,
                            options: [
                                { label: __('Show calendar', 'awesome-calendar-events'), value: 'calendar' },
                                { label: __('Show list', 'awesome-calendar-events'), value: 'list' },
                                { label: __('Hide', 'awesome-calendar-events'), value: 'hide' }
                            ],
                            onChange: function(value) { setAttributes({ mobileView: value }); }
                        }),
                        el(TaxonomyFilter, {
                            restBase: 'categories',
                            label: __('Filter by Categories', 'awesome-calendar-events'),
                            help: __('Only show events assigned to the selected categories.', 'awesome-calendar-events'),
                            slugs: attributes.categories,
                            onChangeSlugs: function(value) { setAttributes({ categories: value }); }
                        }),
                        el(TaxonomyFilter, {
                            restBase: 'tags',
                            label: __('Filter by Tags', 'awesome-calendar-events'),
                            help: __('Only show events assigned to the selected tags.', 'awesome-calendar-events'),
                            slugs: attributes.tags,
                            onChangeSlugs: function(value) { setAttributes({ tags: value }); }
                        })
                    )
                ),
                el(
                    'div',
                    blockProps,
                    buildPreviewGrid(),
                    el(
                        'div',
                        { className: 'awecal-calendar-editor-template' },
                        el(
                            'span',
                            { className: 'awecal-calendar-editor-template-title' },
                            __('Date cell contents', 'awesome-calendar-events')
                        ),
                        el(InnerBlocks, {
                            allowedBlocks: ['awesome-calendar-events/calendar-date'],
                            template: [['awesome-calendar-events/calendar-date', {}]],
                            templateLock: 'all'
                        })
                    )
                )
            );
        },

        save: function() {
            // Persist the inner template (Calendar Date container and its
            // settings) into post content. A null save would serialize the
            // block as self-closing and drop the whole inner tree.
            return el(InnerBlocks.Content);
        }
    });
})();
