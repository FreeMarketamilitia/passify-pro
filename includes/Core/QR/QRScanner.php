<?php
namespace PassifyPro\Core\QR;

use PassifyPro\Core\Security\SecurityHandler;
use PassifyPro\Core\Security\Debugger;

class QRScanner {

    private SecurityHandler $securityHandler;
    private Debugger $debugger;
    private array $ticketValidationRoles;

    public function __construct(SecurityHandler $securityHandler, Debugger $debugger) {
        $this->securityHandler = $securityHandler;
        $this->debugger = $debugger;
        $this->ticketValidationRoles = get_option('ticket_validation_roles', []);

        add_shortcode('passifypro_qrscanner', [$this, 'renderQRScannerPage']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueQRStyles']);
        add_action('template_redirect', [$this, 'maybeDisplayQRScanner']);
    }

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

    public function enqueueQRStyles(): void {
        wp_enqueue_style('passifypro-qr', plugin_dir_url(__FILE__) . 'assets/css/qr.css');
    }

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
                    <div id="qr-scanner-container">
                        <video id="qr-video" width="300" height="200"></video>
                    </div>
                    <form id="qr-code-form" method="post" action="">
                        <?php wp_nonce_field('passifypro_redemption_action', 'passifypro_redemption_nonce'); ?>
                        <p>
                            <label for="qr_code">Or enter QR code manually:</label>
                            <input type="text" id="qr_code" name="qr_code" required style="width:300px;">
                        </p>
                        <button type="submit">Submit</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function maybeDisplayQRScanner(): void {
        if (isset($_GET['passifypro_qrscanner']) && $_GET['passifypro_qrscanner'] == '1') {
            echo do_shortcode('[passifypro_qrscanner]');
            exit;
        }
    }
}