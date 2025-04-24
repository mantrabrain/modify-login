jQuery(document).ready(function($) {
    'use strict';

    // Initialize color pickers
    $('input[type="color"]').wpColorPicker();

    // Tab switching functionality
    $('.tab-link').on('click', function(e) {
        e.preventDefault();
        var targetTab = $(this).data('tab');
        
        // Update hidden field with current tab
        $('#active_tab').val(targetTab);
        
        // Show the target panel, hide others
        $('.tab-panel').addClass('hidden');
        $('#' + targetTab).removeClass('hidden');
        
        // Update active state on tabs
        $('.tab-link').removeClass('text-gray-900 bg-gray-100').addClass('text-gray-600 hover:text-gray-900 hover:bg-gray-50').removeAttr('aria-current');
        $(this).removeClass('text-gray-600 hover:text-gray-900 hover:bg-gray-50').addClass('text-gray-900 bg-gray-100').attr('aria-current', 'page');
    });
    
    // Auto-hide success message after 5 seconds
    if ($('#success-message').length) {
        setTimeout(function() {
            $('#success-message').fadeOut('slow');
        }, 5000);
    }

    // Initialize WordPress Media Uploader
    let mediaUploader;
    $('.upload-media-button').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const inputId = button.data('input');
        const input = $(`#${inputId}`);
        const preview = $(`#${inputId}_preview`);

        // If the media uploader already exists, open it
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        // Create the media uploader
        mediaUploader = wp.media({
            title: 'Choose Image',
            button: {
                text: 'Use this image'
            },
            multiple: false
        });

        // When an image is selected, run a callback
        mediaUploader.on('select', function() {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            input.val(attachment.url);
            preview.attr('src', attachment.url).removeClass('hidden');
        });

        // Open the uploader dialog
        mediaUploader.open();
    });

    // Show image previews on page load if URLs exist
    $('.upload-media-button').each(function() {
        const inputId = $(this).data('input');
        const input = $(`#${inputId}`);
        const preview = $(`#${inputId}_preview`);
        
        if (input.val()) {
            preview.removeClass('hidden');
        }
    });

    // Toggle sections based on checkbox state
    $('.toggle-section').each(function() {
        var $checkbox = $(this);
        var $section = $($checkbox.data('section'));

        function toggleSection() {
            if ($checkbox.is(':checked')) {
                $section.slideDown();
            } else {
                $section.slideUp();
            }
        }

        // Initial state
        toggleSection();

        // On change
        $checkbox.on('change', toggleSection);
    });

    // Form validation
    $('form').on('submit', function(e) {
        var $form = $(this);
        var isValid = true;

        // Validate required fields
        $form.find('[required]').each(function() {
            var $field = $(this);
            if (!$field.val()) {
                isValid = false;
                $field.addClass('error');
            } else {
                $field.removeClass('error');
            }
        });

        if (!isValid) {
            e.preventDefault();
            alert('Please fill in all required fields.');
        }
    });

    // AJAX form submission
    $('.ajax-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $submitButton = $form.find('button[type="submit"]');
        var originalText = $submitButton.text();

        // Disable submit button and show loading state
        $submitButton.prop('disabled', true).text('Saving...');

        $.ajax({
            url: modify_login_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'modify_login_save_settings',
                nonce: modify_login_admin.nonce,
                data: $form.serialize()
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    $form.prepend('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                } else {
                    // Show error message
                    $form.prepend('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                // Show error message
                $form.prepend('<div class="notice notice-error"><p>An error occurred while saving the settings.</p></div>');
            },
            complete: function() {
                // Re-enable submit button and restore original text
                $submitButton.prop('disabled', false).text(originalText);

                // Remove notices after 5 seconds
                setTimeout(function() {
                    $form.find('.notice').fadeOut(function() {
                        $(this).remove();
                    });
                }, 5000);
            }
        });
    });

    // Preview functionality
    $('.preview-button').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $preview = $($button.data('preview'));
        
        $preview.slideToggle();
    });
}); 