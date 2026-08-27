(function(){
    const { registerBlockType } = wp.blocks;
    const { __ } = wp.i18n;
    const { InspectorControls, useBlockProps } = wp.blockEditor || wp.editor;
    const { PanelBody, TextControl, ToggleControl, SelectControl } = wp.components;

    registerBlockType('icob/event-date', {
        title: __('Event Date','awesome-calendar-events'),
        description: __('Displays the upcoming event date or recurring weekdays for the current post.','awesome-calendar-events'),
        icon: 'calendar-alt',
        category: 'icob',
        supports: { html: false, color: { text: true, background: true }, typography: { fontSize: true, lineHeight: true } },
        attributes: {
            format: { type: 'string', default: 'F j, Y' },
            timeFormat: { type: 'string', default: 'g:i A' },
            dataType: { type: 'string', default: 'date' },
            fallbackText: { type: 'string', default: '' },
            showLabel: { type: 'boolean', default: false },
            labelText: { type: 'string', default: __('Event Date:', 'awesome-calendar-events') },
            showWeekdaysWhenMissing: { type: 'boolean', default: true },
            wrapTag: { type: 'string', default: 'div' },
            locationMetaKey: { type: 'string', default: '_awecal_event_location' },
            relativeCurrentWeek: { type: 'boolean', default: false }
        },
        edit: (props) => {
            const { attributes, setAttributes } = props;
            const { format, timeFormat, dataType, fallbackText, showLabel, labelText, showWeekdaysWhenMissing, wrapTag, locationMetaKey, relativeCurrentWeek } = attributes;
            const blockProps = useBlockProps({ className: 'icob-event-date-block-editor' });

            // Adjust default label automatically when type changes if label was one of defaults.
            function onChangeDataType(v){
                const defaults = [ __('Event Date:', 'awesome-calendar-events'), __('Event Time:', 'awesome-calendar-events'), __('Event Location:', 'awesome-calendar-events') ];
                let newLabel = labelText;
                if (defaults.includes(labelText)) {
                    if (v==='time') newLabel = __('Event Time:', 'awesome-calendar-events');
                    else if (v==='location') newLabel = __('Event Location:', 'awesome-calendar-events');
                    else newLabel = __('Event Date:', 'awesome-calendar-events');
                }
                setAttributes({ dataType: v, labelText: newLabel });
            }

            return (
                wp.element.createElement(wp.element.Fragment, null,
                    wp.element.createElement(InspectorControls, null,
                        wp.element.createElement(PanelBody, { title: __('Display Settings','awesome-calendar-events'), initialOpen: true },
                            wp.element.createElement(SelectControl, {
                                label: __('Data Type','awesome-calendar-events'),
                                value: dataType,
                                options: [
                                    { label: __('Date','awesome-calendar-events'), value: 'date' },
                                    { label: __('Time','awesome-calendar-events'), value: 'time' },
                                    { label: __('Location','awesome-calendar-events'), value: 'location' }
                                ],
                                onChange: onChangeDataType
                            }),
                            dataType==='date' && wp.element.createElement(TextControl, {
                                label: __('Date Format','awesome-calendar-events'),
                                help: __('PHP date format (e.g. F j, Y).','awesome-calendar-events'),
                                value: format,
                                onChange: (v)=>setAttributes({ format: v })
                            }),
                            dataType==='time' && wp.element.createElement(TextControl, {
                                label: __('Time Format','awesome-calendar-events'),
                                help: __('PHP time format (e.g. g:i A).','awesome-calendar-events'),
                                value: timeFormat,
                                onChange: (v)=>setAttributes({ timeFormat: v })
                            }),
                            dataType==='location' && wp.element.createElement(TextControl, {
                                label: __('Location Meta Key','awesome-calendar-events'),
                                help: __('Post meta key where the event location is stored.','awesome-calendar-events'),
                                value: locationMetaKey,
                                onChange: (v)=>setAttributes({ locationMetaKey: v })
                            }),
                            wp.element.createElement(ToggleControl, {
                                label: __('Show Label','awesome-calendar-events'),
                                checked: showLabel,
                                onChange: (v)=>setAttributes({ showLabel: v })
                            }),
                            showLabel && wp.element.createElement(TextControl, {
                                label: __('Label Text','awesome-calendar-events'),
                                value: labelText,
                                onChange: (v)=>setAttributes({ labelText: v })
                            }),
                            dataType==='date' && wp.element.createElement(ToggleControl, {
                                label: __('Show Weekdays When Missing','awesome-calendar-events'),
                                checked: showWeekdaysWhenMissing,
                                onChange: (v)=>setAttributes({ showWeekdaysWhenMissing: v })
                            }),
                            dataType==='date' && wp.element.createElement(ToggleControl, {
                                label: __('Relative Current Week Output','awesome-calendar-events'),
                                help: __('Show plural weekdays for weekly recurrence or "This Monday" for single events within current week.','awesome-calendar-events'),
                                checked: relativeCurrentWeek,
                                onChange: (v)=>setAttributes({ relativeCurrentWeek: v })
                            }),
                            wp.element.createElement(TextControl, {
                                label: __('Fallback Text','awesome-calendar-events'),
                                help: __('Shown if no data is available (leave blank to hide block).','awesome-calendar-events'),
                                value: fallbackText,
                                onChange: (v)=>setAttributes({ fallbackText: v })
                            }),
                            wp.element.createElement(SelectControl, {
                                label: __('Wrapper Tag','awesome-calendar-events'),
                                value: wrapTag,
                                options: [
                                    { label: 'div', value: 'div' },
                                    { label: 'span', value: 'span' },
                                    { label: 'p', value: 'p' }
                                ],
                                onChange: (v)=>setAttributes({ wrapTag: v })
                            })
                        )
                    ),
                    wp.element.createElement('div', blockProps,
                        showLabel && wp.element.createElement('strong', { style: { marginRight: '4px'} }, labelText),
                        wp.element.createElement('em', null, dataType==='date' ? __('(Date/Recurrence rendered on frontend)','awesome-calendar-events') : (dataType==='time' ? __('(Time rendered on frontend)','awesome-calendar-events') : __('(Location rendered on frontend)','awesome-calendar-events')) )
                    )
                )
            );
        },
        save: () => null
    });
})();
