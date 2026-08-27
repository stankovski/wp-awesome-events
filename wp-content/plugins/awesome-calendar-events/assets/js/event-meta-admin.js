(function () {
	function init() {
		var recSel = document.getElementById('icob_event_recurrence_type');
		var weekdays = document.getElementById('icob_event_weekdays');
		var endSel = document.getElementById('icob_event_recurrence_end_type');
		var endDateWrap = document.getElementById('icob_event_end_date_wrap');
		var countWrap = document.getElementById('icob_event_count_wrap');
		var enabledCb = document.getElementById('icob_event_date_enabled');
		var fieldsWrap = document.getElementById('icob_event_fields_wrap');

		if (!recSel || !weekdays || !endSel || !endDateWrap || !countWrap || !enabledCb || !fieldsWrap) {
			return;
		}

		function update() {
			weekdays.style.display = (recSel.value === 'weekly') ? '' : 'none';
			endDateWrap.style.display = (endSel.value === 'date') ? '' : 'none';
			countWrap.style.display = (endSel.value === 'count') ? '' : 'none';
			if (enabledCb.checked) {
				fieldsWrap.style.opacity = '1';
				fieldsWrap.style.pointerEvents = 'auto';
			} else {
				fieldsWrap.style.opacity = '.55';
				fieldsWrap.style.pointerEvents = 'none';
			}
		}

		recSel.addEventListener('change', update);
		endSel.addEventListener('change', update);
		enabledCb.addEventListener('change', update);
		update();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
