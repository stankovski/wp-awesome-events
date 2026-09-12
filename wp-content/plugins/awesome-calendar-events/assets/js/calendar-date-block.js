(function() {
    'use strict';

    const { registerBlockType } = wp.blocks;
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, InnerBlocks, useBlockProps } = wp.blockEditor;
    const { PanelBody, ToggleControl } = wp.components;
    const { __ } = wp.i18n;

    registerBlockType('awesome-calendar-events/calendar-date', {
        title: __('Calendar Date', 'awesome-calendar-events'),
        description: __('Container for a single calendar date cell. Shows the day number and the event details for that date.', 'awesome-calendar-events'),
        icon: window.awecalBlockIcons.calendarDate,
        category: 'awesome-calendar-events',
        keywords: [
            __('calendar', 'awesome-calendar-events'),
            __('date', 'awesome-calendar-events'),
            __('day', 'awesome-calendar-events'),
            __('container', 'awesome-calendar-events')
        ],
        usesContext: ['awesome-calendar-events/date'],
        supports: {
            anchor: true,
            html: false,
            className: true,
            color: { gradients: true, link: true, text: true, background: true },
            spacing: { margin: true, padding: true },
            typography: { fontSize: true, lineHeight: true }
        },
        attributes: {
            showDateNumber: { type: 'boolean', default: true }
        },

        edit: function(props) {
            const { attributes, setAttributes } = props;
            // "awecal-calendar" scopes the shared block styles inside the editor canvas.
            const blockProps = useBlockProps({ className: 'awecal-calendar awecal-calendar-date-editor' });

            return el(
                Fragment,
                null,
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: __('Date Cell Settings', 'awesome-calendar-events'), initialOpen: true },
                        el(ToggleControl, {
                            label: __('Show Day Number', 'awesome-calendar-events'),
                            checked: attributes.showDateNumber,
                            onChange: function(value) { setAttributes({ showDateNumber: value }); }
                        })
                    )
                ),
                el(
                    'div',
                    blockProps,
                    el(
                        'div',
                        { className: 'awecal-calendar-day is-today has-events awecal-calendar-date-editor-preview' },
                        attributes.showDateNumber && el('div', { className: 'awecal-calendar-day-number' }, '14'),
                        el(InnerBlocks, {
                            allowedBlocks: ['awesome-calendar-events/event-details'],
                            template: [['awesome-calendar-events/event-details', {}]],
                            templateLock: 'all'
                        })
                    ),
                    el(
                        'p',
                        { className: 'awecal-calendar-editor-note' },
                        __('This container renders once per calendar date. Add the "Event Details" container to control how each event is displayed.', 'awesome-calendar-events')
                    )
                )
            );
        },

        save: function() {
            // Persist the inner template (Event Details container and its
            // settings) into post content. A null save would serialize the
            // block as self-closing and drop the whole inner tree.
            return el(InnerBlocks.Content);
        }
    });
})();
