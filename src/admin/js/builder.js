jQuery(document).ready(function($) {
    // Set up WordPress Gutenberg Color Picker
    const { Component, render, createElement } = wp.element;
    const { ColorPicker, BaseControl } = wp.components;
    
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
                    createElement('div', { className: 'color-picker-main flex items-center' },
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
                        createElement('input', {
                            type: 'text',
                            className: 'color-picker-input',
                            value: this.state.color,
                            onChange: (e) => {
                                const newColor = e.target.value;
                                this.setState({ color: newColor });
                                this.props.onChange(newColor);
                            },
                            style: {
                                marginLeft: '8px',
                                width: '80px',
                                height: '32px',
                                padding: '0 8px',
                                borderRadius: '4px',
                                border: '1px solid #ccc',
                                fontFamily: 'monospace'
                            }
                        }),
                        createElement('button', {
                            type: 'button',
                            className: 'color-picker-clear',
                            onClick: () => {
                                this.setState({ color: '' });
                                this.props.onChange('');
                            },
                            style: {
                                marginLeft: '4px',
                                padding: '0',
                                width: '28px',
                                height: '32px',
                                borderRadius: '4px',
                                border: '1px solid #ccc',
                                cursor: 'pointer',
                                background: '#f0f0f1',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center'
                            }
                        }, createElement('span', {
                            className: 'dashicons dashicons-no-alt',
                            style: {
                                fontSize: '16px',
                                width: '16px',
                                height: '16px'
                            }
                        }))
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
                ${settings.background_color ? `background-color: ${settings.background_color};` : ''}
                ${settings.background_image ? `
                    background-image: url('${settings.background_image}');
                    background-size: ${settings.background_size || 'cover'};
                    background-position: ${settings.background_position || 'center center'};
                    background-repeat: ${settings.background_repeat || 'no-repeat'};
                ` : 'background-image: none;'}
            }
            #login {
                ${settings.form_background ? `background: ${settings.form_background};` : ''}
                ${settings.form_border_radius ? `border-radius: ${settings.form_border_radius};` : ''}
                ${settings.form_padding ? `padding: ${settings.form_padding};` : ''}
            }
            .wp-core-ui .button-primary {
                ${settings.button_color ? `background: ${settings.button_color}; border-color: ${settings.button_color};` : ''}
                ${settings.button_text_color ? `color: ${settings.button_text_color};` : ''}
            }
        `;
        
        // Add logo styles if logo is set
        if (settings.logo_url) {
            css += `
                .login h1 a {
                    background-image: url('${settings.logo_url}');
                    ${settings.logo_width ? `width: ${settings.logo_width};` : ''}
                    ${settings.logo_height ? `height: ${settings.logo_height};` : ''}
                    background-size: contain;
                    background-position: center;
                    background-repeat: no-repeat;
                    text-indent: -9999px;
                    ${settings.logo_position ? `text-align: ${settings.logo_position};` : ''}
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
    $('#reset-all-button').on('click', function(e) {
        e.preventDefault();
        
        // Create custom confirmation modal
        const modalOverlay = $('<div class="custom-modal-overlay"></div>');
        const modal = $(`
            <div class="custom-modal bg-white rounded-lg shadow-lg w-96">
                <div class="custom-modal-header px-5 py-4 border-b border-gray-200 flex items-center">
                    <span class="dashicons dashicons-info text-blue-500 mr-2 text-xl"></span>
                    <h3 class="custom-modal-title text-lg font-semibold text-gray-900">Reset Form</h3>
                </div>
                <div class="custom-modal-content p-5 text-gray-600">
                    <p class="mb-2">You are about to reset all form fields to their default values.</p>
                    <p class="mb-2">This will only reset the form, not save the changes.</p>
                    <p>Click Save after resetting if you want to apply these changes.</p>
                </div>
                <div class="custom-modal-actions p-4 bg-gray-50 flex justify-end gap-3 rounded-b-lg">
                    <button class="custom-modal-cancel px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">Cancel</button>
                    <button class="custom-modal-confirm px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600">Reset Form</button>
                </div>
            </div>
        `);
        
        modalOverlay.append(modal);
        $('body').append(modalOverlay);
        
        // Fade in animation
        setTimeout(function() {
            modalOverlay.addClass('active');
        }, 10);
        
        // Handle cancel button
        modal.find('.custom-modal-cancel').on('click', function() {
            // Close modal with animation
            modalOverlay.removeClass('active');
            setTimeout(function() {
                modalOverlay.remove();
            }, 300);
        });
        
        // Handle confirm button
        modal.find('.custom-modal-confirm').on('click', function() {
            // Close modal with animation
            modalOverlay.removeClass('active');
            setTimeout(function() {
                modalOverlay.remove();
                
                // Get the reset button
                const button = $('#reset-all-button');
                
                // Visual feedback
                button.addClass('opacity-50');
            
                // Reset form fields in the UI
                // Reset all inputs to empty values
                $('input#background_color').val('').trigger('change');
                $('input#form_background').val('').trigger('change');
                $('input#button_color').val('').trigger('change');
                $('input#button_text_color').val('').trigger('change');
                
                // Reset image fields
                $('input#background_image').val('').trigger('change');
                $('input#logo_url').val('').trigger('change');
                
                // Reset select fields - also empty these
                $('select#background_size').val('').trigger('change');
                $('select#background_position').val('').trigger('change');
                $('select#background_repeat').val('').trigger('change');
                $('select#logo_position').val('').trigger('change');
                
                // Reset input text fields - also empty these
                $('input#form_border_radius').val('').trigger('change');
                $('input#form_padding').val('').trigger('change');
                $('input#logo_width').val('').trigger('change');
                $('input#logo_height').val('').trigger('change');
                
                // Reset textarea
                $('textarea#custom_css').val('').trigger('change');
                
                // Hide all image previews and show dropzones
                $('.image-preview').addClass('hidden');
                $('.dropzone-area').removeClass('hidden');
                
                // Force refresh color pickers UI by manually updating them
                $('.gutenberg-color-picker-container').each(function(){
                    const container = $(this);
                    const hiddenInput = container.prev('input[type="hidden"]');
                    
                    // Update color button and text display
                    container.find('.color-picker-button').css('background-color', 'transparent');
                    container.find('.color-picker-input').val('');
                });
                
                // Update preview
                updatePreview();
                
                // Show success notification
                showNotification('success', 'Form has been reset to default values. Click Save to apply changes.');
                
                // Reset button state
                button.removeClass('opacity-50');
            }, 300);
        });
    });
    
    // Initialize preview
    updatePreview();
});