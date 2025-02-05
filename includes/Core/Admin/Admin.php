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

    // Add the settings page to the admin menu
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

    // Render the admin settings page
    public function renderSettingsPage(): void {
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

            <!-- Additional Tools Section -->
            <div class="passifypro-admin-links">
                <h2>Additional Tools</h2>
                <p>
                    <a href="<?php echo esc_url(home_url('?passifypro_qrscanner=1')); ?>" target="_blank">
                        Open QR Scanner
                    </a>
                </p>
            </div>
        </div>
        <?php
    }

    // Register settings and sections including Issuer ID and Service Account Key upload
    public function registerSettings(): void {
        // Section for Issuer ID
        add_settings_section(
            'issuer_id_section', 
            'Google Wallet Issuer ID', 
            null, 
            'passify_pro_settings'
        );
        add_settings_field(
            'google_issuer_id', 
            'Issuer ID', 
            [$this, 'renderIssuerIdField'], 
            'passify_pro_settings', 
            'issuer_id_section'
        );
        register_setting('passify_pro_settings_group', 'google_issuer_id');

        // Service Account Key Upload Section
        add_settings_section(
            'service_account_key_section', 
            'Google Wallet Service Account Key', 
            null, 
            'passify_pro_settings'
        );
        add_settings_field(
            'service_account_key', 
            'Upload Service Account Key', 
            [$this, 'renderServiceAccountKeyField'], 
            'passify_pro_settings', 
            'service_account_key_section'
        );
        register_setting('passify_pro_settings_group', 'service_account_key', [$this, 'validateServiceAccountKey']);

        // Metadata Field Mapping Section
        add_settings_section(
            'metadata_fields_section', 
            'Google Wallet Metadata Field Mapping', 
            null, 
            'passify_pro_settings'
        );
        add_settings_field(
            'metadata_fields', 
            'Map Metadata Fields to Google API', 
            [$this, 'renderMetadataFieldMapping'], 
            'passify_pro_settings', 
            'metadata_fields_section'
        );
        register_setting('passify_pro_settings_group', 'metadata_fields');

        // Ticket Validation Roles Section
        add_settings_section(
            'ticket_validation_roles_section', 
            'Ticket Validation Roles', 
            null, 
            'passify_pro_settings'
        );
        add_settings_field(
            'ticket_validation_roles', 
            'Select Ticket Validation Roles', 
            [$this, 'renderTicketValidationRolesField'], 
            'passify_pro_settings', 
            'ticket_validation_roles_section'
        );
        register_setting('passify_pro_settings_group', 'ticket_validation_roles');

        // Product Categories Section
        add_settings_section(
            'product_categories_section', 
            'Product Categories for Pass Generation', 
            null, 
            'passify_pro_settings'
        );
        add_settings_field(
            'product_categories', 
            'Select Product Categories', 
            [$this, 'renderProductCategoriesField'], 
            'passify_pro_settings', 
            'product_categories_section'
        );
        register_setting('passify_pro_settings_group', 'product_categories');
    }

    // Render Issuer ID Field
    public function renderIssuerIdField(): void {
        $issuerId = esc_attr(get_option('google_issuer_id', ''));
        ?>
        <input type="text" name="google_issuer_id" value="<?php echo $issuerId; ?>" placeholder="Enter your Google Wallet Issuer ID" style="width: 300px;">
        <p class="description">Set the Issuer ID for your Google Wallet passes.</p>
        <?php
    }

    // Render Service Account Key upload field with file type validation
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

    // Validate, encrypt, and store the Service Account Key JSON file
    public function validateServiceAccountKey($input): string {
        if (isset($_FILES['service_account_key']) && $_FILES['service_account_key']['error'] === UPLOAD_ERR_OK) {
            // Validate file upload using the security class's file upload sanitizer
            $sanitizedFile = $this->securityHandler->sanitizeFileUpload($_FILES['service_account_key']);
            if (!$sanitizedFile) {
                wp_redirect(admin_url('admin.php?page=passify_pro_settings&error=invalid_file'));
                exit;
            }

            // Ensure the file is a JSON file
            $fileType = mime_content_type($sanitizedFile['tmp_name']);
            if ($fileType !== 'application/json') {
                wp_redirect(admin_url('admin.php?page=passify_pro_settings&error=invalid_file'));
                exit;
            }

            // Handle the file upload via WordPress
            $uploadedFile = wp_handle_upload($sanitizedFile, ['test_form' => false]);
            if ($uploadedFile && !isset($uploadedFile['error'])) {
                $fileContent = file_get_contents($uploadedFile['file']);
                
                // Verify that the file contains valid JSON
                $decodedJson = json_decode($fileContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    wp_redirect(admin_url('admin.php?page=passify_pro_settings&error=invalid_file'));
                    exit;
                }
                
                // Encrypt and store the JSON service account key
                $encryptedKey = $this->securityHandler->encryptData($fileContent);
                $jsonDecoded = json_decode($encryptedKey, true);
                $this->securityHandler->storeServiceAccountKeys($jsonDecoded);

                // Remove the temporary file
                unlink($uploadedFile['file']);

                return 'Service account key uploaded and encrypted successfully.';
            } else {
                return 'Error uploading the service account key.';
            }
        }
        return '';
    }

    // Render Google Wallet Metadata Field Mapping
    public function renderMetadataFieldMapping(): void {
        $fields = get_option('metadata_fields', []);
        // List of required Google Wallet metadata fields with custom labels
        $googleWalletFields = [
            'class_id'        => 'Class ID (Event Name)',
            'venue_name'      => 'Venue Name',
            'event_time'      => 'Event Time',
            'ticket_number'   => 'Ticket Number',
            'expiration_date' => 'Expiration Date'
        ];
        ?>
        <div class="card-container">
            <div class="card">
                <h3>Map Metadata Fields to Google Wallet API</h3>
                <p class="description">Map your WordPress metadata fields to the required Google Wallet API fields. You can select both core and custom fields.</p>
                <?php foreach ($googleWalletFields as $apiField => $label): ?>
                    <div class="metadata-field-mapping">
                        <label for="<?php echo esc_attr($apiField); ?>"><?php echo esc_html($label); ?>:</label>
                        <select name="metadata_fields[<?php echo esc_attr($apiField); ?>]" id="<?php echo esc_attr($apiField); ?>">
                            <option value="">Select Metadata Field</option>
                            <?php
                            // Assuming get_post_meta_keys() retrieves available metadata keys
                            $wpMetadataFields = get_post_meta_keys();
                            foreach ($wpMetadataFields as $wpField) {
                                $selected = (isset($fields[$apiField]) && $fields[$apiField] === $wpField) ? 'selected' : '';
                                echo "<option value='" . esc_attr($wpField) . "' $selected>" . esc_html($wpField) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    // Render Ticket Validation Roles selection field
    public function renderTicketValidationRolesField(): void {
        $roles = get_option('ticket_validation_roles', []);
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

    // Render Product Categories selection field
    public function renderProductCategoriesField(): void {
        $categories = get_option('product_categories', []);
        $args = ['taxonomy' => 'product_cat', 'orderby' => 'name', 'hide_empty' => false];
        $productCategories = get_terms($args);
        ?>
        <div class="card-container">
            <div class="card">
                <h3>Product Categories</h3>
                <select name="product_categories[]" multiple size="5">
                    <?php foreach ($productCategories as $category): ?>
                        <option value="<?php echo esc_attr($category->term_id); ?>" <?php echo in_array($category->term_id, $categories) ? 'selected' : ''; ?>>
                            <?php echo esc_html($category->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Select the product categories that will generate Google Wallet passes.</p>
            </div>
        </div>
        <?php
    }

    // Enqueue custom CSS and JS for the admin page
    public function enqueueAdminAssets(): void {
        wp_enqueue_style('passify-pro-admin', plugin_dir_url(__FILE__) . 'assets/css/admin.css');
        wp_enqueue_script('passify-pro-admin-js', plugin_dir_url(__FILE__) . 'assets/js/admin.js', ['jquery'], null, true);
    }
}
