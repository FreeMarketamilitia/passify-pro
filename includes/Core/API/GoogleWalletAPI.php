<?php
namespace PassifyPro\Core\API;

use PassifyPro\Core\Security\SecurityHandler;
use PassifyPro\Core\Security\Debugger;
use Google_Client;
use Google_Service_Walletobjects;
use Google_Service_Walletobjects_EventTicketClass;
use Google_Service_Walletobjects_EventTicketObject;
use Google_Service_Walletobjects_RotatingBarcode;
use Google_Service_Exception;
use RuntimeException;
use InvalidArgumentException;

class GoogleWalletAPI {
    private SecurityHandler $securityHandler;
    private Debugger $debugger;
    private ?Google_Service_Walletobjects $walletService = null;
    private string $issuerId;
    private string $classId;
    private array $metadataMappings;
    private array $productCategories;

    public function __construct(SecurityHandler $securityHandler, Debugger $debugger) {
        $this->securityHandler = $securityHandler;
        $this->debugger = $debugger;

        $this->issuerId = (string) get_option('google_issuer_id', '');
        if (empty($this->issuerId)) {
            $this->debugger->logError("Issuer ID is not set in admin settings.", ['component' => "GoogleWalletAPI"]);
        }

        $this->classId = (string) get_option('google_class_id', '');
        if (empty($this->classId)) {
            $this->debugger->logError("Class ID is not set in admin settings.", ['component' => "GoogleWalletAPI"]);
        }

        $metadataFields = get_option('metadata_fields', []);
        $this->metadataMappings = is_array($metadataFields) ? $metadataFields : [];
        if (!is_array($metadataFields)) {
            $this->debugger->logError("metadata_fields option is not an array; defaulted to empty array.", ['component' => "GoogleWalletAPI"]);
        }

        $productCats = get_option('product_categories', []);
        $this->productCategories = is_array($productCats) ? array_map('absint', $productCats) : [];
        if (!is_array($productCats)) {
            $this->debugger->logError("product_categories option is not an array; defaulted to empty array.", ['component' => "GoogleWalletAPI"]);
        }

        $this->debugger->logOperation("GoogleWalletAPI initialized", "GoogleWalletAPI", [
            'issuerId' => $this->issuerId,
            'classId' => $this->classId,
            'productCategories' => $this->productCategories
        ]);
    }

    private function getWalletService(): Google_Service_Walletobjects {
        if ($this->walletService === null) {
            $this->initializeGoogleClient();
        }
        return $this->walletService;
    }

    private function initializeGoogleClient(): void {
        $this->debugger->logOperation("Initializing Google Client", "GoogleWalletAPI");
        $serviceAccount = $this->securityHandler->getServiceAccountKeys();
        if (empty($serviceAccount)) {
            $this->debugger->logError("Google Wallet service account not configured.", ['component' => "GoogleWalletAPI"]);
            throw new RuntimeException('Google Wallet service account not configured.');
        }

        $client = new Google_Client();
        $client->setAuthConfig($serviceAccount);
        $client->setScopes([Google_Service_Walletobjects::WALLET_OBJECT_ISSUER]);
        $token = $client->fetchAccessTokenWithAssertion();
        if (isset($token['error'])) {
            $this->debugger->logError("Google API Authentication Error", ['component' => "GoogleWalletAPI", 'error' => $token]);
            throw new RuntimeException("Failed to authenticate with Google Wallet API.");
        }
        $this->walletService = new Google_Service_Walletobjects($client);
        $this->debugger->logOperation("Google Client initialized successfully", "GoogleWalletAPI");
    }

    public function createPassForPurchase(int $userId, array $orderData): array {
        $this->debugger->logOperation("Starting pass creation for order {$orderData['order_id']}", "GoogleWalletAPI", $orderData);
        $productCategory = $this->getProductCategoryFromOrder($orderData);
        if (!$this->isValidProductCategory($productCategory)) {
            $this->debugger->logOperation("Skipping pass generation for category: $productCategory - not in selected categories.", "GoogleWalletAPI", ['categories' => $this->productCategories]);
            return ['object_id' => null, 'save_link' => null];
        }

        $purchaserData = $this->getPurchaserData($userId, $orderData);
        $mappedData = $this->mapFields($purchaserData, $orderData);

        $this->debugger->logOperation("Mapped data for pass", "GoogleWalletAPI", $mappedData);

        $classId = $this->ensurePassClassExists($mappedData);
        $passData = $this->createPassObject($userId, $classId, $mappedData);

        if (isset($orderData['order_id'])) {
            update_post_meta($orderData['order_id'], 'pass_object_id', $passData['object_id']);
            update_post_meta($orderData['order_id'], 'pass_ticket_number', $mappedData['ticket_number']);
            $this->debugger->logOperation("Updated order metadata with pass data", "GoogleWalletAPI", ['order_id' => $orderData['order_id'], 'pass_data' => $passData]);
        }
        $this->debugger->logOperation("Pass created successfully for order {$orderData['order_id']}", "GoogleWalletAPI", $passData);
        return $passData;
    }

