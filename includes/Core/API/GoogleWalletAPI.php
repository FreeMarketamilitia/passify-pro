<?php
namespace PassifyPro\Core\API;

use PassifyPro\Core\Security\SecurityHandler;
use PassifyPro\Core\Security\Debugger;
use Google_Client;
use Google_Service_Walletobjects;
use Google_Service_Walletobjects_EventTicketClass;
use Google_Service_Walletobjects_EventTicketObject;
use Google_Service_Exception;
use RuntimeException;
use InvalidArgumentException;

class GoogleWalletAPI {
    private SecurityHandler $securityHandler;
    private Debugger $debugger;
    private Google_Service_Walletobjects $walletService;
    private string $issuerId;
    private array $metadataMappings;
    private array $productCategories;

    public function __construct(SecurityHandler $securityHandler, Debugger $debugger) {
        $this->securityHandler = $securityHandler;
        $this->debugger = $debugger;
        // Pull the issuer ID from the admin settings.
        $this->issuerId = get_option('google_issuer_id', '');
        if (empty($this->issuerId)) {
            $this->debugger->logError("Issuer ID is not set in admin settings.");
        }
        $this->metadataMappings = get_option('metadata_fields', []);
        $this->productCategories = get_option('product_categories', []);
        $this->initializeGoogleClient();
    }

    /**
     * Initialize the Google Client using the decrypted service account JSON.
     * This method sets up the client with JWT authentication and creates
     * the Wallet Service instance.
     */
    private function initializeGoogleClient(): void {
        $serviceAccount = $this->securityHandler->getServiceAccountKeys();
        if (empty($serviceAccount)) {
            throw new RuntimeException('Google Wallet service account not configured.');
        }

        $client = new Google_Client();
        // Pass the service account credentials (decrypted JSON) to the client.
        $client->setAuthConfig($serviceAccount);
        $client->setScopes([Google_Service_Walletobjects::WALLET_OBJECT_ISSUER]);
        // Automatically fetch an access token using the JWT assertion flow.
        $token = $client->fetchAccessTokenWithAssertion();
        if (isset($token['error'])) {
            $this->debugger->logError("Google API Authentication Error", $token);
            throw new RuntimeException("Failed to authenticate with Google Wallet API.");
        }
        $this->walletService = new Google_Service_Walletobjects($client);
    }

    /**
     * Create a Google Wallet pass based on a purchase.
     *
     * @param int   $userId    The purchaser's user ID.
     * @param array $orderData WooCommerce order data including billing details and product category.
     * @return string URL to the created pass.
     */
    public function createPassForPurchase(int $userId, array $orderData): string {
        $productCategory = $this->getProductCategoryFromOrder($orderData);
        if (!$this->isValidProductCategory($productCategory)) {
            throw new InvalidArgumentException('Product category not enabled for pass generation.');
        }
        // Use WooCommerce order data for purchaser details with a fallback to user data.
        $purchaserData = $this->getPurchaserData($userId, $orderData);
        $mappedData = $this->mapFields($purchaserData, $orderData);

        $classId = $this->ensurePassClassExists($productCategory, $mappedData);
        return $this->createPassObject($userId, $classId, $mappedData);
    }

    /**
     * Combines WooCommerce order billing data with WordPress user data.
     * Uses order data for billing details if available; otherwise falls back to the user profile.
     *
     * @param int   $userId    The user ID.
     * @param array $orderData WooCommerce order data.
     * @return array The purchaser data including first name, last name, email, and phone.
     */
    private function getPurchaserData(int $userId, array $orderData): array {
        // Check for WooCommerce billing fields in the order data.
        $firstName = isset($orderData['billing_first_name']) && !empty($orderData['billing_first_name'])
            ? $orderData['billing_first_name']
            : null;
        $lastName = isset($orderData['billing_last_name']) && !empty($orderData['billing_last_name'])
            ? $orderData['billing_last_name']
            : null;
        $email = isset($orderData['billing_email']) && !empty($orderData['billing_email'])
            ? $orderData['billing_email']
            : null;
        $phone = isset($orderData['billing_phone']) && !empty($orderData['billing_phone'])
            ? $orderData['billing_phone']
            : null;

        // If any of the fields are missing, fall back to the WordPress user data.
        if (!$firstName || !$lastName || !$email) {
            $user = get_userdata($userId);
            $firstName = $firstName ?: $this->securityHandler->sanitizeString($user->first_name);
            $lastName = $lastName ?: $this->securityHandler->sanitizeString($user->last_name);
            $email = $email ?: $this->securityHandler->sanitizeEmail($user->user_email);
            $phone = $phone ?: $this->securityHandler->sanitizeString(get_user_meta($userId, 'billing_phone', true) ?? '');
        } else {
            // Sanitize order data fields
            $firstName = $this->securityHandler->sanitizeString($firstName);
            $lastName = $this->securityHandler->sanitizeString($lastName);
            $email = $this->securityHandler->sanitizeEmail($email);
            $phone = $this->securityHandler->sanitizeString($phone);
        }
        return [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $email,
            'phone'      => $phone
        ];
    }

