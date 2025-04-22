<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div id="modify-login-builder" class="h-[calc(100vh-32px)] bg-gray-50 p-5">
    <div class="flex h-full gap-6">
        <!-- Left Panel -->
        <div class="w-96 min-w-96 bg-white shadow-md flex flex-col">
            <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between bg-white">
                <h2 class="text-lg font-semibold text-gray-900 m-0"><?php _e('Login Page Builder', 'modify-login'); ?></h2>
                <button class="save-button flex items-center gap-2 px-4 py-2 bg-emerald-500 text-white border-none rounded-md cursor-pointer text-sm font-medium transition-all hover:bg-emerald-600 hover:-translate-y-0.5 active:translate-y-0">
                    <span class="dashicons dashicons-saved text-base w-4 h-4"></span>
                    <?php _e('Save Changes', 'modify-login'); ?>
                </button>
            </div>
            
            <div class="panel-content flex-1 overflow-y-auto p-5">
                <!-- Background Card -->
                <div class="card bg-white border border-gray-200 rounded-lg mb-5 transition-shadow hover:shadow">
                    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center gap-2.5">
                        <span class="dashicons dashicons-format-image text-gray-500 text-lg w-5 h-5"></span>
                        <h3 class="text-sm font-semibold text-gray-800 m-0"><?php _e('Background', 'modify-login'); ?></h3>
                    </div>
                    <div class="p-4">
                        <div class="form-group mb-4 last:mb-0">
                            <label for="background_color" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Background Color', 'modify-login'); ?></label>
                            <input type="text" id="background_color" name="background_color" value="<?php echo esc_attr($settings['background_color']); ?>" class="color-picker" data-default-color="#ffffff">
                        </div>
                        <div class="form-group mb-4 last:mb-0">
                            <label for="background_image" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Background Image', 'modify-login'); ?></label>
                            <div class="media-dropzone" data-target="background_image">
                                <input type="hidden" id="background_image" name="background_image" value="<?php echo esc_attr($settings['background_image']); ?>">
                                
                                <div class="dropzone-area flex flex-col items-center justify-center border-2 border-dashed border-gray-300 bg-gray-50 rounded-lg p-6 transition-all hover:bg-gray-100 cursor-pointer">
                                    <div class="dropzone-icon mb-3 text-gray-400">
                                        <span class="dashicons dashicons-upload text-3xl"></span>
                                    </div>
                                    
                                    <div class="dropzone-content text-center">
                                        <p class="text-sm font-medium text-gray-700 mb-1"><?php _e('Drag & drop an image here', 'modify-login'); ?></p>
                                        <p class="text-xs text-gray-500"><?php _e('or click to browse', 'modify-login'); ?></p>
                                    </div>
                                </div>
                                
                                <div class="image-preview mt-3 hidden">
                                    <div class="flex items-center gap-3">
                                        <div class="preview-thumbnail w-16 h-16 bg-gray-100 rounded-md border border-gray-200">
                                            <img src="" class="w-full h-full object-cover" alt="<?php _e('Preview', 'modify-login'); ?>">
                                        </div>
                                        <div class="preview-info flex-1">
                                            <p class="filename text-sm font-medium text-gray-700 truncate"></p>
                                            <p class="dimensions text-xs text-gray-500"></p>
                                        </div>
                                        <div class="preview-actions flex gap-2">
                                            <button type="button" class="remove-image-button p-1.5 text-red-500 hover:bg-red-50 rounded-md transition-colors">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                            <button type="button" class="toggle-image-properties p-1.5 text-gray-500 hover:bg-gray-50 rounded-md transition-colors" data-target="background-properties">
                                                <span class="dashicons dashicons-admin-generic"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="background-properties" class="image-properties">
                                <div class="popover-header">
                                    <h4><?php _e('Background Image Properties', 'modify-login'); ?></h4>
                                    <span class="close-popover dashicons dashicons-no-alt"></span>
                                </div>
                                <div class="popover-content">
                                    <div class="form-group mb-4 last:mb-0">
                                        <label for="background_size" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Size', 'modify-login'); ?></label>
                                        <select id="background_size" name="background_size" class="w-full px-3 py-2.5 border border-gray-300 rounded-md text-sm text-gray-800 bg-white transition-all hover:border-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                            <option value="auto"><?php _e('Auto', 'modify-login'); ?></option>
                                            <option value="cover"><?php _e('Cover', 'modify-login'); ?></option>
                                            <option value="contain"><?php _e('Contain', 'modify-login'); ?></option>
                                        </select>
                                    </div>
                                    <div class="form-group mb-4 last:mb-0">
                                        <label for="background_position" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Position', 'modify-login'); ?></label>
                                        <select id="background_position" name="background_position" class="w-full px-3 py-2.5 border border-gray-300 rounded-md text-sm text-gray-800 bg-white transition-all hover:border-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                            <option value="center center"><?php _e('Center', 'modify-login'); ?></option>
                                            <option value="top left"><?php _e('Top Left', 'modify-login'); ?></option>
                                            <option value="top center"><?php _e('Top Center', 'modify-login'); ?></option>
                                            <option value="top right"><?php _e('Top Right', 'modify-login'); ?></option>
                                            <option value="center left"><?php _e('Center Left', 'modify-login'); ?></option>
                                            <option value="center right"><?php _e('Center Right', 'modify-login'); ?></option>
                                            <option value="bottom left"><?php _e('Bottom Left', 'modify-login'); ?></option>
                                            <option value="bottom center"><?php _e('Bottom Center', 'modify-login'); ?></option>
                                            <option value="bottom right"><?php _e('Bottom Right', 'modify-login'); ?></option>
                                        </select>
                                    </div>
                                    <div class="form-group mb-4 last:mb-0">
                                        <label for="background_repeat" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Repeat', 'modify-login'); ?></label>
                                        <select id="background_repeat" name="background_repeat" class="w-full px-3 py-2.5 border border-gray-300 rounded-md text-sm text-gray-800 bg-white transition-all hover:border-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                            <option value="no-repeat"><?php _e('No Repeat', 'modify-login'); ?></option>
                                            <option value="repeat"><?php _e('Repeat', 'modify-login'); ?></option>
                                            <option value="repeat-x"><?php _e('Repeat X', 'modify-login'); ?></option>
                                            <option value="repeat-y"><?php _e('Repeat Y', 'modify-login'); ?></option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Logo Card -->
                <div class="card bg-white border border-gray-200 rounded-lg mb-5 transition-shadow hover:shadow">
                    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center gap-2.5">
                        <span class="dashicons dashicons-admin-site-alt3 text-gray-500 text-lg w-5 h-5"></span>
                        <h3 class="text-sm font-semibold text-gray-800 m-0"><?php _e('Logo', 'modify-login'); ?></h3>
                    </div>
                    <div class="p-4">
                        <div class="form-group mb-4 last:mb-0">
                            <label for="logo_url" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Logo', 'modify-login'); ?></label>
                            <div class="media-dropzone" data-target="logo_url">
                                <input type="hidden" id="logo_url" name="logo_url" value="<?php echo esc_attr($settings['logo_url']); ?>">
                                
                                <div class="dropzone-area flex flex-col items-center justify-center border-2 border-dashed border-gray-300 bg-gray-50 rounded-lg p-6 transition-all hover:bg-gray-100 cursor-pointer">
                                    <div class="dropzone-icon mb-3 text-gray-400">
                                        <span class="dashicons dashicons-upload text-3xl"></span>
                                    </div>
                                    
                                    <div class="dropzone-content text-center">
                                        <p class="text-sm font-medium text-gray-700 mb-1"><?php _e('Drag & drop your logo here', 'modify-login'); ?></p>
                                        <p class="text-xs text-gray-500"><?php _e('or click to browse', 'modify-login'); ?></p>
                                    </div>
                                </div>
                                
                                <div class="image-preview mt-3 hidden">
                                    <div class="flex items-center gap-3">
                                        <div class="preview-thumbnail w-16 h-16 bg-gray-100 rounded-md border border-gray-200">
                                            <img src="" class="w-full h-full object-contain" alt="<?php _e('Logo Preview', 'modify-login'); ?>">
                                        </div>
                                        <div class="preview-info flex-1">
                                            <p class="filename text-sm font-medium text-gray-700 truncate"></p>
                                            <p class="dimensions text-xs text-gray-500"></p>
                                        </div>
                                        <div class="preview-actions flex gap-2">
                                            <button type="button" class="remove-image-button p-1.5 text-red-500 hover:bg-red-50 rounded-md transition-colors">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                            <button type="button" class="toggle-image-properties p-1.5 text-gray-500 hover:bg-gray-50 rounded-md transition-colors" data-target="logo-properties">
                                                <span class="dashicons dashicons-admin-generic"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="logo-properties" class="image-properties">
                                <div class="popover-header">
                                    <h4><?php _e('Logo Properties', 'modify-login'); ?></h4>
                                    <span class="close-popover dashicons dashicons-no-alt"></span>
                                </div>
                                <div class="popover-content">
                                    <div class="form-group mb-4 last:mb-0">
                                        <label for="logo_width" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Width', 'modify-login'); ?></label>
                                        <input type="text" id="logo_width" name="logo_width" value="<?php echo esc_attr(get_option('modify_login_logo_width', '84px')); ?>" placeholder="84px" class="w-full px-3 py-2.5 border border-gray-300 rounded-md text-sm text-gray-800 bg-white transition-all hover:border-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                    </div>
                                    <div class="form-group mb-4 last:mb-0">
                                        <label for="logo_height" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Height', 'modify-login'); ?></label>
                                        <input type="text" id="logo_height" name="logo_height" value="<?php echo esc_attr(get_option('modify_login_logo_height', '84px')); ?>" placeholder="84px" class="w-full px-3 py-2.5 border border-gray-300 rounded-md text-sm text-gray-800 bg-white transition-all hover:border-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                    </div>
                                    <div class="form-group mb-4 last:mb-0">
                                        <label for="logo_position" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Position', 'modify-login'); ?></label>
                                        <select id="logo_position" name="logo_position" class="w-full px-3 py-2.5 border border-gray-300 rounded-md text-sm text-gray-800 bg-white transition-all hover:border-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                            <option value="center"><?php _e('Center', 'modify-login'); ?></option>
                                            <option value="left"><?php _e('Left', 'modify-login'); ?></option>
                                            <option value="right"><?php _e('Right', 'modify-login'); ?></option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Card -->
                <div class="card bg-white border border-gray-200 rounded-lg mb-5 transition-shadow hover:shadow">
                    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center gap-2.5">
                        <span class="dashicons dashicons-feedback text-gray-500 text-lg w-5 h-5"></span>
                        <h3 class="text-sm font-semibold text-gray-800 m-0"><?php _e('Form', 'modify-login'); ?></h3>
                    </div>
                    <div class="p-4">
                        <div class="form-group mb-4 last:mb-0">
                            <label for="form_background" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Form Background', 'modify-login'); ?></label>
                            <input type="text" id="form_background" name="form_background" value="<?php echo esc_attr($settings['form_background']); ?>" class="color-picker" data-default-color="#ffffff">
                        </div>
                        <div class="form-group mb-4 last:mb-0">
                            <label for="form_border_radius" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Border Radius', 'modify-login'); ?></label>
                            <input type="text" id="form_border_radius" name="form_border_radius" value="<?php echo esc_attr($settings['form_border_radius']); ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-md text-sm text-gray-800 bg-white transition-all hover:border-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div class="form-group mb-4 last:mb-0">
                            <label for="form_padding" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Padding', 'modify-login'); ?></label>
                            <input type="text" id="form_padding" name="form_padding" value="<?php echo esc_attr($settings['form_padding']); ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-md text-sm text-gray-800 bg-white transition-all hover:border-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        </div>
                    </div>
                </div>

                <!-- Button Card -->
                <div class="card bg-white border border-gray-200 rounded-lg mb-5 transition-shadow hover:shadow">
                    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center gap-2.5">
                        <span class="dashicons dashicons-button text-gray-500 text-lg w-5 h-5"></span>
                        <h3 class="text-sm font-semibold text-gray-800 m-0"><?php _e('Button', 'modify-login'); ?></h3>
                    </div>
                    <div class="p-4">
                        <div class="form-group mb-4 last:mb-0">
                            <label for="button_color" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Button Color', 'modify-login'); ?></label>
                            <input type="text" id="button_color" name="button_color" value="<?php echo esc_attr($settings['button_color']); ?>" class="color-picker" data-default-color="#0073aa">
                        </div>
                        <div class="form-group mb-4 last:mb-0">
                            <label for="button_text_color" class="block mb-2 text-sm font-medium text-gray-700"><?php _e('Button Text Color', 'modify-login'); ?></label>
                            <input type="text" id="button_text_color" name="button_text_color" value="<?php echo esc_attr($settings['button_text_color']); ?>" class="color-picker" data-default-color="#ffffff">
                        </div>
                    </div>
                </div>

                <!-- Custom CSS Card -->
                <div class="card bg-white border border-gray-200 rounded-lg mb-5 transition-shadow hover:shadow">
                    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center gap-2.5">
                        <span class="dashicons dashicons-editor-code text-gray-500 text-lg w-5 h-5"></span>
                        <h3 class="text-sm font-semibold text-gray-800 m-0"><?php _e('Custom CSS', 'modify-login'); ?></h3>
                    </div>
                    <div class="p-4">
                        <div class="form-group mb-4 last:mb-0">
                            <textarea id="custom_css" name="custom_css" rows="5" class="w-full px-3 py-2.5 border border-gray-300 rounded-md text-sm text-gray-800 bg-white transition-all hover:border-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 resize-y min-h-[100px]"><?php echo esc_textarea($settings['custom_css']); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview Panel -->
        <div class="flex-1 bg-white shadow-md">
            <div class="h-full p-6 flex items-center justify-center bg-gray-50">
                <iframe id="login-preview" src="<?php echo wp_login_url(); ?>" class="w-full h-full border-none bg-white shadow"></iframe>
            </div>
        </div>
    </div>
</div>