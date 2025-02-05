<?php
namespace PassifyPro\Core\Security;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Processor\IntrospectionProcessor;
use RuntimeException;
use Throwable;

class Debugger
{
    private Logger $logger;
    private string $logPath;

    public function __construct()
    {
        $this->logPath = plugin_dir_path(__DIR__) . 'Security/logs/';
        $this->verifyLogDirectory();
        $this->initializeLogger();
    }

    /**
     * Verify that the log directory exists and is writable.
     */
    private function verifyLogDirectory(): void
    {
        try {
            if (!file_exists($this->logPath) && !wp_mkdir_p($this->logPath)) {
                throw new RuntimeException("Failed to create log directory: {$this->logPath}");
            }
            if (!is_writable($this->logPath)) {
                throw new RuntimeException("Log directory not writable: {$this->logPath}");
            }
        } catch (Throwable $e) {
            error_log("Debugger Error: " . $e->getMessage());
        }
    }

    /**
     * Initialize the Monolog logger with a rotating file handler.
     *
     * The RotatingFileHandler is configured to keep log files for 14 days.
     * This means that any log files older than 14 days are automatically removed.
     */
    private function initializeLogger(): void
    {
        try {
            $this->logger = new Logger('passifypro');
            $this->logger->pushProcessor(new IntrospectionProcessor());
            $handler = new RotatingFileHandler(
                $this->logPath . 'security.log',
                14, // Retain logs for 14 days; older log files will be automatically deleted.
                Logger::DEBUG
            );
            $this->logger->pushHandler($handler);
        } catch (Throwable $e) {
            error_log("Logger Initialization Error: " . $e->getMessage());
        }
    }

    /**
     * Log a security-related event at the alert level.
     *
     * @param string $message A description of the security event.
     * @param array  $context Additional context data.
     */
    public function logSecurityEvent(string $message, array $context = []): void
    {
        try {
            // Optionally redact sensitive data from $context here.
            $this->logger->alert("[SECURITY] $message", $context);
        } catch (Throwable $e) {
            error_log("Log Security Event Error: " . $e->getMessage());
        }
    }

    /**
     * Log a general operation at the info level.
     *
     * @param string $operation The operation being performed.
     * @param string $component The component or module performing the operation.
     */
    public function logOperation(string $operation, string $component): void
    {
        try {
            $this->logger->info("[OPERATION] $operation", ['component' => $component]);
        } catch (Throwable $e) {
            error_log("Log Operation Error: " . $e->getMessage());
        }
    }

    /**
     * Log an error at the error level.
     *
     * @param string $message A description of the error.
     * @param array  $context Additional context data.
     */
    public function logError(string $message, array $context = []): void
    {
        try {
            // Redact sensitive keys from context if necessary.
            $this->logger->error("[ERROR] $message", $context);
        } catch (Throwable $e) {
            error_log("Log Error Error: " . $e->getMessage());
        }
    }
}