    /**
     * Ensures that the pass class exists in Google Wallet. If not, creates it.
     *
     * @param string $category The product category.
     * @param array  $data     Mapped data for the pass.
     * @return string The pass class ID.
     */
    private function ensurePassClassExists(string $category, array $data): string {
        $classId = "{$this->issuerId}.{$category}";
        try {
            return $this->walletService->eventTicketClass->get($classId)->getId();
        } catch (Google_Service_Exception $e) {
            if ($e->getCode() !== 404) {
                $this->handleGoogleError($e);
            }
            return $this->createNewPassClass($classId, $data);
        }
    }

    /**
     * Creates a new pass class in Google Wallet.
     *
     * @param string $classId The new class ID.
     * @param array  $data    Data used to create the class.
     * @return string The new class ID.
     */
    private function createNewPassClass(string $classId, array $data): string {
        $class = new Google_Service_Walletobjects_EventTicketClass([
            'id' => $classId,
            'eventName' => [
                'defaultValue' => ['value' => $data['event_name'] ?: 'Default Event Name']
            ],
            'venue' => [
                'name' => [
                    'defaultValue' => ['value' => $data['venue_name'] ?: 'Default Venue']
                ]
            ],
            'dateTime' => $data['event_time'] ?: date('c'),
            'issuerName' => get_bloginfo('name')
        ]);
        try {
            return $this->walletService->eventTicketClass->insert($class)->getId();
        } catch (Google_Service_Exception $e) {
            $this->handleGoogleError($e);
        }
    }

    /**
     * Creates the pass object in Google Wallet and returns its secure URL.
     *
     * @param int    $userId  The purchaser's user ID.
     * @param string $classId The pass class ID.
     * @param array  $data    Mapped data for the pass.
     * @return string The secure URL for the created pass.
     */
    private function createPassObject(int $userId, string $classId, array $data): string {
        $objectId = "{$classId}.{$userId}." . uniqid();
        $passObject = new Google_Service_Walletobjects_EventTicketObject([
            'id' => $objectId,
            'classId' => $classId,
            'state' => 'ACTIVE',
            'ticketHolder' => [
                'firstName' => $data['first_name'],
                'lastName'  => $data['last_name'],
                'email'     => $data['email'],
                'phone'     => $data['phone'] ?? ''
            ],
            'ticketNumber' => $data['ticket_number'],
            'expirationDate' => $data['expiration_date'],
            'barcode' => [
                'type'  => 'QR_CODE',
                'value' => $data['ticket_number']
            ]
        ]);
        try {
            return $this->walletService->eventTicketObject->insert($passObject)->getSecureUrl();
        } catch (Google_Service_Exception $e) {
            $this->handleGoogleError($e);
        }
    }

    /**
     * Map fields from the order and purchaser data based on the admin-defined metadata mappings.
     * Provides fallback values for required fields if they are missing.
     *
     * @param array $purchaserData Purchaser data.
     * @param array $orderData     Order data.
     * @return array Mapped data.
     */
    private function mapFields(array $purchaserData, array $orderData): array {
        return [
            'event_name'     => $this->getMappedValue('class_id', $orderData) ?: 'Default Event',
            'venue_name'     => $this->getMappedValue('venue_name', $orderData) ?: 'Default Venue',
            'event_time'     => $this->formatDateTime($this->getMappedValue('event_time', $orderData) ?: time()),
            'ticket_number'  => $this->getMappedValue('ticket_number', $orderData) ?: bin2hex(random_bytes(8)),
            'expiration_date'=> $this->formatDateTime($this->getMappedValue('expiration_date', $orderData) ?: '+1 week'),
            'first_name'     => $purchaserData['first_name'],
            'last_name'      => $purchaserData['last_name'],
            'email'          => $purchaserData['email'],
            'phone'          => $purchaserData['phone'] ?? ''
        ];
    }

    /**
     * Returns the mapped value for a given field if defined.
     *
     * @param string $field The admin-defined key.
     * @param array  $data  The source data.
     * @return mixed|null The mapped value or null if not set.
     */
    private function getMappedValue(string $field, array $data) {
        $mappedField = $this->metadataMappings[$field] ?? null;
        return $mappedField ? ($data[$mappedField] ?? null) : null;
    }

    /**
     * Formats a date/time value to the required ISO 8601 format.
     *
     * @param mixed $input A UNIX timestamp or a date/time string.
     * @return string The formatted date/time string.
     */
    private function formatDateTime($input): string {
        return date('Y-m-d\TH:i:sP', is_numeric($input) ? $input : strtotime($input));
    }

    /**
     * Extracts the product category from the order data.
     *
     * @param array $orderData The order data.
     * @return string The product category.
     */
    private function getProductCategoryFromOrder(array $orderData): string {
        return $orderData['product_category'] ?? '';
    }

    /**
     * Checks if the product category is enabled for pass generation.
     *
     * @param string $category The product category.
     * @return bool True if valid; otherwise false.
     */
    private function isValidProductCategory(string $category): bool {
        return in_array($category, $this->productCategories);
    }

    /**
     * Handles errors from the Google Wallet API by logging and throwing an exception.
     *
     * @param \Google_Service_Exception $e The exception thrown by the Google API.
     */
    private function handleGoogleError(Google_Service_Exception $e): void {
        $error = json_decode($e->getMessage(), true);
        $message = $error['error']['message'] ?? 'Google Wallet API error';
        $code = $error['error']['code'] ?? $e->getCode();
        $this->debugger->logError("Google Wallet Error {$code}: {$message}");
        throw new RuntimeException("Google Wallet Error {$code}: {$message}", $code);
    }
}
