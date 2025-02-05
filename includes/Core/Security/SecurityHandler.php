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
        // Define key storage path (ensure trailing slash)
        $this->keyPath = plugin_dir_path(__DIR__) . 'Security/keys/';
        $this->initializeKeyStorage();
        $this->initializeEncryption();
        $this->initializeSanitizer();
        $this->loadServiceAccountKeys();
    }

    /**
     * Ensure that the directory for encryption keys exists.
     */
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

    /**
     * Initialize the encryption key.
     */
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

    /**
     * Initialize HTML Purifier for input sanitization.
     */
    private function initializeSanitizer(): void
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', '');
        $this->purifier = new HTMLPurifier($config);
    }

    /**
     * Encrypts data using Defuse\Crypto.
     *
     * @param string $data The plain text data to encrypt.
     * @return string The encrypted ciphertext.
     */
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

    /**
     * Decrypts an encrypted string using Defuse\Crypto.
     *
     * How it works:
     * - Receives the encrypted ciphertext (which is a JSON string encrypted).
     * - Uses the stored encryption key to attempt decryption.
     * - On success, returns the decrypted plaintext JSON.
     * - On failure, logs the error and returns an empty string.
     *
     * @param string $ciphertext The encrypted data.
     * @return string The decrypted plain text (JSON string).
     */
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

    /**
     * Sanitize a simple text string.
     */
    public function sanitizeString(string $input): string
    {
        return sanitize_text_field($input);
    }

    /**
     * Sanitize an email address.
     */
    public function sanitizeEmail(string $email): string
    {
        return sanitize_email($email);
    }

    /**
     * Sanitize a URL.
     */
    public function sanitizeURL(string $url): string
    {
        return esc_url_raw($url);
    }

    /**
     * Sanitize HTML input.
     */
    public function sanitizeHTML(string $html): string
    {
        return $this->purifier->purify($html);
    }

    /**
     * Sanitize textarea input.
     */
    public function sanitizeTextarea(string $text): string
    {
        return sanitize_textarea_field($text);
    }

    /**
     * Sanitize an integer.
     */
    public function sanitizeInteger($number): int
    {
        return intval($number);
    }

    /**
     * Sanitize a float.
     */
    public function sanitizeFloat($number): float
    {
        return floatval($number);
    }

    /**
     * Sanitize a boolean.
     */
    public function sanitizeBoolean($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * Sanitize an array of text fields.
     */
    public function sanitizeArray(array $input): array
    {
        return array_map('sanitize_text_field', $input);
    }

    /**
     * Decode and sanitize a JSON string.
     */
    public function sanitizeJSON(string $json): ?array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $this->sanitizeArray($decoded) : null;
    }

    /**
     * Validates and sanitizes an uploaded file.
     * Only allows specific mime types.
     *
     * @param array $file The uploaded file array.
     * @return array|null The sanitized file info or null on failure.
     */
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

    /**
     * Loads the encrypted service account keys from storage.
     */
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
                }
            }
        } catch (Throwable $e) {
            $this->debugger->logError("Service Account Key Loading Error", ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Encrypts and stores the service account keys.
     *
     * @param array $keys The service account keys array.
     */
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

    /**
     * Retrieve the decrypted service account keys.
     *
     * @return array|null The service account keys array or null if not loaded.
     */
    public function getServiceAccountKeys(): ?array
    {
        return $this->serviceAccountKeys;
    }
}
