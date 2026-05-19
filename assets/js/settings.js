jQuery(function($){
	function openMedia($field){
		const frame = wp.media({
			title: 'انتخاب تصویر',
			button: { text: 'انتخاب' },
			multiple: false
		});

		frame.on('select', function(){
			const att = frame.state().get('selection').first().toJSON();
			$field.find('input[type="hidden"]').val(att.id);
			$field.find('.cptt-mediaField__preview').html('<img src="'+att.url+'" alt="">');
		});

		frame.open();
	}

	$(document).on('click', '.cptt-media-select', function(){
		openMedia($(this).closest('.cptt-mediaField'));
	});

	$(document).on('click', '.cptt-media-remove', function(){
		const $f = $(this).closest('.cptt-mediaField');
		$f.find('input[type="hidden"]').val('');
		$f.find('.cptt-mediaField__preview').html('<div class="cptt-mediaField__empty">آپلود نشده</div>');
	});
});