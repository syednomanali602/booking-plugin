jQuery(function ($) {
    // Color pickers
    $('.ccb-color').wpColorPicker();

    // Reset all colors (inherit-type colors are cleared, others get their default)
    $('#ccb-reset-colors').on('click', function () {
        if (!window.confirm('Reset all colors to their default values? You still need to click "Save Style".')) return;
        $('.ccb-color').each(function () {
            var $input = $(this);
            var def = $input.data('default-color');
            if (def) {
                $input.wpColorPicker('color', def);
            } else {
                $input.closest('.wp-picker-container').find('.wp-picker-clear').trigger('click');
                $input.val('');
            }
        });
    });

    // Logo uploader
    var frame;
    var $id = $('#ccb-logo-id');
    var $preview = $('.ccb-logo-preview');
    var $remove = $('.ccb-logo-remove');

    $('.ccb-logo-select').on('click', function (event) {
        event.preventDefault();
        if (!frame) {
            frame = wp.media({
                title: 'Select brand logo',
                button: { text: 'Use this logo' },
                library: { type: 'image' },
                multiple: false
            });
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                var src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                $id.val(attachment.id);
                $preview.empty().append($('<img>').attr('src', src).attr('alt', ''));
                $remove.show();
            });
        }
        frame.open();
    });

    $remove.on('click', function (event) {
        event.preventDefault();
        $id.val('');
        $preview.empty();
        $remove.hide();
    });
});
