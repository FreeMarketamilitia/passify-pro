<?php
namespace PassifyPro\Core\Redemption;

use PassifyPro\Core\API\GoogleWalletAPI;
use PassifyPro\Core\Security\SecurityHandler;
use PassifyPro\Core\Security\Debugger;

/**
 * Class Redemption
 *
 * Handles the redemption of Google Wallet passes.
 * Ensures that only users with the ticket validation roles can redeem passes.
 * Provides a frontend shortcode for employees to scan/enter QR codes.
 */
class Redemption {

    private SecurityHandler $securityHandler;
    private Debugger $debugger;
    private GoogleWalletAPI $googleWalletAPI;
    /** @var array Ticket validation roles allowed to redeem passes */
    private array $ticketValidationRoles;

    public function __construct(
        SecurityHandler $securityHandler,
        Debugger $debugger,
        GoogleWalletAPI $googleWalletAPI
    ) {
        $this->securityHandler   = $securityHandler;
        $this->debugger          = $debugger;
        $this->googleWalletAPI   = $googleWalletAPI;
        $this->ticketValidationRoles = get_option('ticket_validation_roles', []);

        add_shortcode('passifypro_redemption', [$this, 'renderRedemptionPage']);
        add_action('init', [$this, 'handleRedemptionSubmission']);
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

    public function renderRedemptionPage(): string {
        ob_start();
        if (!$this->userHasPermission()) {
            echo '<div class="passifypro-error">You do not have permission to redeem passes.</div>';
            return ob_get_clean();
        }
        ?>
        <div class="passifypro-redemption-form">
            <h2>Redeem Google Wallet Pass</h2>
            <?php if (isset($_GET['passifypro_redemption_message'])) : ?>
                <div class="passifypro-message">
                    <?php echo esc_html($_GET['passifypro_redemption_message']); ?>
                </div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('passifypro_redemption_action', 'passifypro_redemption_nonce'); ?>
                <p>
                    <label for="qr_code">QR Code:</label>
                    <input type="text" id="qr_code" name="qr_code" required style="width:300px;">
                </p>
                <p>
                    <input type="submit" value="Redeem">
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handleRedemptionSubmission(): void {
        if (isset($_POST['passifypro_redemption_nonce'])) {
            if (!wp_verify_nonce($_POST['passifypro_redemption_nonce'], 'passifypro_redemption_action')) {
                $this->debugger->logError("Invalid redemption nonce.");
                return;
            }

            if (!$this->userHasPermission()) {
                $this->debugger->logError("Unauthorized redemption attempt.", ['user_id' => get_current_user_id()]);
                return;
            }

            $qrCode = isset($_POST['qr_code']) ? $this->securityHandler->sanitizeString($_POST['qr_code']) : '';
            if (empty($qrCode)) {
                $this->redirectWithMessage("QR code cannot be empty.");
            }

            $message = $this->processRedemption($qrCode);
            $this->redirectWithMessage($message);
        }
    }

    /**
     * Processes the redemption of a pass using the ticket number from the QR code.
     *
     * @param string $ticketNumber The ticket number from the QR code.
     * @return string Result message.
     */
    private function processRedemption(string $ticketNumber): string {
        // Find the order with this ticket number
        $orders = get_posts([
            'post_type' => 'shop_order',
            'meta_key' => 'pass_ticket_number',
            'meta_value' => $ticketNumber,
            'posts_per_page' => 1,
        ]);

        if (empty($orders)) {
            return "No pass found for this ticket number.";
        }

        $orderId = $orders[0]->ID;
        $objectId = get_post_meta($orderId, 'pass_object_id', true);

        if (empty($objectId)) {
            return "Pass object ID not found for this ticket.";
        }

        if ($this->isPassRedeemed($objectId)) {
            return "This pass has already been redeemed.";
        }

        try {
            $pass = $this->googleWalletAPI->getPassObject($objectId);
            if (isset($pass->state) && $pass->state !== 'ACTIVE') {
                return "This pass is not active and cannot be redeemed.";
            }

            $patchData = new \Google_Service_Walletobjects_EventTicketObject([
                'state' => 'REDEEMED'
            ]);
            $this->googleWalletAPI->updatePassObject($objectId, $patchData);
            $this->markPassRedeemed($objectId);
            $this->debugger->logOperation("Pass redeemed successfully.", "Redemption");
            return "Pass redeemed successfully.";
        } catch (\Exception $e) {
            $this->debugger->logError("Redemption failed", ['error' => $e->getMessage()]);
            return "Redemption failed: " . $e->getMessage();
        }
    }

    private function isPassRedeemed(string $objectId): bool {
        $redeemedPasses = get_option('passifypro_redeemed_passes', []);
        return in_array($objectId, $redeemedPasses, true);
    }

    private function markPassRedeemed(string $objectId): void {
        $redeemedPasses = get_option('passifypro_redeemed_passes', []);
        if (!in_array($objectId, $redeemedPasses, true)) {
            $redeemedPasses[] = $objectId;
            update_option('passifypro_redeemed_passes', $redeemedPasses);
        }
    }

    private function redirectWithMessage(string $message): void {
        $redirectUrl = add_query_arg(
            'passifypro_redemption_message',
            urlencode($message),
            wp_get_referer() ?: home_url()
        );
        wp_safe_redirect($redirectUrl);
        exit;
    }
}