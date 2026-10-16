<?php

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;

class TelegramLoggerHandler extends AbstractProcessingHandler
{
    private $botToken;
    private $chatId;

    public function __construct($level = Logger::ERROR, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->botToken = env('TELEGRAM_ALERT_BOT_TOKEN');
        $this->chatId = env('TELEGRAM_ALERT_CHAT_ID');
    }

    protected function write(LogRecord $record): void
    {
        if (empty($this->botToken) || empty($this->chatId)) {
            return;
        }

        $message = $record->message;
        $context = $record->context;

        // 1. Generate unique error signature/hash
        $hashInput = $message;
        if (isset($context['exception'])) {
            $e = $context['exception'];
            if ($e instanceof \Throwable) {
                $hashInput .= $e->getFile() . $e->getLine() . $e->getCode();
            }
        }
        $errorHash = md5($hashInput);
        $redisKey = 'telegram_alert_lock:' . $errorHash;

        // 2. Rate limiting & Deduplication via Redis (TTL 5 Menit)
        try {
            if (class_exists(\Illuminate\Support\Facades\Redis::class)) {
                // set dengan parameter 'EX' (expire seconds) dan 'NX' (only if not exists)
                // Predis/phpredis di Laravel mengembalikan 1/true jika berhasil set, dan null/false jika sudah ada
                $lockAcquired = \Illuminate\Support\Facades\Redis::set($redisKey, 1, 'EX', 300, 'NX');
                if (!$lockAcquired) {
                    // Skip sending to prevent spamming identical errors
                    return;
                }
            }
        } catch (\Throwable $e) {
            // Fallback: Jika Redis down, biarkan kueri lolos ke Telegram agar tidak hilang
        }
        
        $errorTitle = "🚨 *SERVER ERROR ALERT* 🚨";
        $env = env('APP_ENV', 'production');

        $text = "{$errorTitle}\n\n";
        $text .= "🌐 *Env:* `{$env}`\n";

        if (function_exists('app') && app()->runningInConsole()) {
            $argv = $_SERVER['argv'] ?? [];
            $cliCommand = self::sanitizeCliArgs($argv);
            $text .= "🖥️ *Execution:* `CLI`\n";
            $text .= "⌨️ *Command:* `{$cliCommand}`\n\n";
        } else {
            $url = request()->fullUrl();
            $method = request()->method();
            $ip = request()->ip();
            $text .= "🛣️ *Route:* `[{$method}] {$url}`\n";
            $text .= "👤 *IP:* `{$ip}`\n\n";
        }

        $sanitizedMessage = self::sanitizeSensitiveContent($message);
        $text .= "❌ *Message:*\n`{$sanitizedMessage}`\n\n";

        // Tambah stack trace singkat jika ada exception
        if (isset($context['exception'])) {
            $e = $context['exception'];
            if ($e instanceof \Throwable) {
                $file = $e->getFile();
                $line = $e->getLine();
                $text .= "📍 *Location:* `{$file}:{$line}`\n";
                $text .= "📝 *Code Trace:*\n```\n";
                $sanitizedTrace = self::sanitizeSensitiveContent(substr($e->getTraceAsString(), 0, 500));
                $text .= $sanitizedTrace . "\n```";
            }
        }

        $this->sendTelegramNotification($text);
    }

    /**
     * Sanitize CLI arguments to prevent leaking sensitive options, tokens, or raw code.
     */
    public static function sanitizeCliArgs(array $argv): string
    {
        if (empty($argv)) {
            return 'artisan (console)';
        }

        $script = basename($argv[0]);
        $startIndex = 1;
        $prefix = $script;

        if ($script === 'php' && isset($argv[1])) {
            $prefix = 'php ' . basename($argv[1]);
            $startIndex = 2;
        }

        $sanitized = [$prefix];
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'execute', 'auth', 'credential', 'pin', 'e'];
        $count = count($argv);

        $isTinker = false;
        if (isset($argv[$startIndex]) && strtolower($argv[$startIndex]) === 'tinker') {
            $isTinker = true;
        }

        for ($i = $startIndex; $i < $count; $i++) {
            $arg = $argv[$i];

            // 1. Handle --option=value or -option=value
            if (preg_match('/^(--?[a-zA-Z0-9_-]+)=(.*)$/s', $arg, $matches)) {
                $opt = $matches[1];
                $val = $matches[2];
                $optName = strtolower(ltrim($opt, '-'));

                $isSensitive = false;
                foreach ($sensitiveKeys as $key) {
                    if ($optName === $key || str_contains($optName, $key)) {
                        $isSensitive = true;
                        break;
                    }
                }

                if ($isSensitive) {
                    $sanitized[] = $opt . '=[REDACTED]';
                } else {
                    if (strlen($val) > 40) {
                        $val = substr($val, 0, 37) . '...';
                    }
                    $sanitized[] = $opt . '=' . $val;
                }
                continue;
            }

            // 2. Handle --option value or -option value
            if (preg_match('/^--?([a-zA-Z0-9_-]+)$/', $arg, $matches)) {
                $opt = $arg;
                $optName = strtolower($matches[1]);
                $sanitized[] = $opt;

                $isSensitive = false;
                foreach ($sensitiveKeys as $key) {
                    if ($optName === $key || str_contains($optName, $key)) {
                        $isSensitive = true;
                        break;
                    }
                }

                if ($isSensitive && ($i + 1) < $count) {
                    $i++;
                    $sanitized[] = '[REDACTED]';
                }
                continue;
            }

            // 3. Tinker arbitrary code positional args
            if ($isTinker && $i > $startIndex) {
                $sanitized[] = '[REDACTED]';
                continue;
            }

            // 4. Positional args that look like tokens/keys
            if (preg_match('/^(gsk_|sk-|Bearer)/i', $arg)) {
                $sanitized[] = '[REDACTED]';
                continue;
            }

            if (strlen($arg) > 40) {
                $sanitized[] = substr($arg, 0, 37) . '...';
            } else {
                $sanitized[] = $arg;
            }
        }

        return implode(' ', $sanitized);
    }

    /**
     * Sanitize sensitive strings, Bearer tokens, and API keys from error messages/traces.
     */
    public static function sanitizeSensitiveContent(string $text): string
    {
        // 1. Redact Bearer tokens
        $text = preg_replace('/Bearer\s+[a-zA-Z0-9_\-\.]+/i', 'Bearer [REDACTED]', $text);

        // 2. Redact API keys (gsk_..., sk-..., etc.)
        $text = preg_replace('/\b(gsk_|sk-|key-)[a-zA-Z0-9_\-]{16,}\b/i', '[REDACTED]', $text);

        // 3. Redact key-value pairs (password=..., api_key: ..., etc.)
        $text = preg_replace(
            '/((?:password|token|secret|api_?key|auth_?token|credential|pin)[\s_]*[=:\=>]\s*[\'"]?)[^\'"\s\r\n,;]+/i',
            '${1}[REDACTED]',
            $text
        );

        return $text;
    }

    private function sendTelegramNotification(string $message): void
    {
        $url = "https://api.telegram.org/bot" . $this->botToken . "/sendMessage";
        
        $postData = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        curl_exec($ch);
        curl_close($ch);
    }
}
