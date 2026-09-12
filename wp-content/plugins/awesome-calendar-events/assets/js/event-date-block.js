(function(){
    const { registerBlockType } = wp.blocks;
    const shared = window.awecalEventBlocks;
    if (!shared || !shared.createEventMetaEdit) { return; }

    registerBlockType('awesome-calendar-events/event-date', {
        icon: window.awecalBlockIcons.eventDate,
        edit: shared.createEventMetaEdit({ type: 'date', metaKey: '_awecal_event_date' }),
        save: () => null
    });
})();
