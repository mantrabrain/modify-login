<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="modify-login-builder">
    <div class="builder-panel">
        <div class="panel-header">
            <h2><?php _e('Login Page Builder', 'modify-login'); ?></h2>
            <button class="button button-primary save-settings"><?php _e('Save Changes', 'modify-login'); ?></button>
        </div>

        <div class="panel-content">
            <div class="panel-section">
                <h3><?php _e('Background', 'modify-login'); ?></h3>
                <div class="form-group">
                    <label for="background_color"><?php _e('Background Color', 'modify-login'); ?></label>
                    <input type="color" id="background_color" name="background_color" value="<?php echo esc_attr($settings['background_color']); ?>">
                </div>
                <div class="form-group">
                    <label for="background_image"><?php _e('Background Image', 'modify-login'); ?></label>
                    <input type="text" id="background_image" name="background_image" value="<?php echo esc_attr($settings['background_image']); ?>">
                    <button class="button upload-image"><?php _e('Upload', 'modify-login'); ?></button>
                </div>
            </div>

            <div class="panel-section">
                <h3><?php _e('Logo', 'modify-login'); ?></h3>
                <div class="form-group">
                    <label for="logo_url"><?php _e('Logo URL', 'modify-login'); ?></label>
                    <input type="text" id="logo_url" name="logo_url" value="<?php echo esc_attr($settings['logo_url']); ?>">
                    <button class="button upload-logo"><?php _e('Upload', 'modify-login'); ?></button>
                </div>
            </div>

            <div class="panel-section">
                <h3><?php _e('Login Form', 'modify-login'); ?></h3>
                <div class="form-group">
                    <label for="form_background"><?php _e('Form Background', 'modify-login'); ?></label>
                    <input type="color" id="form_background" name="form_background" value="<?php echo esc_attr($settings['form_background']); ?>">
                </div>
                <div class="form-group">
                    <label for="form_border_radius"><?php _e('Border Radius', 'modify-login'); ?></label>
                    <input type="text" id="form_border_radius" name="form_border_radius" value="<?php echo esc_attr($settings['form_border_radius']); ?>">
                </div>
                <div class="form-group">
                    <label for="form_padding"><?php _e('Padding', 'modify-login'); ?></label>
                    <input type="text" id="form_padding" name="form_padding" value="<?php echo esc_attr($settings['form_padding']); ?>">
                </div>
            </div>

            <div class="panel-section">
                <h3><?php _e('Button', 'modify-login'); ?></h3>
                <div class="form-group">
                    <label for="button_color"><?php _e('Button Color', 'modify-login'); ?></label>
                    <input type="color" id="button_color" name="button_color" value="<?php echo esc_attr($settings['button_color']); ?>">
                </div>
                <div class="form-group">
                    <label for="button_text_color"><?php _e('Button Text Color', 'modify-login'); ?></label>
                    <input type="color" id="button_text_color" name="button_text_color" value="<?php echo esc_attr($settings['button_text_color']); ?>">
                </div>
            </div>

            <div class="panel-section">
                <h3><?php _e('Custom CSS', 'modify-login'); ?></h3>
                <div class="form-group">
                    <textarea id="custom_css" name="custom_css" rows="5"><?php echo esc_textarea($settings['custom_css']); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="builder-preview">
        <div class="preview-container">
            <iframe id="login-preview" src="<?php echo wp_login_url(); ?>"></iframe>
        </div>
    </div>
</div> 