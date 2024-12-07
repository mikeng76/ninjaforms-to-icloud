<?php
/**
 * Plugin Name: Ninja Forms - Apple Contacts
 * Description: Integrates Ninja Forms with Apple Contacts. Takes form input and adds it to Apple Contacts via CardDAV.
 * Version: 1.0
 * Author: Minh Nguyen
 * Author URI: mailto:cutebiz@gmail.com
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

require_once dirname(__FILE__) . '/icloud-carddav.php';

// Register settings menu
add_action('admin_menu', 'ninja_to_icloud_register_menu');
function ninja_to_icloud_register_menu() {

//    add_options_page(
//        'Ninja to Apple Contacts',
//        'Ninja to Apple Contacts',
//        'manage_options',
//        'ninja-to-icloud',
//        'ninja_to_icloud_settings_page'
//    );

    $capability  = apply_filters( 'nfi_required_capabilities', 'manage_options' );
    $parent_slug = 'nfi_main_menu';

    add_menu_page( esc_html__( 'Ninja to Apple Contacts', 'ninjaforms-to-icloud' ), esc_html__( 'Ninja to Apple Contacts', 'ninjaforms-to-icloud' ), $capability, $parent_slug, 'ninja_to_icloud_settings_page', 'dashicons-forms' );
    add_submenu_page( $parent_slug, esc_html__( 'Ninja to Apple Contacts Settings', 'ninjaforms-to-icloud' ), esc_html__( 'Settings', 'ninjaforms-to-icloud' ), $capability, 'nfi_setting', 'ninja_to_icloud_settings_page' );

    // Remove the default one so we can add our customized version.
    remove_submenu_page( $parent_slug, 'nfi_main_menu' );
    add_submenu_page( $parent_slug, esc_html__( 'Ninja to Apple Contacts Help', 'ninjaforms-to-icloud' ), esc_html__( 'Help', 'ninjaforms-to-icloud' ), $capability, 'nfi_support', 'nfi_support' );

}

// Render settings page
function ninja_to_icloud_settings_page() {
    if (!current_user_can('manage_options')) return;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('ninja_to_icloud_save_settings')) {
        update_option('ninja_to_icloud_email', sanitize_email($_POST['icloud_email']));
        update_option('ninja_to_icloud_password', sanitize_text_field($_POST['icloud_password']));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $email = get_option('ninja_to_icloud_email', '');
    $password = get_option('ninja_to_icloud_password', '');
    ?>
    <div class="wrap">
        <h1>Ninja Forms to Apple Contacts</h1>
        <form method="post">
            <?php wp_nonce_field('ninja_to_icloud_save_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="icloud_email">iCloud Email</label></th>
                    <td><input type="email" id="icloud_email" name="icloud_email" value="<?php echo esc_attr($email); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="icloud_password">iCloud App Password</label></th>
                    <td><input type="password" id="icloud_password" name="icloud_password" value="<?php echo esc_attr($password); ?>" class="regular-text" required></td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}

function generateUuidV4() {
    $data = openssl_random_pseudo_bytes(16);

    // Set version to 0100 (UUID v4)
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    // Set variant to 10xx
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// Hook into Ninja Forms submission
add_action('ninja_forms_after_submission', 'ninja_to_icloud_add_contact', 10, 1);
function ninja_to_icloud_add_contact($form_data) {
    // Retrieve iCloud credentials from settings
    $icloud_email = get_option('ninja_to_icloud_email');
    $icloud_password = get_option('ninja_to_icloud_password');
    $base_url = 'https://contacts.icloud.com';

    if (!$icloud_email || !$icloud_password) return;

    $to_work = isset($form_data['fields_by_key']['firstname_1644487673031']);

    // Works on homepage form
    if (isset($form_data['fields_by_key']['firstname_1644487673031']['value'])) {

        // Extract form data (adjust field names based on your Ninja Form setup)
        $fname = $form_data['fields_by_key']['firstname_1644487673031']['value'] ?? '';
        $lname = $form_data['fields_by_key']['lastname_1644487677940']['value'] ?? '';
        $email = $form_data['fields_by_key']['email_address_1644489785898']['value'] ?? '';
        $mobile = $form_data['fields_by_key']['mobile_1667227723543']['value'] ?? '';
        $landline = $form_data['fields_by_key']['landline_1667736983039']['value'] ?? '';
        $street = $form_data['fields_by_key']['street_address_1716001837416']['value'] ?? '';
        $city = $form_data['fields_by_key']['suburb_1667220413467']['value'] ?? '';
        $state = 'South Australia'; // $form_data['fields_by_key']['south_australia_1667225558552']['value'] ?? '';
        $zip = $form_data['fields_by_key']['postcode_1644489791663']['value'] ?? '';

        $notes = '';
        $no_of_windows = $form_data['fields_by_key']['number_of_windows_to_be_installed_1671886608216']['value'] ?? '';
        if ($no_of_windows != '') {
            $notes = 'Number of Windows to be Installed: ' . $no_of_windows;
        }
        $no_of_doors = $form_data['fields_by_key']['number_of_doors_to_be_installed_1671887029155']['value'] ?? '';
        if ($no_of_doors != '') {
            $notes .= '. Number of Doors to be Installed: ' . $no_of_doors;
        }
        $frame_material = $form_data['fields_by_key']['window_frame_material_preference_choose_all_that_apply_1671890042322']['value'] ?? '';
        if ($frame_material != '') {
            $notes .= '. Window Frame Material Preference: ' . $frame_material[0];
        }
        $proj_details = $form_data['fields_by_key']['project_details_1713636447000']['value'] ?? '';
        if ($proj_details != '') {
            $notes .= '. Project Details: ' . $proj_details;
        }

        $uid = generateUuidV4();

        // Create vCard data
        $vCardData = <<<VCF
BEGIN:VCARD
VERSION:3.0
FN:$fname $lname
N:$lname;$fname;;;
EMAIL;TYPE=OTHER;TYPE=pref;TYPE=INTERNET:$email
VCF;

        if ($mobile != '') {
            $vCardData .= "\nTEL;TYPE=CELL:".$mobile;
        }

        if ($landline != '') {
            $vCardData .= "\nTEL;TYPE=MAIN:".$landline;
        }

        if ($notes != '') {
            $vCardData .= "\nNOTE:".$notes;
        }

        $vCardData .= "\nitem1.ADR;TYPE=HOME;TYPE=pref:;;". $street . ";" . $city . ";" . $state . ";" . $zip . ";Australia";
        $vCardData .= "\nitem1.X-ABADR:au";

        $vCardData .= "\nSOURCE:Website contact\nUID:".$uid."\nEND:VCARD";

        // Add vCard to Apple Contacts
        try {

            // Use your iCloud email and an app-specific password
            $cardDav = new ICloudCardDAV($icloud_email, $icloud_password);
            $cardDav->createContact($vCardData, $uid);

            error_log("Contact added successfully to Apple Contacts.");

        } catch (Exception $e) {

            error_log("Failed to add contact. Error: " . $e->getMessage());

        }

    }
}

// Add user documentation
// add_action('admin_notices', 'ninja_to_icloud_admin_notice');
function ninja_to_icloud_admin_notice() {
    $screen = get_current_screen();
    if ($screen->id !== 'settings_page_ninja-to-icloud') return;

    echo '<div class="notice notice-info">
        <p><strong>How to Use Ninja Forms to Apple Contacts:</strong></p>
        <ol>
            <li>Go to "Settings > Ninja to Apple Contacts" and enter your iCloud email and app-specific password.</li>
            <li>Create a Ninja Form with fields for Name, Email, and Phone (ensure field keys match).</li>
            <li>Submit the form, and the contact will be added to your Apple Contacts.</li>
        </ol>
    </div>';
}

/**
 * Edit links that appear on installed plugins list page, for our plugin.
 *
 * @since 1.0.0
 *
 * @internal
 *
 * @param array $links Array of links to display below our plugin listing.
 * @return array Amended array of links.
 */
