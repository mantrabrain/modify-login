jQuery(document).ready(function($) {
    // Set up WordPress Gutenberg Color Picker
    const { Component, render, createElement } = wp.element;
    const { ColorPicker, BaseControl } = wp.components;
    
    // Debugging for reset button
    console.log('Document ready - looking for reset button');
    const resetButton = $('.reset-button');
    console.log('Reset button found:', resetButton.length > 0);
    
    // Default settings for reset
    const defaultSettings = {
        background_color: '#ffffff',
        background_image: '',
        background_size: 'cover',
        background_position: 'center center',
        background_repeat: 'no-repeat',
        logo_url: '',
        logo_width: '84px',
        logo_height: '84px',
        logo_position: 'center',
        form_background: '#ffffff',
        form_border_radius: '4px',
        form_padding: '20px',
        button_color: '#0073aa',
        button_text_color: '#ffffff',
        custom_css: ''
    };
    
    // Initialize each color picker input
    $('.color-picker').each(function() {
        const input = $(this);
        const inputId = input.attr('id');
        const defaultColor = input.data('default-color') || '#ffffff';
        const currentColor = input.val() || defaultColor;
        const container = $('<div class="gutenberg-color-picker-container"></div>');
        
        // Replace the input with a container
        input.after(container);
        
        // Create a hidden input to store the value
        const hiddenInput = $(`<input type="hidden" id="${inputId}" name="${inputId}" value="${currentColor}">`);
        input.replaceWith(hiddenInput);
        
        // Create color picker component
        class ColorPickerComponent extends Component {
            constructor(props) {
                super(props);
                this.state = {
                    color: props.initialColor,
                    isOpen: false
                };
            }
            
            render() {
                return createElement(
                    BaseControl,
                    { id: this.props.inputId },
                    createElement('div', { className: 'color-picker-main' },
                        createElement('button', {
                            type: 'button',
                            className: 'color-picker-button',
                            onClick: () => this.setState({ isOpen: !this.state.isOpen }),
                            style: {
                                backgroundColor: this.state.color,
                                width: '32px',
                                height: '32px',
                                borderRadius: '4px',
                                border: '1px solid #ccc',
                                cursor: 'pointer',
                                boxShadow: '0 1px 0 #ccc'
                            }
                        }),
                        createElement('span', {
                            className: 'color-picker-value',
                            style: {
                                marginLeft: '8px',
                                lineHeight: '32px',
                                verticalAlign: 'top'
                            }
                        }, this.state.color)
                    ),
                    this.state.isOpen && createElement(
                        'div',
                        { className: 'color-picker-popover' },
                        createElement(
                            ColorPicker,
                            {
                                color: this.state.color,
                                onChangeComplete: (colorObject) => {
                                    const newColor = colorObject.hex;
                                    this.setState({ color: newColor });
                                    this.props.onChange(newColor);
                                }
                            }
                        )
                    )
                );
            }
        }
        
        // Render the component
        render(
            createElement(ColorPickerComponent, {
                inputId: inputId,
                initialColor: currentColor,
                onChange: (newColor) => {
                    hiddenInput.val(newColor).trigger('change');
                    updatePreview();
                }
            }),
            container[0]
        );
    });

    // Initialize media dropzones
    $('.media-dropzone').each(function() {
        const dropzone = $(this);
        const dropzoneArea = dropzone.find('.dropzone-area');
        const input = dropzone.find('input[type="hidden"]');
        const imagePreview = dropzone.find('.image-preview');
        const previewImg = imagePreview.find('img');
        const targetField = dropzone.data('target');
        
        // Show existing image preview if available
        if (input.val()) {
            previewImg.attr('src', input.val());
            imagePreview.removeClass('hidden');
            dropzoneArea.addClass('hidden');
            
            // Try to get the filename from the URL
            const url = new URL(input.val());
            const filename = url.pathname.split('/').pop();
            imagePreview.find('.filename').text(filename);
            
            // Create a temporary image to get dimensions
            const tempImg = new Image();
            tempImg.onload = function() {
                imagePreview.find('.dimensions').text(`${this.width} × ${this.height}`);
            };
            tempImg.src = input.val();
        }
        
        // Setup click handler on dropzone area to open media library
        dropzoneArea.on('click', function(e) {
            e.preventDefault();
            openMediaLibrary(input, imagePreview, dropzoneArea);
        });
        
        // Handle drag and drop events
        dropzoneArea.on('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('dragover');
        });
        
        dropzoneArea.on('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');
        });
        
        dropzoneArea.on('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');
            
            // Open the media library instead of direct handling
            // WordPress media library can handle the dropped files
            openMediaLibrary(input, imagePreview, dropzoneArea);
        });
        
        // Handle remove image button
        dropzone.find('.remove-image-button').on('click', function(e) {
            e.preventDefault();
            input.val('').trigger('change');
            imagePreview.addClass('hidden');
            dropzoneArea.removeClass('hidden');
            updatePreview();
        });
    });
    
    // Handle all image property toggle buttons - Keep popovers within their parent containers
    $('.toggle-image-properties').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const button = $(this);
        const targetId = button.data('target');
        const popover = $(`#${targetId}`);
        
        // Close any other open popovers
        $('.image-properties.active').not(popover).removeClass('active');
        
        // Toggle current popover visibility
        popover.toggleClass('active');
        
        // Make sure popover is in right position (no need to manually position since we use CSS)
        if (popover.hasClass('active')) {
            // Ensure the popover is within the #modify-login-builder container
            if (!popover.closest('#modify-login-builder').length) {
                $('#modify-login-builder').find(`#${targetId}`).addClass('active');
            }
        }
    });
    
    // Function to open the media library
    function openMediaLibrary(input, imagePreview, dropzoneArea) {
        const frame = wp.media({
            title: 'Select Image',
            button: {
                text: 'Use this image'
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            input.val(attachment.url).trigger('change');
            
            // Update preview
            imagePreview.find('img').attr('src', attachment.url);
            imagePreview.find('.filename').text(attachment.filename);
            imagePreview.find('.dimensions').text(`${attachment.width} × ${attachment.height}`);
            
            // Show preview, hide dropzone
            imagePreview.removeClass('hidden');
            dropzoneArea.addClass('hidden');
            
            updatePreview();
        });

        frame.open();
    }
    
    // Close popovers when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.image-properties, .toggle-image-properties, .color-picker-popover, .color-picker-button').length) {
            $('.image-properties.active').removeClass('active');
        }
    });
    
    // Close popovers when clicking close button
    $('.close-popover').on('click', function(e) {
        e.preventDefault();
        $(this).closest('.image-properties').removeClass('active');
    });

    // Handle form changes
    $('.form-group input:not(.color-picker), .form-group textarea, .form-group select').on('change input', function() {
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
    
    // Handle reset button
    $('.reset-button').on('click', function(e) {
        e.preventDefault();
        
        console.log('Reset button clicked');
        
        // Create and show notification
        const notification = $('<div class="ml-notification"></div>')
            .css({
                'position': 'fixed',
                'top': '30px',
                'left': '50%',
                'transform': 'translateX(-50%)',
                'background-color': '#f44336',
                'color': 'white',
                'padding': '15px 25px',
                'border-radius': '4px',
                'z-index': '9999',
                'box-shadow': '0 4px 8px rgba(0,0,0,0.2)',
                'font-weight': 'bold',
                'font-size': '14px',
                'display': 'flex',
                'align-items': 'center',
                'max-width': '90%',
                'opacity': '0',
                'transition': 'opacity 0.3s ease'
            })
            .html('<span style="margin-right:10px;">⚠️</span> WARNING: You are about to reset all login customization settings to their default values!');
        
        $('body').append(notification);
        
        // Fade in the notification
        setTimeout(function() {
            notification.css('opacity', '1');
        }, 10);
        
        // Show confirmation dialog after 1 second
        setTimeout(function() {
            if (confirm('Are you sure you want to reset all settings to their default values? This cannot be undone.')) {
                const button = resetButton;
                
                // Remove notification
                notification.remove();
                
                // Visual feedback
                button.prop('disabled', true).text('Resetting...');
                
                // Send AJAX request to reset settings on the server
                const formData = new FormData();
                formData.append('action', 'modify_login_reset_builder_settings');
                formData.append('nonce', modifyLoginBuilder.nonce);
                
                $.ajax({
                    url: modifyLoginBuilder.ajax_url,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            // Reset form fields in the UI
                            // Reset hidden inputs for color pickers
                            $('input#background_color').val(defaultSettings.background_color).trigger('change');
                            $('input#form_background').val(defaultSettings.form_background).trigger('change');
                            $('input#button_color').val(defaultSettings.button_color).trigger('change');
                            $('input#button_text_color').val(defaultSettings.button_text_color).trigger('change');
                            
                            // Reset image fields
                            $('input#background_image').val('').trigger('change');
                            $('input#logo_url').val('').trigger('change');
                            
                            // Reset select fields
                            $('select#background_size').val(defaultSettings.background_size).trigger('change');
                            $('select#background_position').val(defaultSettings.background_position).trigger('change');
                            $('select#background_repeat').val(defaultSettings.background_repeat).trigger('change');
                            $('select#logo_position').val(defaultSettings.logo_position).trigger('change');
                            
                            // Reset input text fields
                            $('input#form_border_radius').val(defaultSettings.form_border_radius).trigger('change');
                            $('input#form_padding').val(defaultSettings.form_padding).trigger('change');
                            $('input#logo_width').val(defaultSettings.logo_width).trigger('change');
                            $('input#logo_height').val(defaultSettings.logo_height).trigger('change');
                            
                            // Reset textarea
                            $('textarea#custom_css').val(defaultSettings.custom_css).trigger('change');
                            
                            // Hide all image previews and show dropzones
                            $('.image-preview').addClass('hidden');
                            $('.dropzone-area').removeClass('hidden');
                            
                            // Force refresh color pickers UI by manually updating them
                            $('.gutenberg-color-picker-container').each(function(){
                                const container = $(this);
                                const hiddenInput = container.prev('input[type="hidden"]');
                                const id = hiddenInput.attr('id');
                                const defaultColor = defaultSettings[id] || '#ffffff';
                                
                                // Update color button and text display
                                container.find('.color-picker-button').css('background-color', defaultColor);
                                container.find('.color-picker-value').text(defaultColor);
                            });
                            
                            // Update preview
                            updatePreview();
                            
                            // Update visual state
                            button.text('Reset Complete!');
                        } else {
                            button.text('Error Resetting');
                        }
                    },
                    error: function() {
                        button.text('Error Resetting');
                    },
                    complete: function() {
                        setTimeout(function() {
                            button.prop('disabled', false).text('Reset All');
                        }, 1500);
                    }
                });
            } else {
                // Remove notification if user cancels
                notification.remove();
            }
        }, 1000);
    });
    
    // Initialize preview
    updatePreview();
});