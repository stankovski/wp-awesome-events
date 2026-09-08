/**
 * Shared editor logic for the Awesome Calendar Events meta blocks
 * (event-date, event-time, event-location).
 *
 * Provides a factory that builds an `edit` component implementing the
 * core Post Date UX:
 *  - "Default format" toggle (DateFormatPicker for dates) falling back to
 *    the site format, with a custom format text box when unselected.
 *  - Live editor preview of the event value. Values come from the plugin's
 *    REST endpoint (`/icob/v1/event-display/:id`), which mirrors the
 *    server-rendered computation (next occurrence for recurring events,
 *    weekday fallbacks, relative weeks), with unsaved client-side meta
 *    edits taking precedence so the pencil fast-edit updates instantly.
 *  - Pencil toolbar button that fast-edits the underlying event meta
 *    (writes back through editEntityRecord), like the Post Date block.
 *  - Optional "Label" attribute prefixed to the value.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.data ) {
		return;
	}

	const { createElement, Fragment, useState, useEffect, useRef } = wp.element;
	const { __, _x } = wp.i18n;
	const { dateI18n, format: formatDate, getSettings } = wp.date;
	const blockEditor = wp.blockEditor || wp.editor;
	const { InspectorControls, BlockControls, useBlockProps } = blockEditor;
	const components = wp.components;
	// The experimental format pickers are exported from @wordpress/block-editor;
	// fall back to @wordpress/components for older builds.
	const DateFormatPicker =
		blockEditor.__experimentalDateFormatPicker || components.__experimentalDateFormatPicker;
	const PublishDateTimePicker =
		blockEditor.__experimentalPublishDateTimePicker ||
		components.__experimentalPublishDateTimePicker;
	const { PanelBody, TextControl, ToggleControl, Dropdown, ToolbarGroup, ToolbarButton } =
		components;
	const { useSelect, useDispatch } = wp.data;
	const apiFetch = wp.apiFetch;
	// Dashicon slug; avoids a dependency on the @wordpress/icons package
	// (which is not exposed as a core script handle).
	const pencil = 'edit';

	function parseYmd( value ) {
		const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( value || '' ) );
		return match ? new Date( +match[ 1 ], +match[ 2 ] - 1, +match[ 3 ] ) : null;
	}

	// Detect 12-hour time formats: unescaped 'a', 'A', 'g' or 'h' PHP chars.
	function is12HourFormat( format ) {
		return /(?:^|[^\\])[aAgh]/.test( format || '' );
	}

	// Fetch the computed display values for a post (saved data only).
	function fetchEventDisplay( postId ) {
		if ( ! apiFetch ) {
			return Promise.resolve( null );
		}
		return apiFetch( { path: '/icob/v1/event-display/' + postId } ).catch( () => null );
	}

	/**
	 * Build an `edit` component for one of the event meta blocks.
	 *
	 * @param {Object} options           Options.
	 * @param {string} options.type      One of 'date', 'time', 'location'.
	 * @param {string} options.metaKey   Post meta key backing the value.
	 * @return {Function} Edit component.
	 */
	function createEventMetaEdit( options ) {
		const settings = Object.assign( { type: 'date', metaKey: '' }, options );
		const type = settings.type;

		const labels = {
			date: {
				change: __( 'Change Event Date', 'awesome-calendar-events' ),
				pickerTitle: __( 'Event Date', 'awesome-calendar-events' ),
				missing: __( '(no event date set)', 'awesome-calendar-events' ),
			},
			time: {
				change: __( 'Change Event Time', 'awesome-calendar-events' ),
				pickerTitle: __( 'Event Time', 'awesome-calendar-events' ),
				missing: __( '(no event time set)', 'awesome-calendar-events' ),
			},
			location: {
				change: __( 'Change Event Location', 'awesome-calendar-events' ),
				pickerTitle: __( 'Event Location', 'awesome-calendar-events' ),
				missing: __( '(no event location set)', 'awesome-calendar-events' ),
			},
		};

		return function EventMetaEdit( props ) {
			const { attributes, setAttributes, context } = props;
			const blockProps = useBlockProps();

			// Context postId/postType (query loop), falling back to the editor's current post.
			const editorPost = useSelect( ( select ) => {
				if ( context && context.postId ) {
					return null;
				}
				let editorStore;
				try {
					editorStore = select( 'core/editor' );
				} catch ( e ) {
					editorStore = null;
				}
				return editorStore
					? {
							id: editorStore.getCurrentPostId(),
							type: editorStore.getCurrentPostType(),
					  }
					: null;
			}, [] );
			const postId = ( context && context.postId ) || ( editorPost && editorPost.id ) || null;
			const postType = ( context && context.postType ) || ( editorPost && editorPost.type ) || 'post';
			// Fast-editing a post inside a query loop is locked (mirrors core/post-data).
			const inQueryLoop = context && Number.isFinite( context.queryId );

			// Re-fetch the computed display when the post is saved so recurrence
			// math picks up newly saved meta.
			const isSaving = useSelect( ( select ) => {
				let editorStore;
				try {
					editorStore = select( 'core/editor' );
				} catch ( e ) {
					editorStore = null;
				}
				return editorStore ? editorStore.isSavingPost() : false;
			}, [] );
			const wasSaving = useRef( false );
			const [ savedAt, setSavedAt ] = useState( 0 );
			useEffect( () => {
				if ( wasSaving.current && ! isSaving ) {
					setSavedAt( Date.now() );
				}
				wasSaving.current = isSaving;
			}, [ isSaving ] );

			const { siteDateFormat, siteTimeFormat, meta, metaHasKey, canEdit } = useSelect(
				( select ) => {
					const core = select( 'core' );
					const { getEntityRecord, getEditedEntityRecord, canUser } = core;
					const dateSettings = getSettings();
					const site = getEntityRecord( 'root', 'site' );

					let metaValues = {};
					let hasKey = false;
					if ( postId ) {
						// getEntityRecord triggers resolution; getEditedEntityRecord
						// carries unsaved edits on top of it.
						const base = getEntityRecord( 'postType', postType, postId );
						const edited = getEditedEntityRecord( 'postType', postType, postId );
						metaValues = Object.assign(
							{},
							( base && base.meta ) || {},
							( edited && edited.meta ) || {}
						);
						if ( metaValues && Object.prototype.hasOwnProperty.call( metaValues, settings.metaKey ) ) {
							hasKey = true;
						}

						// Fallback for the currently edited post when core-data has no meta.
						if ( ! hasKey ) {
							let editorStore = null;
							try {
								editorStore = select( 'core/editor' );
							} catch ( e ) {
								editorStore = null;
							}
							const isCurrentPost =
								editorStore &&
								Number( postId ) === Number( editorStore.getCurrentPostId() ) &&
								postType === editorStore.getCurrentPostType();
							const currentPost = isCurrentPost ? editorStore.getCurrentPost() : null;
							if ( currentPost && currentPost.meta && Object.prototype.hasOwnProperty.call( currentPost.meta, settings.metaKey ) ) {
								metaValues = currentPost.meta;
								hasKey = true;
							}
						}
					}

					let editable = false;
					if ( postId && postType && ! inQueryLoop ) {
						editable = !! canUser( 'update', {
							kind: 'postType',
							name: postType,
							id: postId,
						} );
					}

					return {
						siteDateFormat: ( site && site.date_format ) || dateSettings.formats.date,
						siteTimeFormat: ( site && site.time_format ) || dateSettings.formats.time,
						meta: metaValues,
						metaHasKey: hasKey,
						canEdit: editable,
					};
				},
				[ postId, postType, inQueryLoop ]
			);

			const { editEntityRecord } = useDispatch( 'core' );

			// Computed display values from the REST endpoint (saved data).
			const [ restDisplay, setRestDisplay ] = useState( null );
			const rawEdited = metaHasKey ? meta[ settings.metaKey ] || '' : '';
			useEffect( () => {
				let cancelled = false;
				if ( ! postId ) {
					setRestDisplay( null );
					return;
				}
				fetchEventDisplay( postId ).then( ( d ) => {
					if ( ! cancelled ) {
						setRestDisplay( d );
					}
				} );
				return () => {
					cancelled = true;
				};
			}, [ postId, savedAt ] );

			function getRawValue() {
				return rawEdited;
			}

			function updateMeta( value ) {
				if ( ! postId || ! settings.metaKey ) {
					return;
				}
				editEntityRecord( 'postType', postType, postId, {
					meta: { [ settings.metaKey ]: value },
				} );
			}

			function formatTime( raw ) {
				const parsed = /^(\d{1,2}):(\d{2})/.exec( String( raw ) );
				if ( ! parsed ) {
					return '';
				}
				return formatDate(
					attributes.timeFormat || siteTimeFormat,
					'2000-01-01T' + parsed[ 1 ] + ':' + parsed[ 2 ] + ':00'
				);
			}

			function formatYmd( raw ) {
				return parseYmd( raw )
					? formatDate( attributes.format || siteDateFormat, raw + 'T00:00:00' )
					: '';
			}

			// Resolve the preview value. Order: unsaved client-side edits (live),
			// then the saved server-side computation, then saved raw meta.
			function computeValue() {
				const weekdaysEnabled = ! ( attributes.showWeekdaysWhenMissing === false );

				if ( type === 'location' ) {
					if ( metaHasKey ) {
						return rawEdited;
					}
					return ( restDisplay && ( restDisplay.raw_location || restDisplay.location ) ) || '';
				}

				if ( type === 'time' ) {
					let raw;
					if ( metaHasKey ) {
						raw = rawEdited;
					} else {
						raw = ( restDisplay && ( restDisplay.raw_start_time || restDisplay.start_time ) ) || '';
					}
					return raw ? formatTime( raw ) : '';
				}

				// Date.
				if ( restDisplay ) {
					// When there are unsaved edits, prefer live client-side formatting.
					const dirty = metaHasKey && rawEdited !== ( restDisplay.raw_date || '' );
					if ( ! dirty ) {
						if ( attributes.relativeCurrentWeek && restDisplay.relative ) {
							return restDisplay.relative;
						}
						if ( restDisplay.date ) {
							return restDisplay.date;
						}
						if ( weekdaysEnabled && restDisplay.weekdays ) {
							return restDisplay.weekdays;
						}
						if ( metaHasKey && rawEdited ) {
							return formatYmd( rawEdited );
						}
						return '';
					}
				}
				if ( rawEdited ) {
					return formatYmd( rawEdited );
				}
				return '';
			}

			function renderPicker( onClose ) {
				const raw = getRawValue();
				const todayYmd = dateI18n( 'Y-m-d', new Date() );

				let currentDate;
				let onChange;
				if ( type === 'date' ) {
					currentDate = raw ? raw + 'T09:00:00' : todayYmd + 'T09:00:00';
					onChange = ( newDatetime ) => {
						if ( ! newDatetime ) {
							return;
						}
						const ymd = String( newDatetime ).slice( 0, 10 );
						if ( /^\d{4}-\d{2}-\d{2}$/.test( ymd ) ) {
							updateMeta( ymd );
						}
					};
				} else {
					currentDate = raw ? todayYmd + 'T' + raw + ':00' : todayYmd + 'T09:00:00';
					onChange = ( newDatetime ) => {
						if ( ! newDatetime ) {
							return;
						}
						const time = String( newDatetime ).slice( 11, 16 );
						if ( /^\d{2}:\d{2}$/.test( time ) ) {
							updateMeta( time );
						}
					};
				}

				return createElement( PublishDateTimePicker, {
					title: labels[ type ].pickerTitle,
					currentDate: currentDate,
					onChange: onChange,
					is12Hour: is12HourFormat( siteTimeFormat ),
					onClose: onClose,
				} );
			}

			function renderLocationEditor() {
				return createElement(
					'div',
					{ style: { padding: '12px', minWidth: '260px' } },
					createElement( TextControl, {
						label: labels[ type ].pickerTitle,
						value: getRawValue(),
						onChange: ( value ) => updateMeta( value ),
					} )
				);
			}

			let formatControl = null;
			if ( type === 'date' ) {
				if ( DateFormatPicker ) {
					formatControl = createElement( DateFormatPicker, {
						format: attributes.format,
						defaultFormat: siteDateFormat,
						onChange: ( nextFormat ) => setAttributes( { format: nextFormat || '' } ),
					} );
				} else {
					// Fallback for older WP versions without the experimental picker.
					formatControl = createElement( Fragment, null,
						createElement( ToggleControl, {
							label: __( 'Default format', 'awesome-calendar-events' ),
							help: __( 'Example:', 'awesome-calendar-events' ) + ' ' + dateI18n( siteDateFormat, new Date() ),
							checked: ! attributes.format,
							onChange: ( checked ) => setAttributes( { format: checked ? '' : siteDateFormat } ),
						} ),
						!! attributes.format && createElement( TextControl, {
							label: __( 'Date Format', 'awesome-calendar-events' ),
							help: __( 'PHP date format (e.g. F j, Y).', 'awesome-calendar-events' ),
							value: attributes.format,
							onChange: ( value ) => setAttributes( { format: value } ),
						} )
					);
				}
			} else if ( type === 'time' ) {
				formatControl = createElement( Fragment, null,
					createElement( ToggleControl, {
						label: __( 'Default format', 'awesome-calendar-events' ),
						help: __( 'Example:', 'awesome-calendar-events' ) + ' ' + dateI18n( siteTimeFormat, '2000-01-01T14:30:00' ),
						checked: ! attributes.timeFormat,
						onChange: ( checked ) => setAttributes( { timeFormat: checked ? '' : ( siteTimeFormat || 'g:i A' ) } ),
					} ),
					!! attributes.timeFormat && createElement( TextControl, {
						label: __( 'Time Format', 'awesome-calendar-events' ),
						help: __( 'PHP time format (e.g. g:i A).', 'awesome-calendar-events' ),
						value: attributes.timeFormat,
						onChange: ( value ) => setAttributes( { timeFormat: value } ),
					} )
				);
			}

			const labelControl = createElement( TextControl, {
				label: __( 'Label', 'awesome-calendar-events' ),
				help: __( 'Optional text prefixed to the value.', 'awesome-calendar-events' ),
				value: attributes.label || '',
				onChange: ( value ) => setAttributes( { label: value } ),
			} );

			const fallbackControl = createElement( TextControl, {
				label: __( 'Fallback Text', 'awesome-calendar-events' ),
				help: __( 'Shown if no data is available (leave blank to hide block).', 'awesome-calendar-events' ),
				value: attributes.fallbackText || '',
				onChange: ( value ) => setAttributes( { fallbackText: value } ),
			} );

			const dateToggles = [];
			if ( type === 'date' ) {
				dateToggles.push(
					createElement( ToggleControl, {
						label: __( 'Show Weekdays When Missing', 'awesome-calendar-events' ),
						checked: ! ( attributes.showWeekdaysWhenMissing === false ),
						onChange: ( value ) => setAttributes( { showWeekdaysWhenMissing: value } ),
					} ),
					createElement( ToggleControl, {
						label: __( 'Relative Current Week Output', 'awesome-calendar-events' ),
						help: __( 'Show plural weekdays for weekly recurrence or "This Monday" for single events within current week.', 'awesome-calendar-events' ),
						checked: !! attributes.relativeCurrentWeek,
						onChange: ( value ) => setAttributes( { relativeCurrentWeek: value } ),
					} )
				);
			}

			let toolbar = null;
			if ( canEdit && ( type === 'location' || PublishDateTimePicker ) ) {
				toolbar = createElement( BlockControls, { group: 'block' },
					createElement( ToolbarGroup, null,
						createElement( Dropdown, {
							popoverProps: { placement: 'bottom-start' },
							renderToggle: ( { isOpen, onToggle } ) =>
								createElement( ToolbarButton, {
									'aria-expanded': isOpen,
									icon: pencil,
									title: labels[ type ].change,
									onClick: onToggle,
								} ),
							renderContent: ( { onClose } ) =>
								type === 'location' ? renderLocationEditor() : renderPicker( onClose ),
						} )
					)
				);
			}

			const inspector = createElement( InspectorControls, null,
				createElement( PanelBody, { title: __( 'Display Settings', 'awesome-calendar-events' ), initialOpen: true },
					formatControl,
					dateToggles,
					labelControl,
					fallbackControl
				)
			);

			// Live preview: the value formatted per settings, with label prefix
			// and fallback, plus a muted placeholder when there is nothing to show.
			const value = computeValue();
			const hasValue = value !== '' && value !== undefined;
			const isEmpty = ! hasValue && ! ( attributes.fallbackText || '' );

			const children = [];
			if ( attributes.label ) {
				children.push( createElement( 'span', { className: 'awecal-event-label', key: 'label' }, attributes.label + ' ' ) );
			}
			children.push(
				createElement(
					'span',
					{ className: 'awecal-event-value', key: 'value' },
					hasValue ? value : ( attributes.fallbackText || '' )
				)
			);
			if ( isEmpty ) {
				children.push( createElement( 'em', { key: 'hint', style: { opacity: 0.6 } }, labels[ type ].missing ) );
			}

			return createElement( Fragment, null, toolbar, inspector, createElement( 'div', blockProps, children ) );
		};
	}

	window.awecalEventBlocks = {
		createEventMetaEdit: createEventMetaEdit,
	};
} )( window.wp );
