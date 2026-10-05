(function ($) {
	'use strict';

	$(function () {
		var $response = $('input[name="block_response"]');
		var $messageRow = $('#codecaste-country-access-control-message').closest('tr');
		var $redirectRow = $('#codecaste-country-access-control-redirect').closest('tr');

		function toggleResponseFields() {
			var value = $response.filter(':checked').val();
			$messageRow.toggle(value === 'message');
			$redirectRow.toggle(value === 'redirect');
		}

		$response.on('change', toggleResponseFields);
		toggleResponseFields();

		// Country search filter
		var $searchInput = $('#codecaste-country-access-control-country-search');
		var $select = $('#codecaste-country-access-control-countries');

		if ($searchInput.length && $select.length) {
			$searchInput.on('input keyup', function () {
				var term = $.trim($(this).val()).toLowerCase();
				$select.find('option').each(function () {
					var text = $(this).text().toLowerCase();
					if (!term || text.indexOf(term) !== -1) {
						$(this).show().prop('disabled', false);
					} else {
						$(this).hide().prop('disabled', false);
					}
				});
			});
		}
	});
}(jQuery));
