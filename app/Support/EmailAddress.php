<?php

namespace App\Support;

/**
 * Register/login email hygiene without paid verification APIs.
 *
 * Layers:
 * 1. RFC format + DNS MX/A (Laravel email:rfc,dns)
 * 2. Common provider typo denylist + suggestion
 * 3. Disposable/temp-mail domain blocklist (resources/data/disposable_email_domains.txt)
 * 4. Pending-until-OTP + Resend bounce → email_status (AuthController / webhook)
 */
final class EmailAddress
{
    /** @var array<string, string> typo domain => suggested correct domain */
    public const COMMON_TYPOS = [
        'gmial.com' => 'gmail.com',
        'gmal.com' => 'gmail.com',
        'gamil.com' => 'gmail.com',
        'gnail.com' => 'gmail.com',
        'gmaill.com' => 'gmail.com',
        'gmai.com' => 'gmail.com',
        'gmail.con' => 'gmail.com',
        'gmail.co' => 'gmail.com',
        'gmail.cm' => 'gmail.com',
        'gmail.om' => 'gmail.com',
        'googlemail.con' => 'googlemail.com',
        'yaho.com' => 'yahoo.com',
        'yahooo.com' => 'yahoo.com',
        'yahoocom' => 'yahoo.com',
        'yahoo.con' => 'yahoo.com',
        'hotmial.com' => 'hotmail.com',
        'hotmal.com' => 'hotmail.com',
        'hotmail.con' => 'hotmail.com',
        'outlok.com' => 'outlook.com',
        'outllok.com' => 'outlook.com',
        'outlook.con' => 'outlook.com',
        'icloud.con' => 'icloud.com',
        'icoud.com' => 'icloud.com',
    ];

    /** In-memory set of disposable domains (lowercase). */
    private static ?array $disposableSet = null;

    public static function normalize(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    public static function domain(string $email): ?string
    {
        $parts = explode('@', self::normalize($email), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return $parts[1];
    }

    /**
     * If domain is a known typo, return human message + suggestion. Else null.
     */
    public static function typoMessage(?string $email): ?string
    {
        $domain = self::domain((string) $email);
        if ($domain === null) {
            return null;
        }

        $suggestion = self::COMMON_TYPOS[$domain] ?? null;
        if ($suggestion === null) {
            return null;
        }

        $local = explode('@', self::normalize((string) $email), 2)[0];

        return "Domain email sepertinya typo. Maksudnya {$local}@{$suggestion}?";
    }

    /**
     * True if domain is a known disposable / temp-mail provider.
     */
    public static function isDisposable(?string $email): bool
    {
        $domain = self::domain((string) $email);
        if ($domain === null) {
            return false;
        }

        $set = self::disposableSet();

        if (isset($set[$domain])) {
            return true;
        }

        // Subdomain of a blocked root (e.g. foo.mailinator.com)
        $parts = explode('.', $domain);
        while (count($parts) > 2) {
            array_shift($parts);
            $parent = implode('.', $parts);
            if (isset($set[$parent])) {
                return true;
            }
        }

        return false;
    }

    public static function disposableMessage(): string
    {
        return 'Email sekali pakai / temp-mail tidak diizinkan. Pakai Gmail, Yahoo, Outlook, atau email bisnis.';
    }

    /**
     * Combined pre-validation for register (typo + disposable). Returns error message or null.
     */
    public static function rejectMessage(?string $email): ?string
    {
        $email = self::normalize($email);
        if ($msg = self::typoMessage($email)) {
            return $msg;
        }
        if (self::isDisposable($email)) {
            return self::disposableMessage();
        }

        return null;
    }

    /**
     * Laravel rule string for register forms.
     * dns = MX/A record must exist (blocks random.tld typos that have no DNS).
     */
    public static function validationRule(bool $uniqueUsers = true): string
    {
        $base = 'required|email:rfc,dns|max:255';
        if ($uniqueUsers) {
            $base .= '|unique:users,email';
        }

        return $base;
    }

    /**
     * @return array<string, true>
     */
    private static function disposableSet(): array
    {
        if (self::$disposableSet !== null) {
            return self::$disposableSet;
        }

        // Prefer resource_path when app is booted; fall back to relative path for CLI unit smoke.
        $path = function_exists('resource_path')
            ? resource_path('data/disposable_email_domains.txt')
            : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'disposable_email_domains.txt');
        // When Laravel container exists but base path not bound (bare autoload), still fall back.
        if (!is_readable($path)) {
            $alt = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'disposable_email_domains.txt';
            if (is_readable($alt)) {
                $path = $alt;
            }
        }
        $set = [];

        if (is_readable($path)) {
            $fh = fopen($path, 'r');
            if ($fh !== false) {
                while (($line = fgets($fh)) !== false) {
                    $line = strtolower(trim($line));
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    $set[$line] = true;
                }
                fclose($fh);
            }
        }

        // Always-on short fallback if file missing on deploy
        foreach ([
            'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', '10minutemail.com',
            'tempmail.com', 'temp-mail.org', 'throwaway.email', 'yopmail.com', 'trashmail.com',
            'sharklasers.com', 'getnada.com', 'maildrop.cc', 'discard.email', 'fakeinbox.com',
        ] as $d) {
            $set[$d] = true;
        }

        return self::$disposableSet = $set;
    }
}
