(function() {
    const { registerBlockType, registerBlockVariation } = wp.blocks;
    const { __ } = wp.i18n;
    const { addFilter } = wp.hooks;
    const { createHigherOrderComponent } = wp.compose;
    const { Fragment } = wp.element;
    const { InnerBlocks, InspectorControls, useBlockProps } = wp.blockEditor || wp.editor;
    const { PanelBody } = wp.components;

    const getAddToCalendarButtonAttributes = () => ({
        text: __('Add to Calendar', 'awesome-calendar-events'),
        className: 'is-style-add-to-calendar',
        url: '#add-to-calendar',
        metadata: {
            name: __('Add to Calendar', 'awesome-calendar-events')
        }
    });

    registerBlockType('awesome-calendar-events/add-to-calendar', {
        title: __('Add to Calendar', 'awesome-calendar-events'),
        description: __('Button that opens a calendar selection dialog for the event', 'awesome-calendar-events'),
        icon: 'calendar-alt',
        category: 'awesome-calendar-events',
        keywords: ['event', 'calendar', 'ical'],
        supports: {
            html: false
        },
        edit: () => {
            const blockProps = useBlockProps();

            return wp.element.createElement(
                'div',
                blockProps,
                wp.element.createElement(InnerBlocks, {
                    template: [
                        ['core/buttons', {}, [
                            ['core/button', getAddToCalendarButtonAttributes()]
                        ]]
                    ],
                    templateLock: 'all'
                })
            );
        },
        save: () => wp.element.createElement(InnerBlocks.Content)
    });

    // Retain the button variation for adding the calendar button to existing Buttons blocks.
    registerBlockVariation('core/button', {
        name: 'awecal-add-to-calendar',
        title: __('Add to Calendar', 'awesome-calendar-events'),
        description: __('Button that opens a calendar selection dialog for the event', 'awesome-calendar-events'),
        icon: 'calendar-alt',
        attributes: getAddToCalendarButtonAttributes(),
        isActive: (blockAttributes) => {
            return blockAttributes.className && blockAttributes.className.includes('is-style-add-to-calendar');
        },
        scope: ['inserter', 'transform']
    });

    // Add custom controls to the button when it's our variation
    const withAddToCalendarControls = createHigherOrderComponent((BlockEdit) => {
        return (props) => {
            const { name, attributes, setAttributes } = props;
            const { className } = attributes;

            // Only add controls if this is our button variation
            const isAddToCalendarButton = name === 'core/button' &&
                                         className &&
                                         className.includes('is-style-add-to-calendar');

            if (!isAddToCalendarButton) {
                return wp.element.createElement(BlockEdit, props);
            }

            return wp.element.createElement(
                Fragment,
                {},
                wp.element.createElement(BlockEdit, props),
                wp.element.createElement(
                    InspectorControls,
                    {},
                    wp.element.createElement(
                        PanelBody,
                        {
                            title: __('Calendar Settings', 'awesome-calendar-events'),
                            initialOpen: true
                        },
                        wp.element.createElement(
                            'p',
                            { style: { fontStyle: 'italic', color: '#666' } },
                            __('This button will automatically generate calendar links based on the post event metadata (date, time, location, and title).', 'awesome-calendar-events')
                        ),
                        wp.element.createElement(
                            'p',
                            { style: { fontStyle: 'italic', color: '#666' } },
                            __('When clicked, it will show a dialog with options for Google, Apple, Outlook, Yahoo, and iCal.', 'awesome-calendar-events')
                        )
                    )
                )
            );
        };
    }, 'withAddToCalendarControls');

    addFilter(
        'editor.BlockEdit',
        'awesome-calendar-events/add-to-calendar-controls',
        withAddToCalendarControls
    );

})();
