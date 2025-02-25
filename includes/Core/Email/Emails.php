<?php
namespace PassifyPro\Core\Email;

use PassifyPro\Core\Security\SecurityHandler;
use PassifyPro\Core\Security\Debugger;
use PassifyPro\Core\API\GoogleWalletAPI;

class Emails {
    private SecurityHandler $securityHandler;
    private Debugger $debugger;
    private GoogleWalletAPI $googleWalletAPI;

    public function __construct(SecurityHandler $securityHandler, Debugger $debugger, GoogleWalletAPI $googleWalletAPI) {
        $this->securityHandler = $securityHandler;
        $this->debugger = $debugger;
        $this->googleWalletAPI = $googleWalletAPI;

        // Hook into WooCommerce order status change to completed
        add_action('woocommerce_order_status_completed', [$this, 'sendAddToWalletEmail'], 10, 1);
    }

    public function sendAddToWalletEmail($order_id) {
        $this->debugger->logOperation("Attempting to send Add to Wallet email for order $order_id", "Emails");
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->debugger->logError("Order $order_id not found for Add to Wallet email.", ['component' => "Emails"]);
            return;
        }

        $user_id = $order->get_user_id();
        if (!$user_id) {
            $this->debugger->logError("No user ID found for order $order_id.", ['component' => "Emails"]);
            return;
        }

        // Get product category from the first item
        $items = $order->get_items();
        $product_category = '';
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $categories = get_the_terms($product_id, 'product_cat');
            if ($categories && !is_wp_error($categories)) {
                $product_category = $categories[0]->slug;
                $this->debugger->logOperation("Found product category: $product_category for product ID $product_id", "Emails");
            } else {
                $this->debugger->logOperation("No categories found for product ID $product_id", "Emails");
            }
            break; // Only first item for simplicity
        }

        // Prepare order data for pass generation
        $order_data = [
            'order_id' => $order_id,
            'product_category' => $product_category,
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name' => $order->get_billing_last_name(),
            'billing_email' => $order->get_billing_email(),
            'billing_phone' => $order->get_billing_phone(),
            'event_name' => get_post_meta($order_id, 'event_name', true),
            'venue_name' => get_post_meta($order_id, 'venue_name', true),
            'event_time' => get_post_meta($order_id, 'event_time', true),
            'expiration_field' => get_post_meta($order_id, 'expiration_field', true),
            'order_number' => $order->get_order_number()
        ];

        // Debug: Log order data
        $this->debugger->logOperation("Order data prepared for pass generation", "Emails", $order_data);

        // Generate the pass
        $pass_data = $this->googleWalletAPI->createPassForPurchase($user_id, $order_data);

        if (!$pass_data['save_link']) {
            $this->debugger->logError("No Add to Wallet link generated for order $order_id.", ['component' => "Emails", 'pass_data' => $pass_data]);
            return;
        }

        // Email the link
        $to = $order->get_billing_email();
        $subject = 'Your Google Wallet Ticket for Order #' . $order->get_order_number();
        $message = "Thank you for your purchase! Add your ticket to Google Wallet using the link below:<br><br>";
        $message .= "<a href='{$pass_data['save_link']}'>Add to Google Wallet</a><br><br>";
        $message .= "Order Number: {$order->get_order_number()}<br>";
        $message .= "Event: " . (get_post_meta($order_id, 'event_name', true) ?: 'N/A') . "<br>";
        $message .= "Venue: " . (get_post_meta($order_id, 'venue_name', true) ?: 'N/A') . "<br>";
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $this->debugger->logOperation("Sending email to $to with subject: $subject", "Emails");
        if (wp_mail($to, $subject, $message, $headers)) {
            $this->debugger->logOperation("Add to Wallet email sent successfully for order $order_id.", "Emails");
        } else {
            $this->debugger->logError("Failed to send Add to Wallet email for order $order_id.", ['component' => "Emails"]);
        }
    }
}