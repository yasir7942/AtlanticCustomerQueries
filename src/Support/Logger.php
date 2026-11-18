<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Application error log in storage/logs (outside public_html).
 * Callers must not pass passwords, keys or inquiry bodies; context values are truncated.
 */
final class Logger
{
    public function __construct(private string $dir)
    {
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        if (!is_dir($this->dir) || !is_writable($this->dir)) {
            error_log('[inquiry-manager] ' . $level . ' ' . $message);
            return;
        }
        $clean = [];
        foreach ($context as $k => $v) {
            $clean[$k] = is_scalar($v) ? mb_substr((string) $v, 0, 500) : gettype($v);
        }
        $line = sprintf(
            "[%s] %s %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            str_replace(["\r", "\n"], ' ', $message),
            $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($this->dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
