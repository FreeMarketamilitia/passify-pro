<?php
namespace PassifyPro\Core\Admin;

use PassifyPro\Core\Security\SecurityHandler;
use PassifyPro\Core\Security\Debugger;

class AdminSettingsPage {

    private SecurityHandler $securityHandler;
    private Debugger $debugger;

    public function __construct(SecurityHandler $securityHandler, Debugger $debugger) {
        $this->securityHandler = $securityHandler;
        $this->debugger = $debugger;

        add_action('admin_menu', [$this, 'addAdminPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    public function addAdminPage(): void {
        add_menu_page(
            'Passify Pro Settings',
            'Passify Pro',
            'manage_options',
            'passify_pro_settings',
            [$this, 'renderSettingsPage'],
            'dashicons-admin-generic',
            75
        );
    }

    public function renderSettingsPage(): void {
        // Handle initialization of custom fields if requested
        if (isset($_GET['init_custom_fields']) && check_admin_referer('init_custom_fields_action')) {
            $this->initializeCustomFields();
            wp_redirect(admin_url('admin.php?page=passify_pro_settings&settings-updated=true'));
            exit;
        }

        ?>
        <div class="wrap">
            <h1>Passify Pro Settings</h1>
            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'true'): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Settings updated successfully.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php" enctype="multipart/form-data">
                <?php
                settings_fields('passify_pro_settings_group');
                do_settings_sections('passify_pro_settings');
                submit_button();
                ?>
            </form>

            <div class="passifypro-admin-links">
                <h2>Additional Tools</h2>
                <p>
                    <a href="<?php echo esc_url(home_url('?passifypro_qrscanner=1')); ?>" target="_blank">Open QR Scanner</a>
                </p>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=passify_pro_settings&init_custom_fields=1'), 'init_custom_fields_action'); ?>" class="button">Initialize Custom Fields</a>
                    <span class="description">Creates custom metadata fields in a test order if they don’t exist.</span>
                </p>
            </div>
        </div>
        <?php
    }

    public function registerSettings(): void {
        // Issuer ID
        add_settings_section('issuer_id_section', 'Google Wallet Issuer ID', null, 'passify_pro_settings');
        add_settings_field('google_issuer_id', 'Issuer ID', [$this, 'renderIssuerIdField'], 'passify_pro_settings', 'issuer_id_section');
        register_setting('passify_pro_settings_group', 'google_issuer_id', ['sanitize_callback' => 'sanitize_text_field']);

        // Service Account Key
        add_settings_section('service_account_key_section', 'Google Wallet Service Account Key', null, 'passify_pro_settings');
        add_settings_field('service_account_key', 'Upload Service Account Key', [$this, 'renderServiceAccountKeyField'], 'passify_pro_settings', 'service_account_key_section');
        register_setting('passify_pro_settings_group', 'service_account_key', [$this, 'validateServiceAccountKey']);

        // Metadata Fields
        add_settings_section('metadata_fields_section', 'Google Wallet Metadata Field Mapping', null, 'passify_pro_settings');
        add_settings_field('metadata_fields', 'Map Metadata Fields to Google API', [$this, 'renderMetadataFieldMapping'], 'passify_pro_settings', 'metadata_fields_section');
        register_setting('passify_pro_settings_group', 'metadata_fields', [
            'sanitize_callback' => function($input) {
                return is_array($input) ? array_map('sanitize_text_field', $input) : [];
            }
        ]);

        // Ticket Validation Roles
        add_settings_section('ticket_validation_roles_section', 'Ticket Validation Roles', null, 'passify_pro_settings');
        add_settings_field('ticket_validation_roles', 'Select Ticket Validation Roles', [$this, 'renderTicketValidationRolesField'], 'passify_pro_settings', 'ticket_validation_roles_section');
        register_setting('passify_pro_settings_group', 'ticket_validation_roles', [
            'sanitize_callback' => function($input) {
                return is_array($input) ? array_map('sanitize_text_field', $input) : [];
            }
        ]);

        // Product Categories
        add_settings_section('product_categories_section', 'Product Categories for Pass Generation', null, 'passify_pro_settings');
        add_settings_field('product_categories', 'Select Product Categories', [$this, 'renderProductCategoriesField'], 'passify_pro_settings', 'product_categories_section');
        register_setting('passify_pro_settings_group', 'product_categories', [
            'sanitize_callback' => function($input) {
                return is_array($input) ? array_map('absint', $input) : [];
            }
        ]);
    }

    public function renderIssuerIdField(): void {
        $issuerId = esc_attr(get_option('google_issuer_id', ''));
        ?>
        <input type="text" name="google_issuer_id" value="<?php echo $issuerId; ?>" placeholder="Enter your Google Wallet Issuer ID" style="width: 300px;">
        <p class="description">Set the Issuer ID for your Google Wallet passes.</p>
        <?php
    }

    public function renderServiceAccountKeyField(): void {
        ?>
        <div class="card-container">
            <div class="card">
                <h3>Service Account Key</h3>
                <p class="description">Upload the JSON file for your Google Wallet Service Account Key. This key will be encrypted and stored securely.</p>
                <input type="file" name="service_account_key" accept=".json" />
                <div id="upload-result"></div>
                <?php
                if (isset($_GET['error']) && $_GET['error'] === 'invalid_file') {
                    echo '<div class="error-message">Invalid file type. Please upload a valid .json file.</div>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    public function validateServiceAccountKey($input): string {
        if (isset($_FILES['service_account_key']) && $_FILES['service_account_key']['error'] === UPLOAD_ERR_OK) {
            $sanitizedFile = $this->securityHandler->sanitizeFileUpload($_FILES['service_account_key']);
            if (!$sanitizedFile) {
                wp_redirect(admin_url('admin.php?page=passify_pro_settings&error=invalid_file'));
                exit;
            }

            $fileType = mime_content_type($sanitizedFile['tmp_name']);
            if ($fileType !== 'application/json') {
                wp_redirect(admin_url('admin.php?page=passify_pro_settings&error=invalid_file'));
                exit;
            }

            $uploadedFile = wp_handle_upload($sanitizedFile, ['test_form' => false]);
            if ($uploadedFile && !isset($uploadedFile['error'])) {
                $fileContent = file_get_contents($uploadedFile['file']);
                $decodedJson = json_decode($fileContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    wp_redirect(admin_url('admin.php?page=passify_pro_settings&error=invalid_file'));
                    exit;
                }

                $this->securityHandler->storeServiceAccountKeys($decodedJson);
                unlink($uploadedFile['file']);

                return 'Service account key uploaded and encrypted successfully.';
            } else {
                return 'Error uploading the service account key.';
            }
        }
        return '';
    }

    public function renderMetadataFieldMapping(): void {
        $fields = get_option('metadata_fields', []);
        $metaKeys = $this->getWooCommerceMetaKeys();

        $googleWalletFields = [
            'class_id' => 'Class ID (Event Name)',
            'venue_name' => 'Venue Name',
            'event_time' => 'Event Time',
            'ticket_number' => 'Ticket Number',
            'expiration_date' => 'Expiration Date'
        ];
        ?>
        <div class="card-container">
            <div class="card">
                <h3>Map Metadata Fields to Google Wallet API</h3>
                <p class="description">Map WooCommerce order metadata fields to Google Wallet API fields.</p>
                <?php foreach ($googleWalletFields as $apiField => $label): ?>
                    <div class="metadata-field-mapping" style="margin-bottom: 15px;">
                        <label for="<?php echo esc_attr($apiField); ?>" style="display: inline-block; width: 150px;"><?php echo esc_html($label); ?>:</label>
                        <select name="metadata_fields[<?php echo esc_attr($apiField); ?>]" id="<?php echo esc_attr($apiField); ?>">
                            <option value="">Select Metadata Field</option>
                            <?php
                            foreach ($metaKeys as $metaKey) {
                                $selected = (isset($fields[$apiField]) && $fields[$apiField] === $metaKey) ? 'selected' : '';
                                echo "<option value='" . esc_attr($metaKey) . "' $selected>" . esc_html($metaKey) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function getWooCommerceMetaKeys(): array {
        global $wpdb;

        // Fetch meta keys from WooCommerce orders
        $metaKeys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_key 
                 FROM $wpdb->postmeta 
                 WHERE post_id IN (
                     SELECT ID 
                     FROM $wpdb->posts 
                     WHERE post_type = %s
                 )",
                'shop_order'
            )
        );

        // Define custom metafields expected by the plugin
        $customMetaKeys = [
            'order_title',
            'billing_address',
            'event_date',
            '_order_key',
            'expiration_field'
        ];

        // Include standard WooCommerce fields
        $standardKeys = [
            'billing_first_name',
            'billing_last_name',
            'billing_email',
            'billing_phone',
            'order_total',
            'order_date',
            '_customer_user'
        ];

        // Merge all keys, ensuring custom fields are always available
        $combinedKeys = array_unique(array_merge($metaKeys, $customMetaKeys, $standardKeys));

        if (empty($metaKeys)) {
            $this->debugger->logError("No dynamic WooCommerce order meta keys found; including custom and standard fields.");
        }

        return $combinedKeys;
    }

    private function initializeCustomFields(): void {
        // Create a test order if none exist, or use an existing one
        $existingOrders = get_posts([
            'post_type' => 'shop_order',
            'post_status' => 'any',
            'numberposts' => 1
        ]);

        if (empty($existingOrders)) {
            // Create a new test order
            $order = wc_create_order();
            $order_id = $order->get_id();
            $order->set_billing_email('test@example.com');
            $order->set_status('completed');
            $order->save();
        } else {
            $order_id = $existingOrders[0]->ID;
        }

        // Define custom metafields with default values
        $customFields = [
            'order_title' => 'Test Event ' . $order_id,
            'billing_address' => 'Test Venue Address',
            'event_date' => current_time('Y-m-d H:i:s'),
            '_order_key' => 'wc_order_test_' . wp_generate_password(12, false),
            'expiration_field' => date('Y-m-d H:i:s', strtotime('+1 month'))
        ];

        // Add custom fields to the test order if not already present
        foreach ($customFields as $key => $value) {
            if (!metadata_exists('post', $order_id, $key)) {
                update_post_meta($order_id, $key, $value);
                $this->debugger->logOperation("Initialized custom metafield '$key' for order $order_id", "AdminSettingsPage");
            }
        }
    }

    public function renderTicketValidationRolesField(): void {
        $rolesRaw = get_option('ticket_validation_roles', []);
        $roles = is_array($rolesRaw) ? $rolesRaw : [];
        $allRoles = wp_roles()->roles;
        ?>
        <div class="card-container">
            <div class="card">
                <h3>Ticket Validation Roles</h3>
                <select name="ticket_validation_roles[]" multiple size="5">
                    <?php foreach ($allRoles as $roleKey => $role): ?>
                        <option value="<?php echo esc_attr($roleKey); ?>" <?php echo in_array($roleKey, $roles) ? 'selected' : ''; ?>>
                            <?php echo esc_html($role['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Select the roles allowed to validate tickets.</p>
            </div>
        </div>
        <?php
    }

    public function renderProductCategoriesField(): void {
        $selectedCatsRaw = get_option('product_categories', []);
        $selectedCats = is_array($selectedCatsRaw) ? $selectedCatsRaw : [];
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
        ?>
        <div class="card-container">
            <div class="card">
                <h3>Product Categories</h3>
                <select name="product_categories[]" multiple size="5">
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo esc_attr($category->term_id); ?>" <?php echo in_array($category->term_id, $selectedCats) ? 'selected' : ''; ?>>
                            <?php echo esc_html($category->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Select WooCommerce product categories for pass generation.</p>
            </div>
        </div>
        <?php
    }

    public function enqueueAdminAssets(): void {
        wp_enqueue_style('passify-pro-admin', plugin_dir_url(__FILE__) . 'assets/css/admin.css');
        wp_enqueue_script('passify-pro-admin-js', plugin_dir_url(__FILE__) . 'assets/js/admin.js', ['jquery'], null, true);
    }
}