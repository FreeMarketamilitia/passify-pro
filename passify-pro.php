<?php
/*
Plugin Name: Passify Pro
Plugin URI:  https://example.com/passify-pro
Description: A WooCommerce plugin for generating and redeeming Google Wallet passes.
Version:     1.0.0
Author:      Your Name
Author URI:  https://example.com
License:     GPL2
Text Domain: passify-pro
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Load Composer autoloader if it exists
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Manually require core classes
require_once __DIR__ . '/includes/Core/Security/Debugger.php';
require_once __DIR__ . '/includes/Core/Security/SecurityHandler.php';
require_once __DIR__ . '/includes/Core/Admin/Admin.php';
require_once __DIR__ . '/includes/Core/Redemption/Redemption.php';
require_once __DIR__ . '/includes/Core/QR/QRScanner.php';
require_once __DIR__ . '/includes/Core/API/GoogleWalletAPI.php';
require_once __DIR__ . '/includes/Core/Email/Emails.php'; // Added Emails.php

/**
 * Main Passify Pro Plugin Class.
 * Initializes all components of the plugin using the singleton pattern.
 */
final class PassifyPro {

    /** @var PassifyPro */
    private static $instance;

    /** @var \PassifyPro\Core\Security\SecurityHandler */
    private $securityHandler;

    /** @var \PassifyPro\Core\Security\Debugger */
    private $debugger;

    /** @var \PassifyPro\Core\API\GoogleWalletAPI */
    private $googleWalletAPI;

    /** @var \PassifyPro\Core\Admin\AdminSettingsPage */
    private $adminSettingsPage;

    /** @var \PassifyPro\Core\Redemption\Redemption */
    private $redemption;

    /** @var \PassifyPro\Core\QR\QRScanner */
    private $qrScanner;

    /** @var \PassifyPro\Core\Email\Emails */
    private $emails;

    /**
     * Private constructor to enforce singleton pattern.
     */
    private function __construct() {
        // Instantiate dependencies
        $this->debugger = new \PassifyPro\Core\Security\Debugger();
        $this->securityHandler = new \PassifyPro\Core\Security\SecurityHandler($this->debugger);
        $this->googleWalletAPI = new \PassifyPro\Core\API\GoogleWalletAPI($this->securityHandler, $this->debugger);

        // Initialize components
        $this->adminSettingsPage = new \PassifyPro\Core\Admin\AdminSettingsPage($this->securityHandler, $this->debugger);
        $this->redemption = new \PassifyPro\Core\Redemption\Redemption($this->securityHandler, $this->debugger, $this->googleWalletAPI);
        $this->qrScanner = new \PassifyPro\Core\QR\QRScanner($this->securityHandler, $this->debugger);
        $this->emails = new \PassifyPro\Core\Email\Emails($this->securityHandler, $this->debugger, $this->googleWalletAPI);

        // Load text domain for translations
        add_action('plugins_loaded', [$this, 'loadTextDomain']);
    }

    /**
     * Returns the single instance of the plugin.
     *
     * @return PassifyPro
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load plugin text domain for translations.
     */
    public function loadTextDomain() {
        load_plugin_textdomain('passify-pro', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
}

// Initialize the plugin
function passifypro() {
    return PassifyPro::instance();
}
passifypro();