function nfi_edit_plugin_list_links( $links ) {

    if ( is_array( $links ) && isset( $links['edit'] ) ) {
        // We shouldn't encourage editing our plugin directly.
        unset( $links['edit'] );
    }

    // Add our custom links to the returned array value.
    return array_merge(
        array(
            '<a href="' . admin_url( 'admin.php?page=nfi_support' ) . '">' . esc_html__( 'Documentation', 'ninjaforms-to-icloud' ) . '</a>',
        ),
        $links
    );
}

add_filter( 'plugin_action_links_ninjaforms-to-icloud/ninjaforms-to-icloud.php', 'nfi_edit_plugin_list_links' );

/**
 * Create our settings page output.
 *
 * @since 1.0.0
 *
 * @internal
 */
function nfi_support() {
    echo '<div class="wrap nfi-support">';
    /**
     * Fires immediately after wrap div started on all of the cptui admin pages.
     *
     * @since 1.14.0
     */
    do_action( 'nfi_inside_wrap' );

    /**
     * Fires at the top of the FAQ/Support page.
     *
     * @since 1.0.0
     */
    do_action( 'nfi_main_page_before_faq' ); ?>

    <h1><?php esc_html_e( 'Ninja Forms to Apple Contacts Help', 'ninjaforms-to-icloud' ); ?></h1>


    <table id="support" class="form-table cptui-table">
        <tr>
            <td class="outer">
                <h2><?php esc_html_e( 'How to Use Ninja Forms to Apple Contacts', 'ninjaforms-to-icloud' ); ?></h2>
                <p>Go to "Ninja to Apple Contacts > Settings" and enter your iCloud email and app-specific password.  If your Apple ID uses a non-icloud.com email address, you will need to use icloud.com or me.com alias as the iCloud email.<p>
                <p>Submit ‘Get a Quote’ form at the homepage (https://finestrasa.com.au/#contact). It should add contact to your iCloud Contacts.<p>
                <h2><?php esc_html_e( 'Notes', 'ninjaforms-to-icloud' ); ?></h2>
                <p>Each form has unique form input IDs.  If you edit/change input ID then data might not be captured.<p>
                <p>SOURCE custom field of the vCard is invisible and can be seen when exporting vCard.<p>
            </td>
        </tr>
    </table>

    <?php

    /**
     * Fires at the bottom of the FAQ/Support page.
     *
     * @since 1.0.0
     */
    do_action( 'nfi_main_page_after_faq' );

    echo '</div>';
}
