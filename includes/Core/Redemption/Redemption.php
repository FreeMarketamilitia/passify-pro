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

    /**
     * Redemption constructor.
     *
     * @param SecurityHandler   $securityHandler
     * @param Debugger          $debugger
     * @param GoogleWalletAPI   $googleWalletAPI
     */
    public function __construct(
        SecurityHandler $securityHandler,
        Debugger $debugger,
        GoogleWalletAPI $googleWalletAPI
    ) {
        $this->securityHandler   = $securityHandler;
        $this->debugger          = $debugger;
        $this->googleWalletAPI   = $googleWalletAPI;
        $this->ticketValidationRoles = get_option('ticket_validation_roles', []);

        // Register the shortcode for the redemption frontend.
        add_shortcode('passifypro_redemption', [$this, 'renderRedemptionPage']);

        // Process redemption form submission.
        add_action('init', [$this, 'handleRedemptionSubmission']);
    }

    /**
     * Checks if the current user has a role allowed to validate tickets.
     *
     * @return bool
     */
    private function userHasPermission(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        $user = wp_get_current_user();
        foreach ( $this->ticketValidationRoles as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Renders the redemption form frontend.
     *
     * This form is accessible only to users with the proper permissions.
     *
     * @return string
     */
    public function renderRedemptionPage(): string {
        ob_start();
        if ( ! $this->userHasPermission() ) {
            echo '<div class="passifypro-error">You do not have permission to redeem passes.</div>';
            return ob_get_clean();
        }
        ?>
        <div class="passifypro-redemption-form">
            <h2>Redeem Google Wallet Pass</h2>
            <?php if ( isset( $_GET['passifypro_redemption_message'] ) ) : ?>
                <div class="passifypro-message">
                    <?php echo esc_html( $_GET['passifypro_redemption_message'] ); ?>
                </div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field( 'passifypro_redemption_action', 'passifypro_redemption_nonce' ); ?>
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

    /**
     * Handles the redemption form submission.
     *
     * Checks nonce, user permission, sanitizes input, and processes the redemption.
     *
     * @return void
     */
    public function handleRedemptionSubmission(): void {
        if ( isset( $_POST['passifypro_redemption_nonce'] ) ) {
            // Verify the nonce.
            if ( ! wp_verify_nonce( $_POST['passifypro_redemption_nonce'], 'passifypro_redemption_action' ) ) {
                $this->debugger->logError( "Invalid redemption nonce." );
                return;
            }

            // Verify user permissions.
            if ( ! $this->userHasPermission() ) {
                $this->debugger->logError( "Unauthorized redemption attempt.", [ 'user_id' => get_current_user_id() ] );
                return;
            }

            // Sanitize the QR code input.
            $qrCode = isset( $_POST['qr_code'] ) ? $this->securityHandler->sanitizeString( $_POST['qr_code'] ) : '';
            if ( empty( $qrCode ) ) {
                $this->redirectWithMessage( "QR code cannot be empty." );
            }

            // Process the redemption.
            $message = $this->processRedemption( $qrCode );
            $this->redirectWithMessage( $message );
        }
    }

    /**
     * Processes the redemption of a pass.
     *
     * Verifies that the pass exists, is active, and that it has not already been redeemed.
     * Then, updates the pass state on Google’s backend to "REDEEMED".
     *
     * @param string $qrCode The QR code string (assumed to be the pass object ID).
     *
     * @return string Result message.
     */
    private function processRedemption( string $qrCode ): string {
        if ( $this->isPassRedeemed( $qrCode ) ) {
            return "This pass has already been redeemed.";
        }

        try {
            // Retrieve the pass object via the GoogleWalletAPI.
            // (Assumes that getPassObject() is a public method in GoogleWalletAPI that
            // calls $this->walletService->eventTicketObject->get($qrCode).)
            $pass = $this->googleWalletAPI->getPassObject( $qrCode );

            // Check if the pass is in an active state.
            if ( isset( $pass->state ) && $pass->state !== 'ACTIVE' ) {
                return "This pass is not active and cannot be redeemed.";
            }

            // Prepare the patch data to update the pass state to "REDEEMED".
            $patchData = new \Google_Service_Walletobjects_EventTicketObject([
                'state' => 'REDEEMED'
            ]);

            // Update the pass on Google’s backend.
            $this->googleWalletAPI->updatePassObject( $qrCode, $patchData );

            // Mark the pass as redeemed locally.
            $this->markPassRedeemed( $qrCode );
            $this->debugger->logOperation( "Pass redeemed successfully.", "Redemption" );

            return "Pass redeemed successfully.";
        } catch ( \Exception $e ) {
            $this->debugger->logError( "Redemption failed", [ 'error' => $e->getMessage() ] );
            return "Redemption failed: " . $e->getMessage();
        }
    }

    /**
     * Checks if a pass (by object ID) has already been redeemed.
     *
     * @param string $objectId
     *
     * @return bool
     */
    private function isPassRedeemed( string $objectId ): bool {
        $redeemedPasses = get_option( 'passifypro_redeemed_passes', [] );
        return in_array( $objectId, $redeemedPasses, true );
    }

    /**
     * Marks a pass (by object ID) as redeemed locally.
     *
     * @param string $objectId
     *
     * @return void
     */
    private function markPassRedeemed( string $objectId ): void {
        $redeemedPasses = get_option( 'passifypro_redeemed_passes', [] );
        if ( ! in_array( $objectId, $redeemedPasses, true ) ) {
            $redeemedPasses[] = $objectId;
            update_option( 'passifypro_redeemed_passes', $redeemedPasses );
        }
    }

    /**
     * Redirects to the referring page (or home) with a message in the URL.
     *
     * @param string $message
     *
     * @return void
     */
    private function redirectWithMessage( string $message ): void {
        $redirectUrl = add_query_arg(
            'passifypro_redemption_message',
            urlencode( $message ),
            wp_get_referer() ?: home_url()
        );
        wp_safe_redirect( $redirectUrl );
        exit;
    }
}
