(function() {
    'use strict';

    // Calendar URL generators
    function generateGoogleUrl(title, start, end, description, location, url) {
        const params = new URLSearchParams({
            action: 'TEMPLATE',
            text: title,
            dates: start + '/' + end,
            details: description + '\n\n' + url,
            location: location || ''
        });
        return 'https://calendar.google.com/calendar/render?' + params.toString();
    }

    function generateOutlookUrl(title, start, end, description, location, url) {
        const params = new URLSearchParams({
            path: '/calendar/action/compose',
            rru: 'addevent',
            subject: title,
            startdt: start,
            enddt: end,
            body: description + '\n\n' + url,
            location: location || ''
        });
        return 'https://outlook.live.com/calendar/0/deeplink/compose?' + params.toString();
    }

    function generateOffice365Url(title, start, end, description, location, url) {
        const params = new URLSearchParams({
            path: '/calendar/action/compose',
            rru: 'addevent',
            subject: title,
            startdt: start,
            enddt: end,
            body: description + '\n\n' + url,
            location: location || ''
        });
        return 'https://outlook.office.com/calendar/0/deeplink/compose?' + params.toString();
    }

    function generateICalUrl(postId) {
        const params = new URLSearchParams({
            ics: 'event',
            post_id: postId
        });
        return window.location.origin + '/?' + params.toString();
    }

    function formatDateToICalUTC(dateStr, timeStr, timezoneOffset) {
        // Parse the date and time
        const date = new Date(dateStr + 'T' + (timeStr || '00:00') + ':00');

        // Convert to UTC
        const utcDate = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));

        // Format as YYYYMMDDTHHMMSSZ
        const year = utcDate.getUTCFullYear();
        const month = String(utcDate.getUTCMonth() + 1).padStart(2, '0');
        const day = String(utcDate.getUTCDate()).padStart(2, '0');
        const hours = String(utcDate.getUTCHours()).padStart(2, '0');
        const minutes = String(utcDate.getUTCMinutes()).padStart(2, '0');
        const seconds = String(utcDate.getUTCSeconds()).padStart(2, '0');

        return year + month + day + 'T' + hours + minutes + seconds + 'Z';
    }

    function formatDateToOutlookISO(dateStr, timeStr) {
        // Parse the date and time
        const date = new Date(dateStr + 'T' + (timeStr || '00:00') + ':00');

        // Convert to UTC
        const utcDate = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));

        // Format as YYYY-MM-DDTHH:mm:SSZ (ISO 8601)
        const year = utcDate.getUTCFullYear();
        const month = String(utcDate.getUTCMonth() + 1).padStart(2, '0');
        const day = String(utcDate.getUTCDate()).padStart(2, '0');
        const hours = String(utcDate.getUTCHours()).padStart(2, '0');
        const minutes = String(utcDate.getUTCMinutes()).padStart(2, '0');
        const seconds = String(utcDate.getUTCSeconds()).padStart(2, '0');

        return year + '-' + month + '-' + day + 'T' + hours + ':' + minutes + ':' + seconds + 'Z';
    }

    function calculateEndTime(startDate, durationHours) {
        const hours = Math.floor(durationHours || 1);
        const minutes = Math.round(((durationHours || 1) - hours) * 60);

        const endDate = new Date(startDate.getTime() + (hours * 3600000) + (minutes * 60000));
        return endDate;
    }

    // Create and show the modal dialog
    function showCalendarDialog(postId, eventData) {
        // Get plugin URL for icon paths
        const pluginUrl = icobCalendarData?.pluginUrl || '/wp-content/plugins/awesome-events';
        const iconPath = pluginUrl + '/assets/images/calendar-icons';

        // For recurring events, all services will download ICS files
        const isRecurring = eventData.isRecurring;
        const linkTarget = isRecurring ? '' : 'target="_blank" rel="noopener noreferrer"';
        const linkType = isRecurring ? 'download="event.ics"' : '';

        // Create modal overlay
        const modal = document.createElement('div');
        modal.className = 'icob-calendar-modal';
        modal.innerHTML = `
            <div class="icob-calendar-modal-content">
                <div class="icob-calendar-modal-header">
                    <h3>${eventData.title || 'Add to Calendar'}</h3>
                    <button class="icob-calendar-modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="icob-calendar-modal-body">
                    ${isRecurring ? '<p class="icob-calendar-modal-description">This is a recurring event. Download the calendar file to add it to your calendar app:</p>' : '<p class="icob-calendar-modal-description">Choose your calendar service:</p>'}
                    <div class="icob-calendar-options">
                        <a href="${eventData.urls.google}" ${linkTarget} ${linkType} class="icob-calendar-option">
                            <img src="${iconPath}/google-calendar.svg" alt="Google Calendar" width="24" height="24" class="icob-calendar-icon">
                            <span>Google Calendar${isRecurring ? ' (.ics)' : ''}</span>
                        </a>
                        <a href="${eventData.urls.outlook}" ${linkTarget} ${linkType} class="icob-calendar-option">
                            <img src="${iconPath}/outlook-calendar.svg" alt="Outlook.com" width="24" height="24" class="icob-calendar-icon">
                            <span>Outlook.com${isRecurring ? ' (.ics)' : ''}</span>
                        </a>
                        <a href="${eventData.urls.office365}" ${linkTarget} ${linkType} class="icob-calendar-option">
                            <img src="${iconPath}/office-calendar.svg" alt="Office 365" width="24" height="24" class="icob-calendar-icon">
                            <span>Office 365${isRecurring ? ' (.ics)' : ''}</span>
                        </a>
                        <a href="${eventData.urls.apple}" download="event.ics" class="icob-calendar-option">
                            <img src="${iconPath}/apple-calendar.svg" alt="Apple Calendar" width="24" height="24" class="icob-calendar-icon">
                            <span>Apple Calendar (.ics)</span>
                        </a>
                        <a href="${eventData.urls.ical}" download="event.ics" class="icob-calendar-option">
                            <svg class="icob-calendar-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            <span>Yahoo / Other (.ics file)</span>
                        </a>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Close handlers
        const closeModal = () => {
            modal.classList.add('icob-calendar-modal-closing');
            setTimeout(() => {
                if (modal.parentNode) {
                    modal.parentNode.removeChild(modal);
                }
            }, 300);
        };

        modal.querySelector('.icob-calendar-modal-close').addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal();
            }
        });

        // Close on ESC key
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                closeModal();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);

        // Animate in
        setTimeout(() => {
            modal.classList.add('icob-calendar-modal-open');
        }, 10);
    }

    // Get event data from post meta
    function getEventData() {
        // Get data from hidden element
        const eventDataEl = document.getElementById('icob-event-data');

        if (!eventDataEl) {
            console.warn('Event data not found in page');
            return null;
        }

        const postId = eventDataEl.getAttribute('data-post-id');
        const eventDate = eventDataEl.getAttribute('data-event-date');
        const eventTime = eventDataEl.getAttribute('data-event-time') || '00:00';
        const duration = parseFloat(eventDataEl.getAttribute('data-event-duration') || '1');
        const location = eventDataEl.getAttribute('data-event-location') || '';
        const recurrenceType = eventDataEl.getAttribute('data-recurrence-type') || 'none';
        const title = document.title.split(' - ')[0] || document.title;
        const description = document.querySelector('meta[name="description"]')?.content || '';
        const url = window.location.href;

        // Check if this is a recurring event
        const isRecurring = recurrenceType && recurrenceType !== 'none';

        // For recurring events, use ICS files for all services
        // because Google Calendar and Outlook don't support RRULE in their URL schemes
        if (isRecurring) {
            const icalUrl = generateICalUrl(postId);
            return {
                postId: postId,
                title: title,
                isRecurring: true,
                urls: {
                    google: icalUrl,
                    outlook: icalUrl,
                    office365: icalUrl,
                    apple: icalUrl,
                    ical: icalUrl
                }
            };
        }

        // For non-recurring events, generate service-specific URLs
        // Format dates
        const startFormatted = formatDateToICalUTC(eventDate, eventTime);
        const startDate = new Date(eventDate + 'T' + eventTime + ':00');
        const endDate = calculateEndTime(startDate, duration);
        const endFormatted = formatDateToICalUTC(
            endDate.toISOString().split('T')[0],
            endDate.toTimeString().split(' ')[0].substring(0, 5)
        );

        // Outlook needs ISO 8601 format with hyphens and colons
        const startOutlook = formatDateToOutlookISO(eventDate, eventTime);
        const endOutlook = formatDateToOutlookISO(
            endDate.toISOString().split('T')[0],
            endDate.toTimeString().split(' ')[0].substring(0, 5)
        );

        return {
            postId: postId,
            title: title,
            isRecurring: false,
            urls: {
                google: generateGoogleUrl(title, startFormatted, endFormatted, description, location, url),
                outlook: generateOutlookUrl(title, startOutlook, endOutlook, description, location, url),
                office365: generateOffice365Url(title, startOutlook, endOutlook, description, location, url),
                apple: generateICalUrl(postId),
                ical: generateICalUrl(postId)
            }
        };
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        // Find all "Add to Calendar" buttons
        const buttons = document.querySelectorAll('.wp-block-button.is-style-add-to-calendar a, a.wp-block-button__link[href="#add-to-calendar"]');

        buttons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();

                const eventData = getEventData();
                if (!eventData) {
                    alert('Event information not available for this post.');
                    return;
                }

                showCalendarDialog(eventData.postId, eventData);
            });
        });

        // Check if the URL has ?action=addToCalendar query parameter
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('action') === 'addToCalendar') {
            const eventData = getEventData();
            if (eventData) {
                // Automatically show the dialog
                showCalendarDialog(eventData.postId, eventData);
            }
        }
    });

})();
