(function() {
    'use strict';

    const { registerBlockType } = wp.blocks;
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, ToggleControl, SelectControl, TextControl } = wp.components;
    const { __ } = wp.i18n;

    registerBlockType('awesome-calendar-events/event-details', {
        title: __('Event Details', 'awesome-calendar-events'),
        description: __('Container that displays a single event inside a calendar date cell.', 'awesome-calendar-events'),
        icon: window.awecalBlockIcons.eventDetails,
        category: 'awesome-calendar-events',
        keywords: [
            __('event', 'awesome-calendar-events'),
            __('details', 'awesome-calendar-events'),
            __('calendar', 'awesome-calendar-events'),
            __('container', 'awesome-calendar-events')
        ],
        usesContext: ['awesome-calendar-events/event'],
        supports: {
            anchor: true,
            html: false,
            className: true,
            color: { gradients: true, link: true, text: true, background: true },
            spacing: { margin: true, padding: true },
            typography: { fontSize: true, lineHeight: true }
        },
        attributes: {
            showTitle: { type: 'boolean', default: true },
            titleTag: { type: 'string', default: 'h3' },
            linkTitle: { type: 'boolean', default: true },
            showTime: { type: 'boolean', default: true },
            showLocation: { type: 'boolean', default: false },
            showSnippet: { type: 'boolean', default: false }
        },

        edit: function(props) {
            const { attributes, setAttributes } = props;
            // "awecal-calendar" scopes the shared block styles inside the editor canvas.
            const blockProps = useBlockProps({ className: 'awecal-calendar awecal-event-details-editor' });
            const titleTag = attributes.titleTag || 'h3';

            const sampleEvent = el(
                'div',
                { className: 'awecal-calendar-event' },
                attributes.showTitle && el(
                    titleTag,
                    { className: 'awecal-calendar-event-title' },
                    attributes.linkTitle
                        ? el('a', { href: '#', onClick: function(e) { e.preventDefault(); } }, __('Sample Event Title', 'awesome-calendar-events'))
                        : __('Sample Event Title', 'awesome-calendar-events')
                ),
                attributes.showTime && el('span', { className: 'awecal-calendar-event-time' }, '9:00 am'),
                attributes.showLocation && el('span', { className: 'awecal-calendar-event-location' }, __('Sample Location', 'awesome-calendar-events')),
                attributes.showSnippet && el('span', { className: 'awecal-calendar-event-snippet' }, __('Short event excerpt shown here.', 'awesome-calendar-events'))
            );

            return el(
                Fragment,
                null,
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: __('Event Details Settings', 'awesome-calendar-events'), initialOpen: true },
                        el(ToggleControl, {
                            label: __('Show Event Title', 'awesome-calendar-events'),
                            checked: attributes.showTitle,
                            onChange: function(value) { setAttributes({ showTitle: value }); }
                        }),
                        attributes.showTitle && el(SelectControl, {
                            label: __('Title Tag', 'awesome-calendar-events'),
                            value: attributes.titleTag,
                            options: [
                                { label: 'h2', value: 'h2' },
                                { label: 'h3', value: 'h3' },
                                { label: 'h4', value: 'h4' },
                                { label: 'h5', value: 'h5' },
                                { label: 'p', value: 'p' },
                                { label: 'div', value: 'div' }
                            ],
                            onChange: function(value) { setAttributes({ titleTag: value }); }
                        }),
                        attributes.showTitle && el(ToggleControl, {
                            label: __('Link Title to Event', 'awesome-calendar-events'),
                            checked: attributes.linkTitle,
                            onChange: function(value) { setAttributes({ linkTitle: value }); }
                        }),
                        el(ToggleControl, {
                            label: __('Show Start Time', 'awesome-calendar-events'),
                            checked: attributes.showTime,
                            onChange: function(value) { setAttributes({ showTime: value }); }
                        }),
                        el(ToggleControl, {
                            label: __('Show Location', 'awesome-calendar-events'),
                            checked: attributes.showLocation,
                            onChange: function(value) { setAttributes({ showLocation: value }); }
                        }),
                        el(ToggleControl, {
                            label: __('Show Excerpt', 'awesome-calendar-events'),
                            help: __('Shows a short text snippet of the event.', 'awesome-calendar-events'),
                            checked: attributes.showSnippet,
                            onChange: function(value) { setAttributes({ showSnippet: value }); }
                        })
                    )
                ),
                el(
                    'div',
                    blockProps,
                    sampleEvent,
                    el(
                        'p',
                        { className: 'awecal-calendar-editor-note' },
                        __('This container renders once per event shown in the calendar.', 'awesome-calendar-events')
                    )
                )
            );
        },

        save: function() {
            return null; // Dynamic block, rendered via PHP.
        }
    });
})();
