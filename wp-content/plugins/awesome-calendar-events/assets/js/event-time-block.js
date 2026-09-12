(function(){
    const { registerBlockType } = wp.blocks;
    const shared = window.awecalEventBlocks;
    if (!shared || !shared.createEventMetaEdit) { return; }

    registerBlockType('awesome-calendar-events/event-time', {
        icon: window.awecalBlockIcons.eventTime,
        edit: shared.createEventMetaEdit({ type: 'time', metaKey: '_awecal_event_start_time' }),
        save: () => null
    });
})();
