(function() {
    'use strict';

    /**
     * Initialize all countdown timers on the page
     */
    function initCountdowns() {
        const countdowns = document.querySelectorAll('.awecal-event-countdown');

        countdowns.forEach(function(countdown) {
            // Skip if already initialized
            if (countdown.dataset.initialized === 'true') {
                return;
            }

            countdown.dataset.initialized = 'true';

            const targetTimestamp = countdown.dataset.targetTimestamp;
            const showDays = countdown.dataset.showDays === '1';
            const showHours = countdown.dataset.showHours === '1';
            const showMinutes = countdown.dataset.showMinutes === '1';
            const showSeconds = countdown.dataset.showSeconds === '1';
            const completedText = countdown.dataset.completedText || 'Event has started!';

            if (!targetTimestamp) {
                return;
            }

            // Parse the ISO-8601 timestamp (includes timezone info from server)
            const targetDate = new Date(targetTimestamp);

            if (isNaN(targetDate.getTime())) {
                console.error('Invalid target timestamp:', targetTimestamp);
                return;
            }

            // Get DOM elements for updating
            const daysEl = countdown.querySelector('[data-unit="days"]');
            const hoursEl = countdown.querySelector('[data-unit="hours"]');
            const minutesEl = countdown.querySelector('[data-unit="minutes"]');
            const secondsEl = countdown.querySelector('[data-unit="seconds"]');
            const timerContainer = countdown.querySelector('.awecal-countdown-timer');

            /**
             * Update the countdown display
             */
            function updateCountdown() {
                const now = new Date();
                const timeDiff = targetDate - now;

                // Check if countdown has completed
                if (timeDiff <= 0) {
                    // Show completed message
                    countdown.classList.add('awecal-countdown-completed');
                    if (timerContainer) {
                        timerContainer.innerHTML = '<span class="awecal-countdown-completed-text">' +
                            escapeHtml(completedText) + '</span>';
                    }

                    // Stop updating
                    if (countdown.dataset.intervalId) {
                        clearInterval(parseInt(countdown.dataset.intervalId));
                        delete countdown.dataset.intervalId;
                    }
                    return;
                }

                // Calculate time units
                const totalSeconds = Math.floor(timeDiff / 1000);
                const totalMinutes = Math.floor(totalSeconds / 60);
                const totalHours = Math.floor(totalMinutes / 60);
                const totalDays = Math.floor(totalHours / 24);

                const days = totalDays;
                const hours = totalHours % 24;
                const minutes = totalMinutes % 60;
                const seconds = totalSeconds % 60;

                // Update display
                if (showDays && daysEl) {
                    daysEl.textContent = padZero(days);
                }
                if (showHours && hoursEl) {
                    hoursEl.textContent = padZero(hours);
                }
                if (showMinutes && minutesEl) {
                    minutesEl.textContent = padZero(minutes);
                }
                if (showSeconds && secondsEl) {
                    secondsEl.textContent = padZero(seconds);
                }
            }

            /**
             * Pad number with leading zero if < 10
             */
            function padZero(num) {
                return num < 10 ? '0' + num : '' + num;
            }

            /**
             * Escape HTML to prevent XSS
             */
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // Initial update
            updateCountdown();

            // Set up interval based on whether seconds are shown
            const updateInterval = showSeconds ? 1000 : 60000; // 1 second or 1 minute
            const intervalId = setInterval(updateCountdown, updateInterval);
            countdown.dataset.intervalId = intervalId;
        });
    }

    /**
     * Clean up intervals when page is unloaded
     */
    function cleanupCountdowns() {
        const countdowns = document.querySelectorAll('.awecal-event-countdown');
        countdowns.forEach(function(countdown) {
            if (countdown.dataset.intervalId) {
                clearInterval(parseInt(countdown.dataset.intervalId));
                delete countdown.dataset.intervalId;
            }
        });
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCountdowns);
    } else {
        initCountdowns();
    }

    // Cleanup on page unload
    window.addEventListener('beforeunload', cleanupCountdowns);

    // Re-initialize if blocks are dynamically added (e.g., via AJAX)
    if (window.MutationObserver) {
        const observer = new MutationObserver(function(mutations) {
            let shouldInit = false;
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) { // Element node
                        if (node.classList && node.classList.contains('awecal-event-countdown')) {
                            shouldInit = true;
                        } else if (node.querySelector && node.querySelector('.awecal-event-countdown')) {
                            shouldInit = true;
                        }
                    }
                });
            });

            if (shouldInit) {
                initCountdowns();
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    // Export for manual initialization if needed
    window.ICOBCountdownInit = initCountdowns;
})();
