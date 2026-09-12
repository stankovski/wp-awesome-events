(function() {
    'use strict';

    const { registerBlockVariation } = wp.blocks;
    const { __ } = wp.i18n;

    registerBlockVariation('core/query', {
        name: 'awesome-calendar-events/event-list',
        title: __('Event List', 'awesome-calendar-events'),
        description: __('Display a list of upcoming events, optionally filtered by category or tags. Recurring events are shown once.', 'awesome-calendar-events'),
        icon: window.awecalBlockIcons.eventList,
        keywords: [
            __('event', 'awesome-calendar-events'),
            __('events', 'awesome-calendar-events'),
            __('calendar', 'awesome-calendar-events'),
            __('upcoming', 'awesome-calendar-events')
        ],
        isActive: ['namespace'],
        attributes: {
            namespace: 'awesome-calendar-events/event-list',
            query: {
                perPage: 10,
                pages: 0,
                offset: 0,
                postType: 'post',
                order: 'asc',
                orderBy: 'date',
                author: '',
                search: '',
                exclude: [],
                sticky: '',
                inherit: false,
                taxQuery: {},
                awecalEventList: true
            }
        },
        // Only the built-in taxonomy filter (categories / tags) is exposed.
        allowedControls: ['taxQuery'],
        // Default post template: Event Date on top, Featured Image and
        // Title at the bottom.
        innerBlocks: [
            ['core/post-template', {}, [
                ['awesome-calendar-events/event-date'],
                ['core/post-featured-image'],
                ['core/post-title']
            ]]
        ],
        scope: ['inserter', 'transform']
    });
})();
