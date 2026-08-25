(function() {
    const { registerBlockVariation } = wp.blocks;
    const { __ } = wp.i18n;
    const { addFilter } = wp.hooks;
    const { createHigherOrderComponent } = wp.compose;
    const { Fragment } = wp.element;
    const { InspectorControls } = wp.blockEditor || wp.editor;
    const { PanelBody } = wp.components;

    // Register the block variation
    registerBlockVariation('core/button', {
        name: 'icob-add-to-calendar',
        title: __('Add to Calendar', 'awesome-events'),
        description: __('Button that opens a calendar selection dialog for the event', 'awesome-events'),
        icon: 'calendar-alt',
        attributes: {
            text: __('Add to Calendar', 'awesome-events'),
            className: 'is-style-add-to-calendar',
            url: '#add-to-calendar',
            metadata: {
                name: __('Add to Calendar', 'awesome-events')
            }
        },
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
                            title: __('Calendar Settings', 'awesome-events'),
                            initialOpen: true
                        },
                        wp.element.createElement(
                            'p',
                            { style: { fontStyle: 'italic', color: '#666' } },
                            __('This button will automatically generate calendar links based on the post event metadata (date, time, location, and title).', 'awesome-events')
                        ),
                        wp.element.createElement(
                            'p',
                            { style: { fontStyle: 'italic', color: '#666' } },
                            __('When clicked, it will show a dialog with options for Google, Apple, Outlook, Yahoo, and iCal.', 'awesome-events')
                        )
                    )
                )
            );
        };
    }, 'withAddToCalendarControls');

    addFilter(
        'editor.BlockEdit',
        'awesome-events/add-to-calendar-controls',
        withAddToCalendarControls
    );

})();
