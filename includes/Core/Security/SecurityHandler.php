<?php
namespace PassifyPro\Core\Security;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use HTMLPurifier;
use HTMLPurifier_Config;
use RuntimeException;
use Throwable;
use PassifyPro\Core\Security\Debugger;

class SecurityHandler
{
    private Debugger $debugger;
    private Key $encryptionKey;
    private HTMLPurifier $purifier;
    private string $keyPath;
    private ?array $serviceAccountKeys = null;

    public function __construct(Debugger $debugger)
    {
        $this->debugger = $debugger;
        $this->keyPath = plugin_dir_path(__DIR__) . 'Security/keys/';
        $this->debugger->logOperation("SecurityHandler constructed", "SecurityHandler", ['keyPath' => $this->keyPath]);
        $this->initializeKeyStorage();
        $this->initializeEncryption();
        $this->initializeSanitizer();
        $this->loadServiceAccountKeys();
    }

    private function initializeKeyStorage(): void
    {
        try {
            if (!file_exists($this->keyPath) && !wp_mkdir_p($this->keyPath)) {
                throw new RuntimeException("Failed to create key directory: {$this->keyPath}");
            }
        } catch (Throwable $e) {
            $this->debugger->logError("Key Storage Error", ['exception' => $e->getMessage()]);
        }
    }

    private function initializeEncryption(): void
    {
        try {
            $keyFile = $this->keyPath . 'encryption.key';
            if (!file_exists($keyFile)) {
                $key = Key::createNewRandomKey();
                file_put_contents($keyFile, $key->saveToAsciiSafeString());
                chmod($keyFile, 0600);
                $this->debugger->logSecurityEvent('New encryption key generated');
            }
            $this->encryptionKey = Key::loadFromAsciiSafeString(file_get_contents($keyFile));
        } catch (Throwable $e) {
            $this->debugger->logError("Encryption Initialization Error", ['exception' => $e->getMessage()]);
        }
    }

    private function initializeSanitizer(): void
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', '');
        $this->purifier = new HTMLPurifier($config);
    }

    public function encryptData(string $data): string
    {
        try {
            $this->debugger->logOperation('Data encryption', 'Security');
            return Crypto::encrypt($data, $this->encryptionKey);
        } catch (Throwable $e) {
            $this->debugger->logError("Encryption Error", ['exception' => $e->getMessage()]);
            return '';
        }
    }

    public function decryptData(string $ciphertext): string
    {
        try {
            $this->debugger->logOperation('Data decryption', 'Security');
            return Crypto::decrypt($ciphertext, $this->encryptionKey);
        } catch (Throwable $e) {
            $this->debugger->logError("Decryption Error", ['exception' => $e->getMessage()]);
            return '';
        }
    }

    public function sanitizeString(string $input): string { return sanitize_text_field($input); }
    public function sanitizeEmail(string $email): string { return sanitize_email($email); }
    public function sanitizeURL(string $url): string { return esc_url_raw($url); }
    public function sanitizeHTML(string $html): string { return $this->purifier->purify($html); }
    public function sanitizeTextarea(string $text): string { return sanitize_textarea_field($text); }
    public function sanitizeInteger($number): int { return intval($number); }
    public function sanitizeFloat($number): float { return floatval($number); }
    public function sanitizeBoolean($value): bool { return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false; }
    public function sanitizeArray(array $input): array { return array_map('sanitize_text_field', $input); }
    public function sanitizeJSON(string $json): ?array {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $this->sanitizeArray($decoded) : null;
    }

    public function sanitizeFileUpload(array $file): ?array
    {
        $allowedMimeTypes = ['application/json', 'image/jpeg', 'image/png', 'application/pdf'];
        if (isset($file['type']) && in_array($file['type'], $allowedMimeTypes, true)) {
            return [
                'name'     => sanitize_file_name($file['name']),
                'type'     => $file['type'],
                'tmp_name' => $file['tmp_name'],
                'error'    => $file['error'],
                'size'     => $file['size']
            ];
        }
        $this->debugger->logError("Invalid file upload attempt", ['file' => $file]);
        return null;
    }

    private function loadServiceAccountKeys(): void
    {
        try {
            $keyFile = $this->keyPath . 'service_account.enc';
            if (file_exists($keyFile)) {
                $encryptedData = file_get_contents($keyFile);
                $decryptedJson = $this->decryptData($encryptedData);
                if (!empty($decryptedJson)) {
                    $this->serviceAccountKeys = json_decode($decryptedJson, true);
                    $this->debugger->logSecurityEvent("Encrypted service account keys loaded");
                } else {
                    $this->debugger->logError("Decrypted service account keys are empty", ['file' => $keyFile]);
                }
            } else {
                $this->debugger->logOperation("No service account key file found at $keyFile", "SecurityHandler");
            }
        } catch (Throwable $e) {
            $this->debugger->logError("Service Account Key Loading Error", ['exception' => $e->getMessage()]);
        }
    }

    public function storeServiceAccountKeys(array $keys): void
    {
        try {
            $keyFile = $this->keyPath . 'service_account.enc';
            $jsonData = json_encode($keys, JSON_PRETTY_PRINT);
            $encryptedData = $this->encryptData($jsonData);
            if (!empty($encryptedData)) {
                file_put_contents($keyFile, $encryptedData);
                chmod($keyFile, 0600);
                $this->debugger->logSecurityEvent("Service account keys securely stored");
            }
        } catch (Throwable $e) {
            $this->debugger->logError("Service Account Key Storage Error", ['exception' => $e->getMessage()]);
        }
    }

    public function getServiceAccountKeys(): ?array
    {
        if ($this->serviceAccountKeys === null) {
            $this->debugger->logOperation("Service account keys not loaded yet", "SecurityHandler");
        }
        return $this->serviceAccountKeys;
    }
}