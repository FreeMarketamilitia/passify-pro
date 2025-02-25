<?php
namespace PassifyPro\Core\Security;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\IntrospectionProcessor;
use RuntimeException;
use Throwable;

class Debugger
{
    private Logger $logger;
    private string $logPath;

    public function __construct() {
        $this->logPath = WP_CONTENT_DIR . '/uploads/passify-logs/';
        
        error_log("Debugger: Starting initialization with log path: " . $this->logPath);
        
        $this->verifyLogDirectory();
        $this->initializeLogger();
        
        $this->logger->info("Debugger initialized successfully", ['path' => $this->logPath]);
        $this->logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG)); // Fallback
        error_log("Debugger: Post-initialization log attempt");
    }

    private function verifyLogDirectory(): void
    {
        try {
            if (!file_exists($this->logPath)) {
                error_log("Debugger: Creating directory {$this->logPath}");
                if (!wp_mkdir_p($this->logPath)) {
                    throw new RuntimeException("Failed to create log directory: {$this->logPath}");
                }
                error_log("Debugger: Directory {$this->logPath} created");
            }
            if (!is_writable($this->logPath)) {
                throw new RuntimeException("Log directory not writable: {$this->logPath}");
            }
            error_log("Debugger: Directory {$this->logPath} is writable");
        } catch (Throwable $e) {
            error_log("Debugger Error: " . $e->getMessage());
        }
    }

    private function initializeLogger(): void
    {
        try {
            $this->logger = new Logger('passifypro');
            $this->logger->pushProcessor(new IntrospectionProcessor());
            
            $logFile = $this->logPath . 'security.log';
            // Use StreamHandler instead of RotatingFileHandler for simplicity
            $handler = new StreamHandler($logFile, Logger::DEBUG);
            $handler->setFormatter(new \Monolog\Formatter\LineFormatter(null, null, true, true));
            $this->logger->pushHandler($handler);
            
            error_log("Debugger: Logger initialized with file: $logFile");
        } catch (Throwable $e) {
            error_log("Logger Initialization Error: " . $e->getMessage());
        }
    }

    public function logSecurityEvent(string $message, array $context = []): void
    {
        try {
            $this->logger->alert("[SECURITY] $message", $context);
        } catch (Throwable $e) {
            error_log("Log Security Event Error: " . $e->getMessage());
        }
    }

    public function logOperation(string $operation, string $component, array $context = []): void
    {
        try {
            $this->logger->info("[OPERATION] $operation", array_merge(['component' => $component], $context));
        } catch (Throwable $e) {
            error_log("Log Operation Error: " . $e->getMessage());
        }
    }

    public function logError(string $message, array $context = []): void
    {
        try {
            $this->logger->error("[ERROR] $message", $context);
        } catch (Throwable $e) {
            error_log("Log Error Error: " . $e->getMessage());
        }
    }
}