(function() {
    'use strict';

    const { registerBlockType } = wp.blocks;
    const { createElement: el } = wp.element;
    const { InnerBlocks, useBlockProps } = wp.blockEditor;
    const { __ } = wp.i18n;

    const template = [
        ['core/paragraph', {
            placeholder: __('Announcement content will be displayed here...', 'awesome-calendar-events'),
            metadata: {
                bindings: {
                    content: {
                        source: 'awesome-calendar-events/announcement',
                        args: {
                            key: '_awecal_announcement'
                        }
                    }
                }
            }
        }]
    ];

    registerBlockType('awesome-calendar-events/announcement-container', {
        title: __('Event Announcement', 'awesome-calendar-events'),
        icon: window.awecalBlockIcons.announcement,
        category: 'awesome-calendar-events',
        description: __('A container that displays content when the current post has an unexpired announcement.', 'awesome-calendar-events'),
        keywords: [
            __('announcement', 'awesome-calendar-events'),
            __('container', 'awesome-calendar-events'),
            __('conditional', 'awesome-calendar-events')
        ],
        supports: {
            align: true,
            anchor: true,
            color: {
                gradients: true,
                link: true,
                text: true,
                background: true
            },
            spacing: {
                margin: true,
                padding: true
            },
            typography: {
                fontSize: true,
                lineHeight: true
            },
            __experimentalBorder: {
                color: true,
                radius: true,
                style: true,
                width: true
            }
        },
        usesContext: ['postId'],
        attributes: {},

        edit: function() {
            const blockProps = useBlockProps({
                className: 'awecal-announcement-container-editor'
            });

            return el(
                'div',
                blockProps,
                el(InnerBlocks, {
                    template: template,
                    templateLock: false,
                    renderAppender: InnerBlocks.ButtonBlockAppender
                })
            );
        },

        save: function() {
            return el(InnerBlocks.Content);
        }
    });
})();
