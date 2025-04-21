jQuery(document).ready(function($) {
    // Initialize color pickers
    $('.color-picker').wpColorPicker();

    // Handle image uploads
    $('.upload-image, .upload-logo').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var input = button.prev('input[type="text"]');

        var frame = wp.media({
            title: 'Select Image',
            button: {
                text: 'Use this image'
            },
            multiple: false
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            input.val(attachment.url);
            updatePreview();
        });

        frame.open();
    });

    // Handle form changes
    $('.form-group input, .form-group textarea').on('change input', function() {
        updatePreview();
    });

    // Update preview iframe
    function updatePreview() {
        var preview = $('#login-preview');
        var formData = new FormData();
        
        // Collect all form values
        $('.form-group input, .form-group textarea').each(function() {
            formData.append($(this).attr('name'), $(this).val());
        });

        // Send AJAX request to update preview
        $.ajax({
            url: modifyLoginBuilder.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    preview.attr('src', response.data.preview_url);
                }
            }
        });
    }

    // Handle save button
    $('.save-settings').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var formData = new FormData();

        // Collect all form values
        $('.form-group input, .form-group textarea').each(function() {
            formData.append($(this).attr('name'), $(this).val());
        });

        // Add nonce
        formData.append('nonce', modifyLoginBuilder.nonce);

        // Disable button and show loading state
        button.prop('disabled', true).text('Saving...');

        // Send AJAX request to save settings
        $.ajax({
            url: modifyLoginBuilder.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    button.text('Settings Saved!');
                    setTimeout(function() {
                        button.text('Save Settings');
                    }, 2000);
                } else {
                    button.text('Error Saving');
                    setTimeout(function() {
                        button.text('Save Settings');
                    }, 2000);
                }
            },
            error: function() {
                button.text('Error Saving');
                setTimeout(function() {
                    button.text('Save Settings');
                }, 2000);
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
}); 