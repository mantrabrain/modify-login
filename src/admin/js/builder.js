jQuery(document).ready(function($) {
    // Set up WordPress Gutenberg Color Picker
    const { Component, render, createElement } = wp.element;
    const { ColorPicker, BaseControl } = wp.components;
    
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
    
    // Handle all image property toggle buttons
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
        
        // Position the popover correctly
        if (popover.hasClass('active')) {
            const buttonRect = button[0].getBoundingClientRect();
            const bodyRect = document.body.getBoundingClientRect();
            
            // Adjust position based on available space
            let leftPos = buttonRect.left + window.scrollX - popover.outerWidth() + buttonRect.width;
            if (leftPos < 0) {
                leftPos = buttonRect.left + window.scrollX;
            }
            
            // Set the popover position
            popover.css({
                top: buttonRect.bottom + window.scrollY + 5,
                left: leftPos,
                zIndex: 100
            });
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
    
    // Initialize preview
    updatePreview();
});