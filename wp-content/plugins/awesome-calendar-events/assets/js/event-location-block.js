(function(){
    const { registerBlockType } = wp.blocks;
    const shared = window.awecalEventBlocks;
    if (!shared || !shared.createEventMetaEdit) { return; }

    registerBlockType('awesome-calendar-events/event-location', {
        icon: window.awecalBlockIcons.eventLocation,
        edit: shared.createEventMetaEdit({ type: 'location', metaKey: '_awecal_event_location' }),
        save: () => null
    });
})();
