<?php

namespace Lib\Logger;

// Cargar interfaces PSR-3 si no existen (fallback)
if (!interface_exists('Psr\Log\LoggerInterface')) {
    require_once __DIR__ . '/../Psr/Log/LoggerInterface.php';
}

if (!class_exists('Psr\Log\LogLevel')) {
    require_once __DIR__ . '/../Psr/Log/LogLevel.php';
}

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Logger - Sistema de logging compatible con PSR-3
 * 
 * Soporta múltiples handlers y formatters
 * Niveles: emergency, alert, critical, error, warning, notice, info, debug
 * 
 * @package Lib\Logger
 * @version 1.0.0
 */
class Logger implements LoggerInterface
{
    /**
     * Handlers registrados
     * @var array<LogHandler>
     */
    private array $handlers = [];

    /**
     * Nivel mínimo de log a procesar
     * @var string
     */
    private string $minLevel;

    /**
     * Mapeo de niveles a prioridad num�rica
     * @var array<string, int>
     */
    private const LEVEL_PRIORITY = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /**
     * Constructor
     * 
     * @param string $minLevel Nivel mínimo (default: INFO)
     */
    public function __construct(string $minLevel = LogLevel::INFO)
    {
        $this->minLevel = $minLevel;
    }

    /**
     * Agrega un handler
     * 
     * @param LogHandler $handler
     * @return self
     */
    public function addHandler(LogHandler $handler): self
    {
        $this->handlers[] = $handler;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = []): void
    {
        // Verificar si el nivel cumple el mínimo
        if (!$this->shouldLog($level)) {
            return;
        }

        // Interpolar placeholders en el mensaje
        $message = $this->interpolate($message, $context);

        // Crear record
        $record = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'extra' => $this->getExtra()
        ];

        // Enviar a todos los handlers
        foreach ($this->handlers as $handler) {
            $handler->handle($record);
        }
    }

    /**
     * Verifica si debe loggear según nivel mínimo
     * 
     * @param string $level
     * @return bool
     */
    private function shouldLog(string $level): bool
    {
        $levelPriority = self::LEVEL_PRIORITY[$level] ?? 0;
        $minPriority = self::LEVEL_PRIORITY[$this->minLevel] ?? 0;

        return $levelPriority >= $minPriority;
    }

    /**
     * Interpola placeholders {key} en el mensaje
     * 
     * @param string $message
     * @param array $context
     * @return string
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        return strtr($message, $replace);
    }

    /**
     * Obtiene información extra del contexto
     * 
     * @return array
     */
    private function getExtra(): array
    {
        return [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'user_id' => $_SESSION['user']['id'] ?? null,
        ];
    }
}

/**
 * Interface para handlers de log
 */
interface LogHandler
{
    /**
     * Procesa un record de log
     * 
     * @param array $record
     * @return void
     */
    public function handle(array $record): void;
}









