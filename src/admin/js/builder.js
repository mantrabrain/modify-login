jQuery(document).ready(function($) {
    // Initialize color pickers
    $('input[type="color"]').wpColorPicker();

    // Handle image uploads
    $('.upload-button').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const input = button.prev('input[type="text"]');
        const target = input.attr('id');

        const frame = wp.media({
            title: 'Select Image',
            button: {
                text: 'Use this image'
            },
            multiple: false
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            input.val(attachment.url);
            updatePreview();
        });

        frame.open();
    });

    // Handle remove image buttons
    $('.remove-button').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const input = button.prev().prev('input[type="text"]');
        input.val('');
        updatePreview();
    });

    // Handle popover toggles
    $('.toggle-image-properties').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const targetId = button.data('target');
        const popover = $(`#${targetId}`);
        
        // Close any other open popovers
        $('.image-properties.active').not(popover).removeClass('active');
        
        // Toggle current popover
        popover.toggleClass('active');
        
        // Position the popover
        if (popover.hasClass('active')) {
            const buttonRect = button[0].getBoundingClientRect();
            popover.css({
                top: buttonRect.bottom + window.scrollY + 5,
                left: buttonRect.left + window.scrollX - popover.width() + buttonRect.width
            });
        }
    });
    
    // Close popovers when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.image-properties, .toggle-image-properties').length) {
            $('.image-properties.active').removeClass('active');
        }
    });
    
    // Close popovers when clicking close button
    $('.close-popover').on('click', function(e) {
        e.preventDefault();
        $(this).closest('.image-properties').removeClass('active');
    });

    // Handle form changes
    $('.form-group input, .form-group textarea, .form-group select').on('change input', function() {
        updatePreview();
    });

    // Update preview iframe
    function updatePreview() {
        const preview = $('#login-preview');
        const settings = {};
        
        // Collect all form values
        $('.form-group input, .form-group textarea, .form-group select').each(function() {
            settings[$(this).attr('name')] = $(this).val();
        });

        // Apply settings to preview iframe
        const previewDoc = preview[0].contentDocument || preview[0].contentWindow.document;
        const style = previewDoc.createElement('style');
        
        // Generate CSS based on current settings
        let css = `
            body.login {
                background-color: ${settings.background_color || '#ffffff'};
                ${settings.background_image ? `
                    background-image: url('${settings.background_image}');
                    background-size: ${settings.background_size || 'cover'};
                    background-position: ${settings.background_position || 'center center'};
                    background-repeat: ${settings.background_repeat || 'no-repeat'};
                ` : 'background-image: none;'}
            }
            #login {
                background: ${settings.form_background || '#ffffff'};
                border-radius: ${settings.form_border_radius || '4px'};
                padding: ${settings.form_padding || '20px'};
            }
            .wp-core-ui .button-primary {
                background: ${settings.button_color || '#0073aa'};
                border-color: ${settings.button_color || '#0073aa'};
                color: ${settings.button_text_color || '#ffffff'};
            }
        `;
        
        // Add logo styles if logo is set
        if (settings.logo_url) {
            css += `
                .login h1 a {
                    background-image: url('${settings.logo_url}');
                    width: ${settings.logo_width || '84px'};
                    height: ${settings.logo_height || '84px'};
                    background-size: contain;
                    background-position: center;
                    background-repeat: no-repeat;
                    text-indent: -9999px;
                    text-align: ${settings.logo_position || 'center'};
                }
            `;
        } else {
            css += `
                .login h1 a {
                    background-image: none;
                    width: auto;
                    height: auto;
                    text-indent: 0;
                }
            `;
        }
        
        // Add custom CSS
        if (settings.custom_css) {
            css += settings.custom_css;
        }
        
        style.textContent = css;
        previewDoc.head.appendChild(style);
    }

    // Handle save button
    $('.save-button').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const formData = new FormData();

        // Add action parameter for saving
        formData.append('action', 'modify_login_save_builder_settings');
        formData.append('nonce', modifyLoginBuilder.nonce);

        // Collect all form values
        $('.form-group input, .form-group textarea, .form-group select').each(function() {
            formData.append($(this).attr('name'), $(this).val());
        });

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
    
    // Initialize preview
    updatePreview();
}); 