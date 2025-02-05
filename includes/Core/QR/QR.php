<?php
namespace PassifyPro\Core\QR;

use PassifyPro\Core\Security\SecurityHandler;
use PassifyPro\Core\Security\Debugger;

class QRScanner {

    private SecurityHandler $securityHandler;
    private Debugger $debugger;
    /** @var array Ticket validation roles allowed to access the scanner */
    private array $ticketValidationRoles;

    /**
     * QRScanner constructor.
     *
     * @param SecurityHandler $securityHandler
     * @param Debugger $debugger
     */
    public function __construct(SecurityHandler $securityHandler, Debugger $debugger) {
        $this->securityHandler = $securityHandler;
        $this->debugger = $debugger;
        $this->ticketValidationRoles = get_option('ticket_validation_roles', []);

        // Register the shortcode for the QR scanner page.
        add_shortcode('passifypro_qrscanner', [$this, 'renderQRScannerPage']);

        // Enqueue the QR scanner stylesheet.
        add_action('wp_enqueue_scripts', [$this, 'enqueueQRStyles']);

        // If the query variable is set, display the scanner page.
        add_action('template_redirect', [$this, 'maybeDisplayQRScanner']);
    }

    /**
     * Check if the current user has permission based on ticket validation roles.
     *
     * @return bool
     */
    private function userHasPermission(): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        $user = wp_get_current_user();
        foreach ($this->ticketValidationRoles as $role) {
            if (in_array($role, (array) $user->roles, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Enqueue the QR scanner stylesheet.
     *
     * @return void
     */
    public function enqueueQRStyles(): void {
        wp_enqueue_style('passifypro-qr', plugin_dir_url(__FILE__) . 'assets/css/qr.css');
    }

    /**
     * Renders the QR scanner page (used via shortcode).
     *
     * @return string
     */
    public function renderQRScannerPage(): string {
        if (!$this->userHasPermission()) {
            return '<div class="error-message">You do not have permission to access the QR Scanner.</div>';
        }

        ob_start();
        ?>
        <div class="qr-wrap">
            <h1>QR Scanner</h1>
            <div class="qr-card-container">
                <div class="qr-card">
                    <h3>Scan a QR Code</h3>
                    <p class="description">Use your device camera to scan the QR code from the ticket.</p>
                    <!-- Scanner UI placeholder (video element can be integrated with a JS library) -->
                    <div id="qr-scanner-container">
                        <video id="qr-video" width="300" height="200"></video>
                    </div>
                    <form id="qr-code-form" method="post" action="">
                        <?php wp_nonce_field('passifypro_qrscanner_action', 'passifypro_qrscanner_nonce'); ?>
                        <p>
                            <label for="qr_code_input">Or enter QR code manually:</label>
                            <input type="text" id="qr_code_input" name="qr_code_input" required style="width:300px;">
                        </p>
                        <button type="submit">Submit</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * If the query variable "passifypro_qrscanner" is set, display the QR scanner page.
     *
     * This enables the admin settings link (which points to ?passifypro_qrscanner=1) to show the scanner.
     *
     * @return void
     */
    public function maybeDisplayQRScanner(): void {
        if (isset($_GET['passifypro_qrscanner']) && $_GET['passifypro_qrscanner'] == '1') {
            echo do_shortcode('[passifypro_qrscanner]');
            exit;
        }
    }
}
