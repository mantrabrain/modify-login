jQuery(document).ready(function($) {
    // Function to show notifications
    function showNotification(type, message) {
        // Create notification container if it doesn't exist
        if (!$('#modify-login-notifications').length) {
            $('body').append('<div id="modify-login-notifications" class="fixed top-4 right-4 z-50"></div>');
        }
        
        // Generate unique ID for the notification
        const id = 'notification-' + Date.now();
        
        // Create notification element
        const notification = $(`
            <div id="${id}" class="notification p-4 mb-3 rounded-lg shadow-md flex items-center justify-between transform translate-x-full opacity-0 transition-all duration-300" style="min-width: 300px; max-width: 400px;">
                <div class="flex items-center">
                    <span class="notification-icon mr-3"></span>
                    <p class="notification-message text-sm m-0">${message}</p>
                </div>
                <button class="notification-close ml-3 text-gray-500 hover:text-gray-700 focus:outline-none" aria-label="Close">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
        `);
        
        // Set notification type
        if (type === 'success') {
            notification.addClass('bg-green-100 border-l-4 border-green-600 text-green-900');
            notification.find('.notification-icon').addClass('dashicons dashicons-yes-alt text-green-600');
        } else if (type === 'reset') {
            notification.addClass('bg-blue-100 border-l-4 border-blue-600 text-blue-900'); 
            notification.find('.notification-icon').addClass('dashicons dashicons-update text-blue-600');
        } else {
            notification.addClass('bg-red-100 border-l-4 border-red-600 text-red-900');
            notification.find('.notification-icon').addClass('dashicons dashicons-warning text-red-600');
        }
        
        // Add notification to container
        $('#modify-login-notifications').append(notification);
        
        // Show notification with animation
        setTimeout(() => {
            notification.removeClass('translate-x-full opacity-0');
        }, 10);
        
        // Add click handler for close button
        notification.find('.notification-close').on('click', () => {
            closeNotification(id);
        });
        
        // Auto-close after 5 seconds for success, 8 seconds for errors
        setTimeout(() => {
            closeNotification(id);
        }, type === 'success' || type === 'reset' ? 5000 : 8000);
    }
    
    // Function to close notification
    function closeNotification(id) {
        const notification = $(`#${id}`);
        
        // Animate out
        notification.addClass('translate-x-full opacity-0');
        
        // Remove after animation completes
        setTimeout(() => {
            notification.remove();
        }, 300);
    }
    
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
        background_opacity: 1.0,
        logo_url: '',
        logo_width: '84px',
        logo_height: '84px',
        logo_position: 'center',
        form_background: '#ffffff',
        form_border_radius: '4px',
        form_padding: '20px',
        button_color: '#0073aa',
        button_text_color: '#ffffff',
        custom_css: '',
        link_color: '',
        link_hover_color: '',
        label_color: ''
    };
    
    // Initialize each color picker input
    $('.color-picker').each(function() {
        const input = $(this);
        const inputId = input.attr('id');
        const defaultColor = '';
        const currentColor = input.val() || defaultColor;
        const container = $('<div class="gutenberg-color-picker-container"></div>');
        
        // Add special initial class if empty value
        if (!currentColor) {
            //container.addClass('color-empty');
        }
        
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
                
                // Store a reference to the color input
                this.colorInputRef = React.createRef();
            }
            
            componentDidUpdate(prevProps) {
                // Check if initialColor prop changed
                if (prevProps.initialColor !== this.props.initialColor) {
                    this.setState({ color: this.props.initialColor });
                }
            }
            
            clearColor = () => {
                this.setState({ color: '' });
                this.props.onChange('');
                
                // Force update the input field DOM element
                if (this.colorInputRef.current) {
                    this.colorInputRef.current.value = '';
                }
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
                                backgroundColor: this.state.color || 'transparent',
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
                            value: this.state.color || '',
                            ref: this.colorInputRef,
                            onClick: () => this.setState({ isOpen: !this.state.isOpen }),
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
                                fontFamily: 'monospace',
                                cursor: 'pointer'
                            }
                        }),
                        createElement('button', {
                            type: 'button',
                            className: 'color-picker-clear',
                            onClick: this.clearColor,
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
    
    // Initialize opacity slider
    initializeOpacitySlider();
    
    function initializeOpacitySlider() {
        const container = $('.opacity-slider-container');
        const track = $('.opacity-slider-track');
        const fill = $('.opacity-slider-fill');
        const handle = $('.opacity-slider-handle');
        const input = $('#background_opacity');
        const valueDisplay = $('#background_opacity_value');
        
        // Set initial position
        const initialValue = parseFloat(input.val()) || 1.0; // Default to 1.0 (100%) if not set
        updateSliderPosition(initialValue);
        
        // Update the slider UI
        function updateSliderPosition(value) {
            // Ensure value is between 0 and 1
            value = Math.max(0, Math.min(1, value));
            
            // Calculate the position percentage
            const percent = value * 100;
            
            // Update the fill and handle positions
            fill.css('width', `${percent}%`);
            handle.css('left', `${percent}%`);
            handle.css('right', 'auto');
            handle.css('transform', 'translate(-50%, -50%)');
            
            // Update the input value and display
            input.val(value);
            valueDisplay.text(`${Math.round(percent)}%`);
        }
        
        // Handle mouse/touch down on track
        track.on('mousedown touchstart', function(e) {
            e.preventDefault();
            handleSliderInteraction(e);
            
            // Add mouse/touch move and up events
            $(document).on('mousemove touchmove', handleSliderInteraction);
            $(document).on('mouseup touchend', function() {
                $(document).off('mousemove touchmove', handleSliderInteraction);
                $(document).off('mouseup touchend');
            });
        });
        
        // Handle mouse/touch down on handle
        handle.on('mousedown touchstart', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Add mouse/touch move and up events
            $(document).on('mousemove touchmove', handleSliderInteraction);
            $(document).on('mouseup touchend', function() {
                $(document).off('mousemove touchmove', handleSliderInteraction);
                $(document).off('mouseup touchend');
            });
        });
        
        // Process mouse/touch interaction
        function handleSliderInteraction(e) {
            // Get mouse/touch position
            let clientX;
            if (e.type === 'touchmove' || e.type === 'touchstart') {
                clientX = e.originalEvent.touches[0].clientX;
            } else {
                clientX = e.clientX;
            }
            
            // Get track dimensions and position
            const trackRect = track[0].getBoundingClientRect();
            
            // Calculate value based on position
            let value = (clientX - trackRect.left) / trackRect.width;
            
            // Constrain value between 0 and 1
            value = Math.max(0, Math.min(1, value));
            
            // Update slider and trigger change
            updateSliderPosition(value);
            updatePreview();
        }
        
        // Keyboard accessibility
        handle.on('keydown', function(e) {
            let value = parseFloat(input.val());
            
            switch (e.key) {
                case 'ArrowRight':
                case 'ArrowUp':
                    value = Math.min(1, value + 0.01);
                    break;
                case 'ArrowLeft':
                case 'ArrowDown':
                    value = Math.max(0, value - 0.01);
                    break;
                case 'Home':
                    value = 0;
                    break;
                case 'End':
                    value = 1;
                    break;
                default:
                    return;
            }
            
            e.preventDefault();
            updateSliderPosition(value);
            updatePreview();
        });
    }
    
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
        
        // Collect all form values for settings object
        $('.form-group input, .form-group textarea, .form-group select').each(function() {
            const input = $(this);
            const name = input.attr('name');
            const value = input.val();
            
            // Include all fields, even if empty
            settings[name] = value;
            
            // Debug logo fields
            if (name === 'logo_url' || name === 'logo_width' || name === 'logo_height') {
                console.log(`Sending ${name} with value: ${value}`);
            }
        });

        // Apply settings to preview iframe
        const previewDoc = preview[0].contentDocument || preview[0].contentWindow.document;
        const style = previewDoc.createElement('style');
        style.id = 'modify-login-custom-css';
        const customCss = previewDoc.getElementById('modify-login-custom-css');
        
        // Generate CSS based on current settings
        let css = `
            body.login {
                ${settings.background_color ? `background-color: ${settings.background_color} !important;` : ''}
                ${settings.background_image ? `position: relative !important;` : ''}
            }
            
            ${settings.background_image ? `
                body.login::before {
                    content: "" !important;
                    position: absolute !important;
                    top: 0 !important;
                    left: 0 !important;
                    width: 100% !important;
                    height: 100% !important;
                    background-image: url('${settings.background_image}') !important;
                    ${settings.background_size ? `background-size: ${settings.background_size} !important;` : ''}
                    ${settings.background_position ? `background-position: ${settings.background_position} !important;` : ''}
                    ${settings.background_repeat ? `background-repeat: ${settings.background_repeat} !important;` : ''}
                    opacity: ${settings.background_opacity} !important;
                    z-index: -1 !important;
                }
            ` : ''}
            .login form {
                ${settings.form_background ? `background: ${settings.form_background} !important;` : ''}
                ${settings.form_border_radius ? `border-radius: ${settings.form_border_radius} !important;` : ''}
                ${settings.form_padding ? `padding: ${settings.form_padding} !important;` : ''}
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.13) !important;
            }
            .wp-core-ui .button-primary {
                ${settings.button_color ? `background: ${settings.button_color} !important; border-color: ${settings.button_color} !important;` : ''}
                ${settings.button_text_color ? `color: ${settings.button_text_color} !important;` : ''}
                text-decoration: none !important;
                text-shadow: none !important;
            }
            
            /* Link Colors */
            ${settings.link_color ? `.login a, .login #nav a, .login #backtoblog a {
                color: ${settings.link_color} !important;
            }` : ''}
            
            /* Link Hover Colors */
            ${settings.link_hover_color ? `.login a:hover, .login #nav a:hover, .login #backtoblog a:hover {
                color: ${settings.link_hover_color} !important;
            }` : ''}
            
            /* Form Label Colors */
            ${settings.label_color ? `.login form label {
                color: ${settings.label_color} !important;
            }` : ''}
        `;

        
        // Add logo styles if logo is set
        if (settings.logo_url) {
            css += `
                .login h1 a {
                    background-image: url('${settings.logo_url}') !important;
                    ${settings.logo_width ? `width: ${settings.logo_width} !important;` : ''}
                    ${settings.logo_height ? `height: ${settings.logo_height} !important;` : ''}
                    background-size: contain !important;
                    background-position: center !important;
                    background-repeat: no-repeat !important;
                    text-indent: -9999px !important;
                    ${settings.logo_position ? `text-align: ${settings.logo_position} !important;` : ''}
                    margin: 0 auto 25px auto !important;
                }
            `;
        }
        
        // Add user's custom CSS
        if (settings.custom_css) {
            css += settings.custom_css;
        }
        
        style.textContent = css;

        // Check if customCss exists, if not create and append it
        if (customCss) {
            // Update existing style element
            customCss.innerHTML = css;
        } else {
            // If it doesn't exist yet, append the new style element
            try {
                previewDoc.head.appendChild(style);
                console.log('Added new style element to preview');
            } catch (e) {
                console.error('Error updating preview styles:', e);
            }
        }
    }

    // Function to adjust color brightness (similar to the PHP version)
    function adjustBrightness(hex, steps) {
        // Remove # if present
        hex = hex.replace('#', '');
        
        // Parse the hex color
        const r = parseInt(hex.substr(0, 2), 16);
        const g = parseInt(hex.substr(2, 2), 16);
        const b = parseInt(hex.substr(4, 2), 16);
        
        // Adjust brightness
        const adjustR = Math.max(0, Math.min(255, r + steps));
        const adjustG = Math.max(0, Math.min(255, g + steps));
        const adjustB = Math.max(0, Math.min(255, b + steps));
        
        // Convert back to hex
        const rHex = adjustR.toString(16).padStart(2, '0');
        const gHex = adjustG.toString(16).padStart(2, '0');
        const bHex = adjustB.toString(16).padStart(2, '0');
        
        return `#${rHex}${gHex}${bHex}`;
    }

    // Handle save button
    $('.save-button').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const formData = new FormData();

        // Add action parameter for saving
        formData.append('action', 'modify_login_save_builder_settings');
        formData.append('nonce', modifyLoginBuilder.nonce);

        // Collect all form values for AJAX
        $('.form-group input, .form-group textarea, .form-group select').each(function() {
            const input = $(this);
            const name = input.attr('name');
            const value = input.val();
            
            // Include all fields, even if empty
            formData.append(name, value);
            
            // Debug logo fields
            if (name === 'logo_url' || name === 'logo_width' || name === 'logo_height') {
                console.log(`Sending ${name} with value: ${value}`);
            }
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
                    
                    // Show success notification
                    showNotification('success', 'Settings saved successfully.');
                } else {
                    button.text('Error Saving');
                    setTimeout(function() {
                        button.text('Save Settings');
                    }, 2000);
                    
                    // Show error notification
                    showNotification('error', response.data || 'Error saving settings. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                button.text('Error Saving');
                setTimeout(function() {
                    button.text('Save Settings');
                }, 2000);
                
                // Show detailed error notification
                showNotification('error', 'Server error: ' + (xhr.responseText || error));
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
                const originalText = button.text();
                button.addClass('opacity-50').prop('disabled', true).text('Resetting...');
                
                // Reset all form fields without saving to database
                // Clear text inputs
                $('.form-group input[type="text"]').val('').trigger('change');
                
                // Reset select elements to defaults
                $('.form-group select').each(function() {
                    const select = $(this);
                    const defaultVal = select.find('option:first').val();
                    select.val(defaultVal).trigger('change');
                });
                
                // Clear textareas
                $('.form-group textarea').val('').trigger('change');
                
                // Clear hidden inputs
                $('.form-group input[type="hidden"]').val('').trigger('change');
                
                // Handle color pickers
                $('.gutenberg-color-picker-container').each(function() {
                    const container = $(this);
                    const hiddenInput = container.next('input[type="hidden"]');
                    
                    // Clear value completely - no default colors
                    hiddenInput.val('').trigger('change');
                    
                    // Update color picker button to be transparent
                    container.find('.color-picker-button').css('background-color', 'transparent');
                    
                    // Clear text input
                    const inputField = container.find('.color-picker-input');
                    if (inputField.length) {
                        inputField.val('');
                    }
                    
                    // Try to use the existing clear button functionality
                    const clearButton = container.find('.color-picker-clear');
                    if (clearButton.length) {
                        clearButton.trigger('click');
                    }
                });
                
                // Handle image fields
                $('.media-dropzone').each(function() {
                    const dropzone = $(this);
                    const input = dropzone.find('input[type="hidden"]');
                    const imagePreview = dropzone.find('.image-preview');
                    const dropzoneArea = dropzone.find('.dropzone-area');
                    
                    // Clear value
                    input.val('').trigger('change');
                    
                    // Hide preview, show dropzone
                    imagePreview.addClass('hidden');
                    dropzoneArea.removeClass('hidden');
                });
                
                // Clear logo dimensions specifically
                $('#logo_width, #logo_height').val('').trigger('change');
                
                // Clear any custom CSS
                $('#custom_css').val('').trigger('change');
                
                // Reset opacity slider to 100%
                const opacityTrack = $('.opacity-slider-track');
                const opacityFill = $('.opacity-slider-fill');
                const opacityHandle = $('.opacity-slider-handle');
                const opacityValueDisplay = $('#background_opacity_value');
                const opacityInput = $('#background_opacity');

                // Set opacity to 100%
                opacityFill.css('width', '100%');
                opacityHandle.css('left', '100%');
                opacityHandle.css('transform', 'translate(-50%, -50%)');
                opacityValueDisplay.text('100%');
                opacityInput.val(1.0).trigger('change');
                
                // Update preview
                updatePreview();
                
                // Reset button state
                setTimeout(function() {
                    button.removeClass('opacity-50').prop('disabled', false).text(originalText);
                    
                    // Show notification
                    showNotification('reset', 'Form has been reset. Click Save if you want to apply these changes.');
                }, 500);
            }, 300);
        });
    });
    
    // Initialize preview
    updatePreview();

    // Reset button in modal
    $('#builder-reset-confirm').on('click', function() {
        // Nothing here - redundant
    });
});