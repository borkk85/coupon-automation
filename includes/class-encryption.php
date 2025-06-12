<?php
/**
 * Encryption handler for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Encryption class for secure storage of sensitive data
 */
class Coupon_Automation_Encryption {
    
    /**
     * Encryption method
     */
    const ENCRYPTION_METHOD = 'AES-256-CBC';
    
    /**
     * Key derivation method
     */
    const KEY_DERIVATION_METHOD = 'sha256';
    
    /**
     * Encryption key cache
     * @var string|null
     */
    private static $encryption_key = null;
    
    /**
     * Initialize encryption
     */
    public function init() {
        // Ensure encryption key exists
        $this->get_encryption_key();
    }
    
    /**
     * Encrypt sensitive data
     * 
     * @param string $data Data to encrypt
     * @return string|false Encrypted data or false on failure
     */
    public function encrypt($data) {
        if (empty($data)) {
            return $data;
        }
        
        try {
            $key = $this->get_encryption_key();
            if (!$key) {
                return false;
            }
            
            // Generate a random IV
            $iv_length = openssl_cipher_iv_length(self::ENCRYPTION_METHOD);
            $iv = openssl_random_pseudo_bytes($iv_length);
            
            // Encrypt the data
            $encrypted = openssl_encrypt($data, self::ENCRYPTION_METHOD, $key, 0, $iv);
            
            if ($encrypted === false) {
                return false;
            }
            
            // Combine IV and encrypted data, then base64 encode
            return base64_encode($iv . $encrypted);
            
        } catch (Exception $e) {
            $this->log_encryption_error('Encryption failed', $e);
            return false;
        }
    }
    
    /**
     * Decrypt sensitive data
     * 
     * @param string $encrypted_data Encrypted data to decrypt
     * @return string|false Decrypted data or false on failure
     */
    public function decrypt($encrypted_data) {
        if (empty($encrypted_data)) {
            return $encrypted_data;
        }
        
        try {
            $key = $this->get_encryption_key();
            if (!$key) {
                return false;
            }
            
            // Base64 decode the data
            $data = base64_decode($encrypted_data, true);
            if ($data === false) {
                return false;
            }
            
            // Extract IV and encrypted data
            $iv_length = openssl_cipher_iv_length(self::ENCRYPTION_METHOD);
            $iv = substr($data, 0, $iv_length);
            $encrypted = substr($data, $iv_length);
            
            // Decrypt the data
            $decrypted = openssl_decrypt($encrypted, self::ENCRYPTION_METHOD, $key, 0, $iv);
            
            return $decrypted;
            
        } catch (Exception $e) {
            $this->log_encryption_error('Decryption failed', $e);
            return false;
        }
    }
    
    /**
     * Get or generate encryption key
     * 
     * @return string|false Encryption key or false on failure
     */
    private function get_encryption_key() {
        if (self::$encryption_key !== null) {
            return self::$encryption_key;
        }
        
        // Try to get existing key
        $stored_key = get_option('coupon_automation_encryption_key');
        
        if (!$stored_key) {
            // Generate new key
            $stored_key = $this->generate_encryption_key();
            if ($stored_key) {
                update_option('coupon_automation_encryption_key', $stored_key, false);
            }
        }
        
        if ($stored_key) {
            // Derive the actual encryption key
            self::$encryption_key = $this->derive_key($stored_key);
        }
        
        return self::$encryption_key;
    }
    
    /**
     * Generate a new encryption key
     * 
     * @return string|false Generated key or false on failure
     */
    private function generate_encryption_key() {
        try {
            // Use WordPress salts and other unique data
            $unique_data = [
                ABSPATH,
                DB_NAME,
                DB_USER,
                DB_HOST,
                AUTH_KEY,
                SECURE_AUTH_KEY,
                LOGGED_IN_KEY,
                NONCE_KEY,
                time(),
                uniqid('', true),
            ];
            
            // Add server-specific data if available
            if (isset($_SERVER['SERVER_SIGNATURE'])) {
                $unique_data[] = $_SERVER['SERVER_SIGNATURE'];
            }
            
            if (isset($_SERVER['SERVER_SOFTWARE'])) {
                $unique_data[] = $_SERVER['SERVER_SOFTWARE'];
            }
            
            // Generate random bytes
            $random_bytes = openssl_random_pseudo_bytes(32);
            $unique_data[] = $random_bytes;
            
            // Create the key
            $key_material = implode('|', $unique_data);
            return hash('sha256', $key_material);
            
        } catch (Exception $e) {
            $this->log_encryption_error('Key generation failed', $e);
            return false;
        }
    }
    
