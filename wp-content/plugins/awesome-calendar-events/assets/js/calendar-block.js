(function() {
    'use strict';

    const { registerBlockType } = wp.blocks;
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, InnerBlocks, useBlockProps } = wp.blockEditor;
    const { PanelBody, ToggleControl, SelectControl, TextControl, RangeControl } = wp.components;
    const { __ } = wp.i18n;

    /**
     * Build a static preview grid for the current month (editor only).
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
            { className: 'awecal-calendar-editor-preview', 'aria-hidden': true },
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
                        el(TextControl, {
                            label: __('Filter by Categories', 'awesome-calendar-events'),
                            help: __('Comma-separated category slugs.', 'awesome-calendar-events'),
                            value: attributes.categories,
                            onChange: function(value) { setAttributes({ categories: value }); }
                        }),
                        el(TextControl, {
                            label: __('Filter by Tags', 'awesome-calendar-events'),
                            help: __('Comma-separated tag slugs.', 'awesome-calendar-events'),
                            value: attributes.tags,
                            onChange: function(value) { setAttributes({ tags: value }); }
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
