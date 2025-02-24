<?php
namespace PassifyPro\Core\Email;

use PassifyPro\Core\API\GoogleWalletAPI;
use PassifyPro\Core\Security\SecurityHandler;
use PassifyPro\Core\Security\Debugger;

class Emails {
    private SecurityHandler $securityHandler;
    private Debugger $debugger;
    private GoogleWalletAPI $googleWalletAPI;

    public function __construct(SecurityHandler $securityHandler, Debugger $debugger, GoogleWalletAPI $googleWalletAPI) {
        $this->securityHandler = $securityHandler;
        $this->debugger = $debugger;
        $this->googleWalletAPI = $googleWalletAPI;

        // Hook into WooCommerce order completion to send the email
        add_action('woocommerce_order_status_completed', [$this, 'sendTicketEmail'], 10, 1);
    }

    /**
     * Sends the Google Wallet ticket email when an order is completed.
     *
     * @param int $orderId The WooCommerce order ID.
     */
    public function sendTicketEmail(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!$order) {
            $this->debugger->logError("Failed to retrieve order for ID: $orderId");
            return;
        }

        try {
            // Prepare order data for pass creation
            $userId = $order->get_user_id() ?: 1; // Default to admin user if no user ID
            $orderData = [
                'order_id' => $orderId,
                'product_category' => $this->getOrderProductCategory($order),
                'billing_first_name' => $order->get_billing_first_name(),
                'billing_last_name' => $order->get_billing_last_name(),
                'billing_email' => $order->get_billing_email(),
                'billing_phone' => $order->get_billing_phone(),
                // Add custom meta fields as mapped in admin settings
                'order_title' => get_post_meta($orderId, 'order_title', true),
                'billing_address' => $order->get_billing_address_1(),
                'event_date' => get_post_meta($orderId, 'event_date', true),
                '_order_key' => $order->get_order_key(),
                'expiration_field' => get_post_meta($orderId, 'expiration_field', true)
            ];

            // Generate the Google Wallet pass
            $passData = $this->googleWalletAPI->createPassForPurchase($userId, $orderData);
            $saveLink = $passData['save_link'];

            // Send the email
            $this->sendEmail($order, $saveLink);
            $this->debugger->logOperation("Ticket email sent for order $orderId", "Emails");
        } catch (\Exception $e) {
            $this->debugger->logError("Failed to send ticket email for order $orderId: " . $e->getMessage());
        }
    }

    /**
     * Retrieves the product category slug from the order.
     *
     * @param WC_Order $order The WooCommerce order object.
     * @return string The category slug, or empty string if none found.
     */
    private function getOrderProductCategory($order): string {
        $items = $order->get_items();
        foreach ($items as $item) {
            $product = $item->get_product();
            $terms = get_the_terms($product->get_id(), 'product_cat');
            if ($terms && !is_wp_error($terms)) {
                return $terms[0]->slug; // Return the first category slug
            }
        }
        return '';
    }

    /**
     * Sends the email with the Google Wallet ticket link.
     *
     * @param WC_Order $order The WooCommerce order object.
     * @param string $saveLink The Google Wallet save link.
     */
    private function sendEmail($order, string $saveLink): void {
        $to = $this->securityHandler->sanitizeEmail($order->get_billing_email());
        $subject = sprintf('Your Google Wallet Ticket for Order #%s', $order->get_order_number());
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        // Load email template
        $templatePath = plugin_dir_path(__DIR__) . 'templates/email-ticket.php';
        if (!file_exists($templatePath)) {
            $this->debugger->logError("Email template not found at: $templatePath");
            return;
        }

        ob_start();
        include $templatePath;
        $message = ob_get_clean();

        // Replace placeholders in the template
        $replacements = [
            '{{first_name}}' => $order->get_billing_first_name(),
            '{{order_number}}' => $order->get_order_number(),
            '{{save_link}}' => esc_url($saveLink),
            '{{event_name}}' => get_post_meta($order->get_id(), 'order_title', true) ?: 'Your Event',
            '{{event_date}}' => get_post_meta($order->get_id(), 'event_date', true) ?: 'TBD'
        ];
        $message = str_replace(array_keys($replacements), array_values($replacements), $message);

        // Send the email
        if (!wp_mail($to, $subject, $message, $headers)) {
            $this->debugger->logError("Failed to send email to $to for order " . $order->get_id());
        }
    }
}