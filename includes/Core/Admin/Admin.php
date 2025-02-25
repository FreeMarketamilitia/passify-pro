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

        // Add custom fields to WooCommerce product edit page
        add_action('woocommerce_product_options_general_product_data', [$this, 'addCustomProductFields']);
        add_action('woocommerce_process_product_meta', [$this, 'saveCustomProductFields']);

        // Transfer product fields to order
        add_action('woocommerce_checkout_update_order_meta', [$this, 'transferProductFieldsToOrder']);

        // Initialize custom fields for all existing products on plugin load
        add_action('plugins_loaded', [$this, 'initializeProductFieldsOnLoad']);

        // Handle manual metafield addition via button
        add_action('admin_post_passify_pro_add_metafields', [$this, 'handleAddMetafields']);

        $this->debugger->logOperation("AdminSettingsPage constructed.", "AdminSettingsPage");
    }

    public function addAdminPage(): void {
        add_menu_page(
            'Passify Pro Settings',
            'Passify Pro',
            'manage_options',
            'passify_pro_settings',
            [$this, 'renderSettingsPage'],
            'dashicons-tickets-alt',
            75
        );
        $this->debugger->logOperation("Added admin menu page 'Passify Pro Settings'.", "AdminSettingsPage");
    }

    public function renderSettingsPage(): void {
        ?>
        <div class="wrap passify-pro-wrap">
            <header class="passify-pro-header">
                <h1 class="passify-pro-title">
                    <span class="dashicons dashicons-tickets-alt"></span> Passify Pro Settings
                </h1>
                <p class="passify-pro-subtitle">Configure Google Wallet passes for your WooCommerce ticket products.</p>
            </header>

            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'true'): ?>
                <div class="notice notice-success is-dismissible passify-pro-notice">
                    <p><strong>Success:</strong> Settings saved successfully.</p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['metafields-added']) && $_GET['metafields-added'] == 'true'): ?>
                <div class="notice notice-success is-dismissible passify-pro-notice">
                    <p><strong>Success:</strong> Metafields added to all products.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php" enctype="multipart/form-data" class="passify-pro-form">
                <?php
                settings_fields('passify_pro_settings_group');
                do_settings_sections('passify_pro_settings');
                ?>
                <div class="passify-pro-submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary button-hero" value="Save Changes">
                </div>
            </form>

            <div class="passify-pro-actions">
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="passify-pro-action-form">
                    <input type="hidden" name="action" value="passify_pro_add_metafields">
                    <?php wp_nonce_field('passify_pro_add_metafields_nonce', 'passify_pro_nonce'); ?>
                    <button type="submit" class="button button-secondary">Add Metafields to All Products</button>
                    <p class="description passify-pro-desc">This will add blank Google Wallet metafields to all existing products.</p>
                </form>
            </div>

            <aside class="passify-pro-sidebar">
                <h2 class="passify-pro-sidebar-title">Resources</h2>
                <ul class="passify-pro-sidebar-links">
                    <li><a href="<?php echo esc_url(home_url('?passifypro_qrscanner=1')); ?>" target="_blank" class="button">Launch QR Scanner</a></li>
                    <li><a href="https://wallet.google.com" target="_blank" class="passify-pro-link">Google Wallet Console</a></li>
                </ul>
            </aside>
        </div>
        <?php
        $this->debugger->logOperation("Rendered settings page.", "AdminSettingsPage");
    }

    public function registerSettings(): void {
        // Google Wallet Credentials Section
        add_settings_section(
            'credentials_section',
            'Google Wallet Credentials',
            function() {
                echo '<p class="passify-pro-section-desc">Enter your Google Wallet API credentials to enable pass generation.</p>';
                $this->debugger->logOperation("Added 'Google Wallet Credentials' section.", "AdminSettingsPage");
            },
            'passify_pro_settings'
        );

        add_settings_field('google_issuer_id', 'Issuer ID', [$this, 'renderIssuerIdField'], 'passify_pro_settings', 'credentials_section');
        register_setting('passify_pro_settings_group', 'google_issuer_id', [
            'sanitize_callback' => function($input) {
                $sanitized = sanitize_text_field($input);
                $this->debugger->logOperation("Sanitized google_issuer_id input.", "AdminSettingsPage", ['input' => $input, 'sanitized' => $sanitized]);
                return $sanitized;
            },
            'default' => ''
        ]);

        add_settings_field('google_class_id', 'Class ID', [$this, 'renderClassIdField'], 'passify_pro_settings', 'credentials_section');
        register_setting('passify_pro_settings_group', 'google_class_id', [
            'sanitize_callback' => function($input) {
                $sanitized = sanitize_text_field($input);
                $this->debugger->logOperation("Sanitized google_class_id input.", "AdminSettingsPage", ['input' => $input, 'sanitized' => $sanitized]);
                return $sanitized;
            },
            'default' => ''
        ]);

        add_settings_field('service_account_key', 'Service Account Key', [$this, 'renderServiceAccountKeyField'], 'passify_pro_settings', 'credentials_section');
        register_setting('passify_pro_settings_group', 'service_account_key', [$this, 'validateServiceAccountKey']);

        // Metadata Fields Section
        add_settings_section(
            'metadata_fields_section',
            'Field Mapping',
            function() {
                echo '<p class="passify-pro-section-desc">Map WooCommerce fields to Google Wallet ticket properties.</p>';
                $this->debugger->logOperation("Added 'Field Mapping' section.", "AdminSettingsPage");
            },
            'passify_pro_settings'
        );
        add_settings_field('metadata_fields', '', [$this, 'renderMetadataFieldMapping'], 'passify_pro_settings', 'metadata_fields_section');
        register_setting('passify_pro_settings_group', 'metadata_fields', [
            'sanitize_callback' => function($input) {
                $sanitized = is_array($input) ? array_map('sanitize_text_field', $input) : [];
                $this->debugger->logOperation("Sanitized metadata_fields input.", "AdminSettingsPage", ['input' => $input, 'sanitized' => $sanitized]);
                return $sanitized;
            }
        ]);

        // Ticket Validation Roles Section
        add_settings_section(
            'ticket_validation_roles_section',
            'Validation Roles',
            function() {
                echo '<p class="passify-pro-section-desc">Select user roles authorized to validate tickets.</p>';
                $this->debugger->logOperation("Added 'Validation Roles' section.", "AdminSettingsPage");
            },
            'passify_pro_settings'
        );
        add_settings_field('ticket_validation_roles', '', [$this, 'renderTicketValidationRolesField'], 'passify_pro_settings', 'ticket_validation_roles_section');
        register_setting('passify_pro_settings_group', 'ticket_validation_roles', [
            'sanitize_callback' => function($input) {
                $sanitized = is_array($input) ? array_map('sanitize_text_field', $input) : [];
                $this->debugger->logOperation("Sanitized ticket_validation_roles input.", "AdminSettingsPage", ['input' => $input, 'sanitized' => $sanitized]);
                return $sanitized;
            }
        ]);

        // Product Categories Section
        add_settings_section(
            'product_categories_section',
            'Ticket Categories',
            function() {
                echo '<p class="passify-pro-section-desc">Choose product categories that will trigger Google Wallet pass generation.</p>';
                $this->debugger->logOperation("Added 'Ticket Categories' section.", "AdminSettingsPage");
            },
            'passify_pro_settings'
        );
        add_settings_field('product_categories', '', [$this, 'renderProductCategoriesField'], 'passify_pro_settings', 'product_categories_section');
        register_setting('passify_pro_settings_group', 'product_categories', [
            'sanitize_callback' => function($input) {
                $sanitized = is_array($input) ? array_map('absint', $input) : [];
                $this->debugger->logOperation("Sanitized product_categories input.", "AdminSettingsPage", ['input' => $input, 'sanitized' => $sanitized]);
                return $sanitized;
            }
        ]);

        $this->debugger->logOperation("Registered all settings sections and fields.", "AdminSettingsPage");
    }

    public function renderIssuerIdField(): void {
        $issuerId = esc_attr(get_option('google_issuer_id', ''));
        ?>
        <div class="passify-pro-field">
            <label for="google_issuer_id" class="passify-pro-label">Issuer ID</label>
            <input type="text" id="google_issuer_id" name="google_issuer_id" value="<?php echo $issuerId; ?>" placeholder="e.g., 3388000000012345678" class="regular-text passify-pro-input">
            <p class="description passify-pro-desc">Your unique Google Wallet Issuer ID, provided by Google.</p>
        </div>
        <?php
        $this->debugger->logOperation("Rendered Issuer ID field.", "AdminSettingsPage", ['value' => $issuerId]);
    }

    public function renderClassIdField(): void {
        $classId = esc_attr(get_option('google_class_id', ''));
        ?>
        <div class="passify-pro-field">
            <label for="google_class_id" class="passify-pro-label">Class ID</label>
            <input type="text" id="google_class_id" name="google_class_id" value="<?php echo $classId; ?>" placeholder="e.g., ConcertClass2025" class="regular-text passify-pro-input">
            <p class="description passify-pro-desc">The Class ID created in the Google Wallet Console for event tickets.</p>
        </div>
        <?php
        $this->debugger->logOperation("Rendered Class ID field.", "AdminSettingsPage", ['value' => $classId]);
    }

    public function renderServiceAccountKeyField(): void {
        ?>
        <div class="passify-pro-field card passify-pro-card">
            <label for="service_account_key" class="passify-pro-label">Service Account Key</label>
            <input type="file" id="service_account_key" name="service_account_key" accept=".json" class="passify-pro-input" />
            <p class="description passify-pro-desc">Upload your Google Wallet Service Account Key JSON file.</p>
            <?php if (isset($_GET['error']) && $_GET['error'] === 'invalid_file'): ?>
                <p class="passify-pro-error">Invalid file type. Please upload a valid .json file.</p>
            <?php endif; ?>
        </div>
        <?php
        $this->debugger->logOperation("Rendered Service Account Key field.", "AdminSettingsPage");
    }

    public function validateServiceAccountKey($input): string {
        if (isset($_FILES['service_account_key']) && $_FILES['service_account_key']['error'] === UPLOAD_ERR_OK) {
            $this->debugger->logOperation("Validating service account key upload.", "AdminSettingsPage", $_FILES['service_account_key']);
            $sanitizedFile = $this->securityHandler->sanitizeFileUpload($_FILES['service_account_key']);
            if (!$sanitizedFile) {
                $this->debugger->logError("Service account key sanitization failed.", ['component' => "AdminSettingsPage"]);
                wp_redirect(admin_url('admin.php?page=passify_pro_settings&error=invalid_file'));
                exit;
            }
    
            $fileType = mime_content_type($sanitizedFile['tmp_name']);
            if ($fileType !== 'application/json') {
                $this->debugger->logError("Invalid file type for service account key: $fileType", ['component' => "AdminSettingsPage"]);
                wp_redirect(admin_url('admin.php?page=passify_pro_settings&error=invalid_file'));
                exit;
            }
    
            $uploadedFile = wp_handle_upload($sanitizedFile, ['test_form' => false]);
            if ($uploadedFile && !isset($uploadedFile['error'])) {
                $fileContent = file_get_contents($uploadedFile['file']);
                $decodedJson = json_decode($fileContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->debugger->logError("Invalid JSON detected in service account key file.", ['component' => "AdminSettingsPage"]);
                    wp_redirect(admin_url('admin.php?page=passify_pro_settings&error=invalid_file'));
                    exit;
                }
    
                $this->securityHandler->storeServiceAccountKeys($decodedJson);
                unlink($uploadedFile['file']);
                $this->debugger->logOperation("Service account key uploaded and encrypted successfully.", "AdminSettingsPage");
                return 'Service account key uploaded and encrypted successfully.';
            } else {
                $this->debugger->logError("Service account key upload failed: " . ($uploadedFile['error'] ?? 'unknown error'), ['component' => "AdminSettingsPage"]);
                return 'Error uploading the service account key.';
            }
        }
        $this->debugger->logOperation("No service account key file provided for validation.", "AdminSettingsPage");
        return '';
    }

    public function renderMetadataFieldMapping(): void {
        $fields = get_option('metadata_fields', []);
        $metaKeys = $this->getWooCommerceMetaKeys();

        $googleWalletFields = [
            'class_id' => 'Event Name',
            'venue_name' => 'Venue Name',
            'event_time' => 'Event Time',
            'ticket_number' => 'Ticket Number',
            'expiration_date' => 'Expiration Date'
        ];
        ?>
        <div class="card passify-pro-card">
            <table class="passify-pro-table">
                <thead>
                    <tr>
                        <th>Google Wallet Field</th>
                        <th>WooCommerce Field</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($googleWalletFields as $apiField => $label): ?>
                        <tr>
                            <td><?php echo esc_html($label); ?></td>
                            <td>
                                <select name="metadata_fields[<?php echo esc_attr($apiField); ?>]" class="passify-pro-select">
                                    <option value="">Select Field</option>
                                    <?php
                                    foreach ($metaKeys as $metaKey) {
                                        $selected = (isset($fields[$apiField]) && $fields[$apiField] === $metaKey) ? 'selected' : '';
                                        echo "<option value='" . esc_attr($metaKey) . "' $selected>" . esc_html($metaKey) . "</option>";
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        $this->debugger->logOperation("Rendered metadata field mapping.", "AdminSettingsPage", ['current_mappings' => $fields]);
    }

    private function getWooCommerceMetaKeys(): array {
        global $wpdb;

        $this->debugger->logOperation("Fetching WooCommerce meta keys.", "AdminSettingsPage");
        $productMetaKeys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_key 
                 FROM $wpdb->postmeta 
                 WHERE post_id IN (
                     SELECT ID 
                     FROM $wpdb->posts 
                     WHERE post_type = %s
                 )",
                'product'
            )
        );

        $orderMetaKeys = $wpdb->get_col(
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

        $customProductMetaKeys = [
            'event_name',
            'venue_name',
            'event_time',
            'expiration_field'
        ];

        $customOrderMetaKeys = [
            'order_number',
            'billing_address',
            'billing_first_name',
            'billing_last_name',
            'billing_email',
            'billing_phone'
        ];

        $combinedKeys = array_unique(array_merge($productMetaKeys, $orderMetaKeys, $customProductMetaKeys, $customOrderMetaKeys));
        $this->debugger->logOperation("Retrieved WooCommerce meta keys.", "AdminSettingsPage", ['total_keys' => count($combinedKeys), 'keys' => $combinedKeys]);

        if (empty($productMetaKeys) && empty($orderMetaKeys)) {
            $this->debugger->logError("No dynamic WooCommerce meta keys found; returning custom keys.", "AdminSettingsPage");
        }

        return $combinedKeys;
    }

    public function initializeProductFieldsOnLoad(): void {
        if (!function_exists('wc_get_products')) {
            $this->debugger->logError("WooCommerce not loaded; skipping initial product field setup.", "AdminSettingsPage");
            return;
        }

        if (!get_option('passify_pro_fields_initialized', false)) {
            $this->debugger->logOperation("Starting initial product field initialization on plugin load.", "AdminSettingsPage");
            $this->addMetafieldsToAllProducts();
            update_option('passify_pro_fields_initialized', true);
            $this->debugger->logOperation("Completed initial product field initialization.", "AdminSettingsPage");
        } else {
            $this->debugger->logOperation("Product fields already initialized on load; skipped.", "AdminSettingsPage");
        }
    }

    public function handleAddMetafields(): void {
        if (!isset($_POST['passify_pro_nonce']) || !wp_verify_nonce($_POST['passify_pro_nonce'], 'passify_pro_add_metafields_nonce')) {
            $this->debugger->logError("Nonce verification failed for add metafields action.", "AdminSettingsPage");
            wp_die('Security check failed.');
        }

        if (!current_user_can('manage_options')) {
            $this->debugger->logError("User lacks permission to add metafields.", "AdminSettingsPage");
            wp_die('Permission denied.');
        }

        $this->debugger->logOperation("Manual request to add metafields to all products received.", "AdminSettingsPage");
        $this->addMetafieldsToAllProducts();

        wp_redirect(admin_url('admin.php?page=passify_pro_settings&metafields-added=true'));
        exit;
    }

    private function addMetafieldsToAllProducts(): void {
        if (!function_exists('wc_get_products')) {
            $this->debugger->logError("WooCommerce not loaded; cannot add metafields to products.", "AdminSettingsPage");
            return;
        }

        $products = wc_get_products(['limit' => -1]);
        $customFields = [
            'event_name' => '',
            'venue_name' => '',
            'event_time' => '',
            'expiration_field' => ''
        ];

        $this->debugger->logOperation(sprintf("Adding metafields to %d products.", count($products)), "AdminSettingsPage");

        foreach ($products as $product) {
            $product_id = $product->get_id();
            foreach ($customFields as $key => $value) {
                if (!metadata_exists('post', $product_id, $key)) {
                    update_post_meta($product_id, $key, $value);
                    $this->debugger->logOperation(sprintf("Added blank metafield '%s' to product ID %d.", $key, $product_id), "AdminSettingsPage");
                } else {
                    $this->debugger->logOperation(sprintf("Metafield '%s' already exists for product ID %d; skipped.", $key, $product_id), "AdminSettingsPage");
                }
            }
        }

        $this->debugger->logOperation("Successfully added metafields to all products.", "AdminSettingsPage");
    }

    public function addCustomProductFields(): void {
        echo '<div class="options_group passify-pro-product-fields">';
        
        woocommerce_wp_text_input([
            'id' => 'event_name',
            'label' => 'Event Name',
            'description' => 'The name of the event for the Google Wallet pass.',
            'desc_tip' => true,
            'placeholder' => 'e.g., Concert at Madison Square Garden'
        ]);

        woocommerce_wp_text_input([
            'id' => 'venue_name',
            'label' => 'Venue Name',
            'description' => 'The venue name for the Google Wallet pass.',
            'desc_tip' => true,
            'placeholder' => 'e.g., Madison Square Garden'
        ]);

        woocommerce_wp_text_input([
            'id' => 'event_time',
            'label' => 'Event Time',
            'description' => 'The date and time of the event.',
            'desc_tip' => true,
            'type' => 'datetime-local',
            'placeholder' => 'e.g., 2025-03-01T19:00'
        ]);

        woocommerce_wp_text_input([
            'id' => 'expiration_field',
            'label' => 'Expiration Date',
            'description' => 'The expiration date of the ticket.',
            'desc_tip' => true,
            'type' => 'datetime-local',
            'placeholder' => 'e.g., 2025-03-02T00:00'
        ]);

        echo '</div>';
        $this->debugger->logOperation("Added custom fields to WooCommerce product edit page.", "AdminSettingsPage");
    }

    public function saveCustomProductFields($product_id): void {
        $this->debugger->logOperation("Saving custom fields for product ID $product_id.", "AdminSettingsPage");
        $fields = [
            'event_name' => isset($_POST['event_name']) ? sanitize_text_field($_POST['event_name']) : '',
            'venue_name' => isset($_POST['venue_name']) ? sanitize_text_field($_POST['venue_name']) : '',
            'event_time' => isset($_POST['event_time']) ? sanitize_text_field($_POST['event_time']) : '',
            'expiration_field' => isset($_POST['expiration_field']) ? sanitize_text_field($_POST['expiration_field']) : ''
        ];

        foreach ($fields as $key => $value) {
            update_post_meta($product_id, $key, $value);
            $this->debugger->logOperation(sprintf("Saved '%s' = '%s' for product ID %d.", $key, $value, $product_id), "AdminSettingsPage");
        }
    }

    public function transferProductFieldsToOrder(int $order_id): void {
        $this->debugger->logOperation("Starting field transfer for order ID $order_id.", "AdminSettingsPage");
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->debugger->logError("Order ID $order_id not found.", ['component' => "AdminSettingsPage"]);
            return;
        }
    
        $items = $order->get_items();
        if (empty($items)) {
            $this->debugger->logError("No items found for order ID $order_id.", ['component' => "AdminSettingsPage"]);
            return;
        }
    
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            if (!$product_id) {
                $this->debugger->logError("Invalid product ID for order item in order $order_id.", ['component' => "AdminSettingsPage"]);
                continue;
            }
            $product_fields = [
                'event_name' => get_post_meta($product_id, 'event_name', true),
                'venue_name' => get_post_meta($product_id, 'venue_name', true),
                'event_time' => get_post_meta($product_id, 'event_time', true),
                'expiration_field' => get_post_meta($product_id, 'expiration_field', true)
            ];
    
            $this->debugger->logOperation("Product fields for ID $product_id", "AdminSettingsPage", $product_fields);
    
            foreach ($product_fields as $key => $value) {
                if ($value && !metadata_exists('post', $order_id, $key)) {
                    update_post_meta($order_id, $key, $value);
                    $this->debugger->logOperation(sprintf("Transferred '%s' = '%s' from product ID %d to order ID %d.", $key, $value, $product_id, $order_id), "AdminSettingsPage");
                }
            }
    
            if (!metadata_exists('post', $order_id, 'order_number')) {
                $order_number = $order->get_order_number();
                update_post_meta($order_id, 'order_number', $order_number);
                $this->debugger->logOperation(sprintf("Transferred order_number = '%s' to order ID %d.", $order_number, $order_id), "AdminSettingsPage");
            }
            break; // First item only
        }
    
        $user_fields = [
            'billing_address' => $order->get_billing_address_1() ?: 'Default Venue',
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name' => $order->get_billing_last_name(),
            'billing_email' => $order->get_billing_email(),
            'billing_phone' => $order->get_billing_phone()
        ];
    
        foreach ($user_fields as $key => $value) {
            if ($value && !metadata_exists('post', $order_id, $key)) {
                update_post_meta($order_id, $key, $value);
                $this->debugger->logOperation(sprintf("Transferred user field '%s' = '%s' to order ID %d.", $key, $value, $order_id), "AdminSettingsPage");
            }
        }
    
        $this->debugger->logOperation("Completed field transfer for order ID $order_id.", "AdminSettingsPage");
    }

    public function renderTicketValidationRolesField(): void {
        $rolesRaw = get_option('ticket_validation_roles', []);
        $roles = is_array($rolesRaw) ? $rolesRaw : [];
        $allRoles = wp_roles()->roles;
        ?>
        <div class="card passify-pro-card">
            <select name="ticket_validation_roles[]" multiple size="5" class="passify-pro-select">
                <?php foreach ($allRoles as $roleKey => $role): ?>
                    <option value="<?php echo esc_attr($roleKey); ?>" <?php echo in_array($roleKey, $roles) ? 'selected' : ''; ?>>
                        <?php echo esc_html($role['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
        $this->debugger->logOperation("Rendered ticket validation roles field.", "AdminSettingsPage", ['selected_roles' => $roles]);
    }

    public function renderProductCategoriesField(): void {
        $selectedCatsRaw = get_option('product_categories', []);
        $selectedCats = is_array($selectedCatsRaw) ? $selectedCatsRaw : [];
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
        ?>
        <div class="card passify-pro-card">
            <select name="product_categories[]" multiple size="5" class="passify-pro-select">
                <?php foreach ($categories as $category): ?>
                    <option value="<?php echo esc_attr($category->term_id); ?>" <?php echo in_array($category->term_id, $selectedCats) ? 'selected' : ''; ?>>
                        <?php echo esc_html($category->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
        $this->debugger->logOperation("Rendered product categories field.", "AdminSettingsPage", ['selected_categories' => $selectedCats]);
    }

    public function enqueueAdminAssets(): void {
        wp_enqueue_style('passify-pro-admin', plugin_dir_url(__FILE__) . 'assets/css/admin.css', [], '1.2.0');
        wp_enqueue_script('passify-pro-admin-js', plugin_dir_url(__FILE__) . 'assets/js/admin.js', ['jquery'], '1.2.0', true);
        $this->debugger->logOperation("Enqueued admin assets (CSS & JS, version 1.2.0).", "AdminSettingsPage");
       }
}