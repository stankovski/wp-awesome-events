(function(){
    const { registerBlockType } = wp.blocks;
    const { __ } = wp.i18n;
    const { InspectorControls, useBlockProps } = wp.blockEditor || wp.editor;
    const { PanelBody, TextControl, ToggleControl, SelectControl } = wp.components;

    registerBlockType('icob/event-date', {
        title: __('Event Date','awesome-events'),
        description: __('Displays the upcoming event date or recurring weekdays for the current post.','awesome-events'),
        icon: 'calendar-alt',
        category: 'icob',
        supports: { html: false, color: { text: true, background: true }, typography: { fontSize: true, lineHeight: true } },
        attributes: {
            format: { type: 'string', default: 'F j, Y' },
            timeFormat: { type: 'string', default: 'g:i A' },
            dataType: { type: 'string', default: 'date' },
            fallbackText: { type: 'string', default: '' },
            showLabel: { type: 'boolean', default: false },
            labelText: { type: 'string', default: __('Event Date:', 'awesome-events') },
            showWeekdaysWhenMissing: { type: 'boolean', default: true },
            wrapTag: { type: 'string', default: 'div' },
            locationMetaKey: { type: 'string', default: '_icob_event_location' },
            relativeCurrentWeek: { type: 'boolean', default: false }
        },
        edit: (props) => {
            const { attributes, setAttributes } = props;
            const { format, timeFormat, dataType, fallbackText, showLabel, labelText, showWeekdaysWhenMissing, wrapTag, locationMetaKey, relativeCurrentWeek } = attributes;
            const blockProps = useBlockProps({ className: 'icob-event-date-block-editor' });

            // Adjust default label automatically when type changes if label was one of defaults.
            function onChangeDataType(v){
                const defaults = [ __('Event Date:', 'awesome-events'), __('Event Time:', 'awesome-events'), __('Event Location:', 'awesome-events') ];
                let newLabel = labelText;
                if (defaults.includes(labelText)) {
                    if (v==='time') newLabel = __('Event Time:', 'awesome-events');
                    else if (v==='location') newLabel = __('Event Location:', 'awesome-events');
                    else newLabel = __('Event Date:', 'awesome-events');
                }
                setAttributes({ dataType: v, labelText: newLabel });
            }

            return (
                wp.element.createElement(wp.element.Fragment, null,
                    wp.element.createElement(InspectorControls, null,
                        wp.element.createElement(PanelBody, { title: __('Display Settings','awesome-events'), initialOpen: true },
                            wp.element.createElement(SelectControl, {
                                label: __('Data Type','awesome-events'),
                                value: dataType,
                                options: [
                                    { label: __('Date','awesome-events'), value: 'date' },
                                    { label: __('Time','awesome-events'), value: 'time' },
                                    { label: __('Location','awesome-events'), value: 'location' }
                                ],
                                onChange: onChangeDataType
                            }),
                            dataType==='date' && wp.element.createElement(TextControl, {
                                label: __('Date Format','awesome-events'),
                                help: __('PHP date format (e.g. F j, Y).','awesome-events'),
                                value: format,
                                onChange: (v)=>setAttributes({ format: v })
                            }),
                            dataType==='time' && wp.element.createElement(TextControl, {
                                label: __('Time Format','awesome-events'),
                                help: __('PHP time format (e.g. g:i A).','awesome-events'),
                                value: timeFormat,
                                onChange: (v)=>setAttributes({ timeFormat: v })
                            }),
                            dataType==='location' && wp.element.createElement(TextControl, {
                                label: __('Location Meta Key','awesome-events'),
                                help: __('Post meta key where the event location is stored.','awesome-events'),
                                value: locationMetaKey,
                                onChange: (v)=>setAttributes({ locationMetaKey: v })
                            }),
                            wp.element.createElement(ToggleControl, {
                                label: __('Show Label','awesome-events'),
                                checked: showLabel,
                                onChange: (v)=>setAttributes({ showLabel: v })
                            }),
                            showLabel && wp.element.createElement(TextControl, {
                                label: __('Label Text','awesome-events'),
                                value: labelText,
                                onChange: (v)=>setAttributes({ labelText: v })
                            }),
                            dataType==='date' && wp.element.createElement(ToggleControl, {
                                label: __('Show Weekdays When Missing','awesome-events'),
                                checked: showWeekdaysWhenMissing,
                                onChange: (v)=>setAttributes({ showWeekdaysWhenMissing: v })
                            }),
                            dataType==='date' && wp.element.createElement(ToggleControl, {
                                label: __('Relative Current Week Output','awesome-events'),
                                help: __('Show plural weekdays for weekly recurrence or "This Monday" for single events within current week.','awesome-events'),
                                checked: relativeCurrentWeek,
                                onChange: (v)=>setAttributes({ relativeCurrentWeek: v })
                            }),
                            wp.element.createElement(TextControl, {
                                label: __('Fallback Text','awesome-events'),
                                help: __('Shown if no data is available (leave blank to hide block).','awesome-events'),
                                value: fallbackText,
                                onChange: (v)=>setAttributes({ fallbackText: v })
                            }),
                            wp.element.createElement(SelectControl, {
                                label: __('Wrapper Tag','awesome-events'),
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
                        wp.element.createElement('em', null, dataType==='date' ? __('(Date/Recurrence rendered on frontend)','awesome-events') : (dataType==='time' ? __('(Time rendered on frontend)','awesome-events') : __('(Location rendered on frontend)','awesome-events')) )
                    )
                )
            );
        },
        save: () => null
    });
})();