    public function getPassObject(string $objectId): Google_Service_Walletobjects_EventTicketObject {
        try {
            $this->debugger->logOperation("Fetching pass object $objectId", "GoogleWalletAPI");
            return $this->getWalletService()->eventTicketObject->get($objectId);
        } catch (Google_Service_Exception $e) {
            $this->handleGoogleError($e);
        }
    }

    public function updatePassObject(string $objectId, Google_Service_Walletobjects_EventTicketObject $patchData): void {
        try {
            $this->debugger->logOperation("Updating pass object $objectId", "GoogleWalletAPI");
            $this->getWalletService()->eventTicketObject->patch($objectId, $patchData);
            $this->debugger->logOperation("Pass object $objectId updated", "GoogleWalletAPI");
        } catch (Google_Service_Exception $e) {
            $this->handleGoogleError($e);
        }
    }

    private function getPurchaserData(int $userId, array $orderData): array {
        $firstName = $orderData['billing_first_name'] ?? null;
        $lastName = $orderData['billing_last_name'] ?? null;
        $email = $orderData['billing_email'] ?? null;
        $phone = $orderData['billing_phone'] ?? null;

        if (!$firstName || !$lastName || !$email) {
            $user = get_userdata($userId);
            $firstName = $firstName ?: $this->securityHandler->sanitizeString($user->first_name);
            $lastName = $lastName ?: $this->securityHandler->sanitizeString($user->last_name);
            $email = $email ?: $this->securityHandler->sanitizeEmail($user->user_email);
            $phone = $phone ?: $this->securityHandler->sanitizeString(get_user_meta($userId, 'billing_phone', true) ?? '');
        } else {
            $firstName = $this->securityHandler->sanitizeString($firstName);
            $lastName = $this->securityHandler->sanitizeString($lastName);
            $email = $this->securityHandler->sanitizeEmail($email);
            $phone = $this->securityHandler->sanitizeString($phone);
        }
        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone
        ];
    }

    private function ensurePassClassExists(array $data): string {
        $classId = $this->classId;
        try {
            $this->debugger->logOperation("Checking if class $classId exists", "GoogleWalletAPI");
            return $this->getWalletService()->eventTicketClass->get($classId)->getId();
        } catch (Google_Service_Exception $e) {
            if ($e->getCode() !== 404) {
                $this->handleGoogleError($e);
            }
            $this->debugger->logOperation("Class $classId not found, creating new", "GoogleWalletAPI");
            return $this->createNewPassClass($classId, $data);
        }
    }

    private function createNewPassClass(string $classId, array $data): string {
        $class = new Google_Service_Walletobjects_EventTicketClass([
            'id' => $classId,
            'issuerId' => $this->issuerId,
            'eventName' => [
                'defaultValue' => ['value' => $data['event_name'] ?: 'Default Event Name']
            ],
            'venue' => [
                'name' => [
                    'defaultValue' => ['value' => $data['venue_name'] ?: 'Default Venue']
                ]
            ],
            'dateTime' => $data['event_time'] ? $this->formatDateTime($data['event_time']) : date('c'),
            'issuerName' => get_bloginfo('name')
        ]);
        try {
            $this->debugger->logOperation("Creating new pass class $classId", "GoogleWalletAPI");
            return $this->getWalletService()->eventTicketClass->insert($class)->getId();
        } catch (Google_Service_Exception $e) {
            $this->handleGoogleError($e);
        }
    }

    private function createPassObject(int $userId, string $classId, array $data): array {
        $objectId = "{$classId}.{$userId}." . uniqid();
        $rotatingBarcode = new Google_Service_Walletobjects_RotatingBarcode([
            'type' => 'QR_CODE',
            'renderEncoding' => 'UTF_8',
            'value' => $data['ticket_number'],
            'totpDetails' => [
                'periodMillis' => 300000,
                'algorithm' => 'TOTP_SHA1',
                'parameters' => [
                    [
                        'key' => 'secret_key_' . $this->issuerId,
                        'valueLength' => 10
                    ]
                ]
            ]
        ]);

        $passObject = new Google_Service_Walletobjects_EventTicketObject([
            'id' => $objectId,
            'classId' => $classId,
            'state' => 'ACTIVE',
            'ticketHolder' => [
                'firstName' => $data['first_name'],
                'lastName' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? ''
            ],
            'ticketNumber' => $data['ticket_number'],
            'expirationDate' => $data['expiration_date'],
            'rotatingBarcode' => $rotatingBarcode
        ]);

        try {
            $this->debugger->logOperation("Inserting pass object $objectId", "GoogleWalletAPI");
            $insertedObject = $this->getWalletService()->eventTicketObject->insert($passObject);
            $saveLink = $this->generateSaveLink($insertedObject->getId());
            return [
                'object_id' => $insertedObject->getId(),
                'save_link' => $saveLink
            ];
        } catch (Google_Service_Exception $e) {
            $this->handleGoogleError($e);
        }
    }

    private function generateSaveLink(string $objectId): string {
        $this->debugger->logOperation("Generating save link for object $objectId", "GoogleWalletAPI");
        $serviceAccount = $this->securityHandler->getServiceAccountKeys();
        if (empty($serviceAccount)) {
            $this->debugger->logError("Service account keys not available for JWT generation.", ['component' => "GoogleWalletAPI"]);
            throw new RuntimeException('Service account keys not available.');
        }
        $clientEmail = $serviceAccount['client_email'];
        $privateKey = $serviceAccount['private_key'];

        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = json_encode([
            'iss' => $clientEmail,
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'origins' => [home_url()],
            'payload' => [
                'eventTicketObjects' => [
                    ['id' => $objectId]
                ]
            ]
        ]);
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        $unsignedJwt = $base64Header . '.' . $base64Payload;
        openssl_sign($unsignedJwt, $signature, $privateKey, 'SHA256');
        $jwt = $unsignedJwt . '.' . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        $this->debugger->logOperation("Save link generated", "GoogleWalletAPI", ['link' => "https://pay.google.com/gp/v/save/{$jwt}"]);
        return "https://pay.google.com/gp/v/save/{$jwt}";
    }

    private function mapFields(array $purchaserData, array $orderData): array {
        $ticketNumber = $this->getMappedValue('ticket_number', $orderData);
        if ($ticketNumber === null && isset($orderData['order_id'])) {
            $ticketNumber = '#' . $orderData['order_id'];
        }

        $mapped = [
            'event_name' => $this->getMappedValue('class_id', $orderData) ?: 'Default Event',
            'venue_name' => $this->getMappedValue('venue_name', $orderData) ?: 'Default Venue',
            'event_time' => $this->getMappedValue('event_time', $orderData) ? $this->formatDateTime($this->getMappedValue('event_time', $orderData)) : date('c'),
            'ticket_number' => $ticketNumber ?: 'N/A',
            'expiration_date' => $this->getMappedValue('expiration_date', $orderData) ? $this->formatDateTime($this->getMappedValue('expiration_date', $orderData)) : date('c', strtotime('+1 month')),
            'first_name' => $purchaserData['first_name'],
            'last_name' => $purchaserData['last_name'],
            'email' => $purchaserData['email'],
            'phone' => $purchaserData['phone'] ?? ''
        ];
        $this->debugger->logOperation("Fields mapped for pass", "GoogleWalletAPI", $mapped);
        return $mapped;
    }

    private function getMappedValue(string $field, array $data) {
        $mappedField = $this->metadataMappings[$field] ?? null;
        return $mappedField ? ($data[$mappedField] ?? null) : null;
    }

    private function formatDateTime($input): string {
        return date('Y-m-d\TH:i:sP', is_numeric($input) ? $input : strtotime($input));
    }

    private function getProductCategoryFromOrder(array $orderData): string {
        return $orderData['product_category'] ?? '';
    }

    private function isValidProductCategory(string $category): bool {
        $term = get_term_by('slug', $category, 'product_cat') ?: get_term_by('name', $category, 'product_cat');
        $categoryId = $term ? (int) $term->term_id : 0;
        $isValid = in_array($categoryId, $this->productCategories);
        $this->debugger->logOperation("Checking category validity: $category (ID: $categoryId)", "GoogleWalletAPI", ['isValid' => $isValid, 'allowedCategories' => $this->productCategories]);
        return $isValid;
    }

    private function handleGoogleError(Google_Service_Exception $e): void {
        $error = json_decode($e->getMessage(), true);
        $message = $error['error']['message'] ?? 'Google Wallet API error';
        $code = $error['error']['code'] ?? $e->getCode();
        $this->debugger->logError("Google Wallet Error {$code}: {$message}", ['component' => "GoogleWalletAPI"]);
        throw new RuntimeException("Google Wallet Error {$code}: {$message}", $code);
    }
}