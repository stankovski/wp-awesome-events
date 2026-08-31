(function() {
    const { registerBlockType } = wp.blocks;
    const { __ } = wp.i18n;
    const { InspectorControls, useBlockProps } = wp.blockEditor || wp.editor;
    const { PanelBody, TextControl, ToggleControl, FormTokenField } = wp.components;
    const { useState, useEffect } = wp.element;
    const apiFetch = wp.apiFetch;

    registerBlockType('awesome-calendar-events/event-countdown', {
        title: __('Event Countdown', 'awesome-calendar-events'),
        description: __('Displays a live countdown timer to the next event occurrence.', 'awesome-calendar-events'),
        icon: 'clock',
        category: 'awesome-calendar-events',
        supports: {
            html: false,
            anchor: true,
            align: ['left', 'center', 'right', 'wide', 'full'],
            className: true,
            color: { text: true, background: true },
            typography: { fontSize: true, lineHeight: true, fontWeight: true, fontFamily: true },
            spacing: { margin: true, padding: true }
        },
        attributes: {
            postId: { type: 'integer', default: 0 },
            showLabel: { type: 'boolean', default: true },
            labelText: { type: 'string', default: __('Countdown to Event:', 'awesome-calendar-events') },
            showDays: { type: 'boolean', default: true },
            showHours: { type: 'boolean', default: true },
            showMinutes: { type: 'boolean', default: true },
            showSeconds: { type: 'boolean', default: false },
            separator: { type: 'string', default: ':' },
            completedText: { type: 'string', default: __('Event has started!', 'awesome-calendar-events') },
            daysLabel: { type: 'string', default: __('d', 'awesome-calendar-events') },
            hoursLabel: { type: 'string', default: __('h', 'awesome-calendar-events') },
            minutesLabel: { type: 'string', default: __('m', 'awesome-calendar-events') },
            secondsLabel: { type: 'string', default: __('s', 'awesome-calendar-events') }
        },
        edit: function EditComponent(props) {
            const { attributes, setAttributes } = props;
            const {
                postId,
                showLabel,
                labelText,
                showDays,
                showHours,
                showMinutes,
                showSeconds,
                separator,
                completedText,
                daysLabel,
                hoursLabel,
                minutesLabel,
                secondsLabel
            } = attributes;

            const [searchTerm, setSearchTerm] = useState('');
            const [selectedPostTitle, setSelectedPostTitle] = useState('');
            const [posts, setPosts] = useState([]);
            const [isLoadingPosts, setIsLoadingPosts] = useState(true);

            // Fetch posts with event metadata using custom REST endpoint
            useEffect(() => {
                setIsLoadingPosts(true);

                const searchParam = searchTerm ? '&search=' + encodeURIComponent(searchTerm) : '';
                const path = '/icob/v1/event-posts?per_page=100' + searchParam;

                apiFetch({ path })
                    .then((eventPosts) => {
                        setPosts(eventPosts || []);
                        setIsLoadingPosts(false);
                    })
                    .catch((error) => {
                        console.error('Error fetching event posts:', error);
                        // Fallback to regular posts endpoint
                        const fallbackPath = '/wp/v2/posts?per_page=100&status=publish&orderby=title&order=asc' + searchParam;
                        return apiFetch({ path: fallbackPath });
                    })
                    .then((fallbackPosts) => {
                        if (fallbackPosts) {
                            setPosts(fallbackPosts || []);
                        }
                        setIsLoadingPosts(false);
                    })
                    .catch((err) => {
                        console.error('Error fetching posts:', err);
                        setPosts([]);
                        setIsLoadingPosts(false);
                    });
            }, [searchTerm]);

            // Use posts directly
            const eventPosts = posts;

            // Get selected post title
            useEffect(() => {
                if (postId && posts.length > 0) {
                    const selected = posts.find(p => p.id === postId);
                    if (selected) {
                        const title = selected.title?.rendered || selected.title?.raw || selected.title || `Post #${postId}`;
                        setSelectedPostTitle(title);
                    }
                }
            }, [postId, posts]);

            // Build suggestions for autocomplete
            const suggestions = eventPosts.map(post => {
                const title = post.title?.rendered || post.title?.raw || post.title || `Post #${post.id}`;
                return {
                    id: post.id,
                    value: title
                };
            });

            const handlePostSelection = (tokens) => {
                if (tokens.length === 0) {
                    setAttributes({ postId: 0 });
                    setSelectedPostTitle('');
                    return;
                }

                const selectedValue = tokens[0];
                const selected = suggestions.find(s => s.value === selectedValue);

                if (selected) {
                    setAttributes({ postId: selected.id });
                    setSelectedPostTitle(selected.value);
                }
            };

            const blockProps = useBlockProps({
                className: 'awecal-event-countdown-editor'
            });

            return wp.element.createElement(
                wp.element.Fragment,
                null,
                wp.element.createElement(
                    InspectorControls,
                    null,
                    wp.element.createElement(
                        PanelBody,
                        { title: __('Event Selection', 'awesome-calendar-events'), initialOpen: true },
                        wp.element.createElement(
                            'p',
                            { style: { marginBottom: '8px', fontSize: '12px', color: '#757575' } },
                            __('Select a post with event metadata:', 'awesome-calendar-events')
                        ),
                        wp.element.createElement(FormTokenField, {
                            label: __('Search Events', 'awesome-calendar-events'),
                            value: selectedPostTitle ? [selectedPostTitle] : [],
                            suggestions: suggestions.map(s => s.value),
                            onChange: handlePostSelection,
                            maxSuggestions: 20,
                            placeholder: __('Type to search for events...', 'awesome-calendar-events'),
                            __experimentalShowHowTo: false,
                            __experimentalExpandOnFocus: true
                        }),
                        isLoadingPosts && wp.element.createElement(
                            'p',
                            { style: { fontSize: '12px', fontStyle: 'italic' } },
                            __('Loading posts...', 'awesome-calendar-events')
                        ),
                        posts.length === 0 && !isLoadingPosts && wp.element.createElement(
                            'p',
                            { style: { fontSize: '12px', color: '#d63638' } },
                            __('No posts found.', 'awesome-calendar-events')
                        )
                    ),
                    wp.element.createElement(
                        PanelBody,
                        { title: __('Display Settings', 'awesome-calendar-events'), initialOpen: true },
                        wp.element.createElement(ToggleControl, {
                            label: __('Show Label', 'awesome-calendar-events'),
                            checked: showLabel,
                            onChange: (value) => setAttributes({ showLabel: value })
                        }),
                        showLabel && wp.element.createElement(TextControl, {
                            label: __('Label Text', 'awesome-calendar-events'),
                            value: labelText,
                            onChange: (value) => setAttributes({ labelText: value })
                        }),
                        wp.element.createElement(ToggleControl, {
                            label: __('Show Days', 'awesome-calendar-events'),
                            checked: showDays,
                            onChange: (value) => setAttributes({ showDays: value })
                        }),
                        wp.element.createElement(ToggleControl, {
                            label: __('Show Hours', 'awesome-calendar-events'),
                            checked: showHours,
                            onChange: (value) => setAttributes({ showHours: value })
                        }),
                        wp.element.createElement(ToggleControl, {
                            label: __('Show Minutes', 'awesome-calendar-events'),
                            checked: showMinutes,
                            onChange: (value) => setAttributes({ showMinutes: value })
                        }),
                        wp.element.createElement(ToggleControl, {
                            label: __('Show Seconds', 'awesome-calendar-events'),
                            checked: showSeconds,
                            help: __('Updates every second (may impact performance)', 'awesome-calendar-events'),
                            onChange: (value) => setAttributes({ showSeconds: value })
                        }),
                        wp.element.createElement(TextControl, {
                            label: __('Separator', 'awesome-calendar-events'),
                            help: __('Character between time units (if using compact labels)', 'awesome-calendar-events'),
                            value: separator,
                            onChange: (value) => setAttributes({ separator: value })
                        }),
                        wp.element.createElement(TextControl, {
                            label: __('Completed Text', 'awesome-calendar-events'),
                            help: __('Shown when countdown reaches zero', 'awesome-calendar-events'),
                            value: completedText,
                            onChange: (value) => setAttributes({ completedText: value })
                        })
                    ),
                    wp.element.createElement(
                        PanelBody,
                        { title: __('Unit Labels', 'awesome-calendar-events'), initialOpen: false },
                        wp.element.createElement(TextControl, {
                            label: __('Days Label', 'awesome-calendar-events'),
                            value: daysLabel,
                            onChange: (value) => setAttributes({ daysLabel: value })
                        }),
                        wp.element.createElement(TextControl, {
                            label: __('Hours Label', 'awesome-calendar-events'),
                            value: hoursLabel,
                            onChange: (value) => setAttributes({ hoursLabel: value })
                        }),
                        wp.element.createElement(TextControl, {
                            label: __('Minutes Label', 'awesome-calendar-events'),
                            value: minutesLabel,
                            onChange: (value) => setAttributes({ minutesLabel: value })
                        }),
                        wp.element.createElement(TextControl, {
                            label: __('Seconds Label', 'awesome-calendar-events'),
                            value: secondsLabel,
                            onChange: (value) => setAttributes({ secondsLabel: value })
                        })
                    )
                ),
                wp.element.createElement(
                    'div',
                    blockProps,
                    !postId && wp.element.createElement(
                        'div',
                        { className: 'awecal-countdown-placeholder' },
                        wp.element.createElement('p', null, __('⏱️ Event Countdown', 'awesome-calendar-events')),
                        wp.element.createElement('p', { style: { fontSize: '13px', opacity: 0.7 } }, __('Select an event post from the sidebar to display a countdown timer.', 'awesome-calendar-events'))
                    ),
                    postId && wp.element.createElement(
                        'div',
                        { className: 'awecal-countdown-preview' },
                        showLabel && wp.element.createElement(
                            'div',
                            { className: 'awecal-countdown-label', style: { marginBottom: '8px', fontWeight: 'bold' } },
                            labelText
                        ),
                        wp.element.createElement(
                            'div',
                            { className: 'awecal-countdown-timer-preview', style: { display: 'flex', gap: '12px', alignItems: 'center' } },
                            showDays && wp.element.createElement(
                                'div',
                                { className: 'awecal-countdown-unit' },
                                wp.element.createElement('span', { className: 'value', style: { fontSize: '24px', fontWeight: 'bold' } }, '__'),
                                wp.element.createElement('span', { className: 'label', style: { fontSize: '12px', opacity: 0.7 } }, daysLabel)
                            ),
                            showHours && wp.element.createElement(
                                'div',
                                { className: 'awecal-countdown-unit' },
                                wp.element.createElement('span', { className: 'value', style: { fontSize: '24px', fontWeight: 'bold' } }, '__'),
                                wp.element.createElement('span', { className: 'label', style: { fontSize: '12px', opacity: 0.7 } }, hoursLabel)
                            ),
                            showMinutes && wp.element.createElement(
                                'div',
                                { className: 'awecal-countdown-unit' },
                                wp.element.createElement('span', { className: 'value', style: { fontSize: '24px', fontWeight: 'bold' } }, '__'),
                                wp.element.createElement('span', { className: 'label', style: { fontSize: '12px', opacity: 0.7 } }, minutesLabel)
                            ),
                            showSeconds && wp.element.createElement(
                                'div',
                                { className: 'awecal-countdown-unit' },
                                wp.element.createElement('span', { className: 'value', style: { fontSize: '24px', fontWeight: 'bold' } }, '__'),
                                wp.element.createElement('span', { className: 'label', style: { fontSize: '12px', opacity: 0.7 } }, secondsLabel)
                            )
                        ),
                        wp.element.createElement(
                            'p',
                            { style: { marginTop: '8px', fontSize: '11px', opacity: 0.6, fontStyle: 'italic' } },
                            __('Preview - Live countdown will appear on the frontend', 'awesome-calendar-events')
                        ),
                        selectedPostTitle && wp.element.createElement(
                            'p',
                            { style: { marginTop: '4px', fontSize: '12px', opacity: 0.7 } },
                            __('Event: ', 'awesome-calendar-events') + selectedPostTitle
                        )
                    )
                )
            );
        },
        save: function() {
            return null; // Dynamic block, rendered via PHP
        }
    });
})();