    /**
     * Derive encryption key from stored key
     * 
     * @param string $stored_key Stored key material
     * @return string Derived encryption key
     */
    private function derive_key($stored_key) {
        // Add WordPress-specific salts for key derivation
        $key_material = $stored_key . AUTH_KEY . SECURE_AUTH_KEY;
        return hash(self::KEY_DERIVATION_METHOD, $key_material, true);
    }
    
    /**
     * Check if data appears to be encrypted
     * 
     * @param string $data Data to check
     * @return bool True if data appears encrypted, false otherwise
     */
    public function is_encrypted($data) {
        if (empty($data)) {
            return false;
        }
        
        // Check if it's base64 encoded and has the right length characteristics
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }
        
        $iv_length = openssl_cipher_iv_length(self::ENCRYPTION_METHOD);
        return strlen($decoded) > $iv_length;
    }
    
    /**
     * Securely store API key
     * 
     * @param string $key_name Option name for the API key
     * @param string $api_key API key to store
     * @return bool True on success, false on failure
     */
    public function store_api_key($key_name, $api_key) {
        if (empty($api_key)) {
            delete_option($key_name);
            return true;
        }
        
        $encrypted_key = $this->encrypt($api_key);
        if ($encrypted_key === false) {
            return false;
        }
        
        return update_option($key_name, $encrypted_key, false);
    }
    
    /**
     * Retrieve and decrypt API key
     * 
     * @param string $key_name Option name for the API key
     * @return string|false Decrypted API key or false on failure
     */
    public function get_api_key($key_name) {
        $encrypted_key = get_option($key_name);
        
        if (empty($encrypted_key)) {
            return '';
        }
        
        // Check if it's already decrypted (backwards compatibility)
        if (!$this->is_encrypted($encrypted_key)) {
            // Encrypt it for future use
            $this->store_api_key($key_name, $encrypted_key);
            return $encrypted_key;
        }
        
        return $this->decrypt($encrypted_key);
    }
    
    /**
     * Migrate existing unencrypted API keys to encrypted storage
     * 
     * @return bool True on success, false on failure
     */
    public function migrate_api_keys() {
        $api_key_options = [
            'addrevenue_api_token',
            'awin_api_token',
            'openai_api_key',
            'yourl_api_token',
        ];
        
        $migration_success = true;
        
        foreach ($api_key_options as $option_name) {
            $current_value = get_option($option_name);
            
            if (!empty($current_value) && !$this->is_encrypted($current_value)) {
                $success = $this->store_api_key($option_name, $current_value);
                if (!$success) {
                    $migration_success = false;
                    $this->log_encryption_error('Failed to migrate API key', new Exception("Option: $option_name"));
                }
            }
        }
        
        return $migration_success;
    }
    
    /**
     * Clear encryption key (for testing or reset purposes)
     * 
     * @return bool True on success
     */
    public function reset_encryption_key() {
        delete_option('coupon_automation_encryption_key');
        self::$encryption_key = null;
        return true;
    }
    
    /**
     * Validate encryption functionality
     * 
     * @return bool True if encryption is working properly
     */
    public function validate_encryption() {
        $test_data = 'test_encryption_' . time();
        
        $encrypted = $this->encrypt($test_data);
        if ($encrypted === false) {
            return false;
        }
        
        $decrypted = $this->decrypt($encrypted);
        if ($decrypted === false) {
            return false;
        }
        
        return $decrypted === $test_data;
    }
    
    /**
     * Log encryption errors
     * 
     * @param string $message Error message
     * @param Exception $e Exception object
     */
    private function log_encryption_error($message, Exception $e) {
        $logger = coupon_automation()->get_service('logger');
        if ($logger) {
            $logger->error($message, [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        } else {
            error_log(sprintf('Coupon Automation Encryption Error: %s - %s', $message, $e->getMessage()));
        }
    }
}