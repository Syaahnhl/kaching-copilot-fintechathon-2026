<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Logging\TelegramLoggerHandler;

class TelegramLoggerHandlerTest extends TestCase
{
    /**
     * Test sanitasi CLI option bentuk --option=value
     */
    public function test_cli_option_equals_redaction(): void
    {
        $argv = [
            'artisan',
            'migrate',
            '--password=super_secret_pass_123',
            '--token=gsk_live_token_abc_xyz_123',
            '--secret=my_app_secret_value',
            '--key=sk-or-v1-my-secret-key-12345',
            '--force',
        ];

        $sanitized = TelegramLoggerHandler::sanitizeCliArgs($argv);

        $this->assertStringNotContainsString('super_secret_pass_123', $sanitized);
        $this->assertStringNotContainsString('gsk_live_token_abc_xyz_123', $sanitized);
        $this->assertStringNotContainsString('my_app_secret_value', $sanitized);
        $this->assertStringNotContainsString('sk-or-v1-my-secret-key-12345', $sanitized);

        $this->assertStringContainsString('--password=[REDACTED]', $sanitized);
        $this->assertStringContainsString('--token=[REDACTED]', $sanitized);
        $this->assertStringContainsString('--secret=[REDACTED]', $sanitized);
        $this->assertStringContainsString('--key=[REDACTED]', $sanitized);
        $this->assertStringContainsString('--force', $sanitized);
    }

    /**
     * Test sanitasi CLI option bentuk --option value
     */
    public function test_cli_option_space_redaction(): void
    {
        $argv = [
            'artisan',
            'kaching:audit',
            '--password',
            'confidential_pass',
            '--token',
            'token_secret_value',
            '--pin',
            '123456',
            '--fix',
        ];

        $sanitized = TelegramLoggerHandler::sanitizeCliArgs($argv);

        $this->assertStringNotContainsString('confidential_pass', $sanitized);
        $this->assertStringNotContainsString('token_secret_value', $sanitized);
        $this->assertStringNotContainsString('123456', $sanitized);

        $this->assertStringContainsString('--password [REDACTED]', $sanitized);
        $this->assertStringContainsString('--token [REDACTED]', $sanitized);
        $this->assertStringContainsString('--pin [REDACTED]', $sanitized);
        $this->assertStringContainsString('--fix', $sanitized);
    }

    /**
     * Test sanitasi --execute dan argumen kode arbitrary pada tinker
     */
    public function test_cli_execute_and_tinker_redaction(): void
    {
        $argvExecuteEquals = [
            'artisan',
            'tinker',
            '--execute=User::where("id", 1)->delete()',
        ];
        $sanitizedEquals = TelegramLoggerHandler::sanitizeCliArgs($argvExecuteEquals);
        $this->assertStringNotContainsString('delete()', $sanitizedEquals);
        $this->assertStringContainsString('--execute=[REDACTED]', $sanitizedEquals);

        $argvExecuteSpace = [
            'artisan',
            'tinker',
            '--execute',
            'echo env("APP_KEY");',
        ];
        $sanitizedSpace = TelegramLoggerHandler::sanitizeCliArgs($argvExecuteSpace);
        $this->assertStringNotContainsString('APP_KEY', $sanitizedSpace);
        $this->assertStringContainsString('--execute [REDACTED]', $sanitizedSpace);

        // Positional raw code on tinker
        $argvTinkerRaw = [
            'artisan',
            'tinker',
            'DB::table("users")->get()',
        ];
        $sanitizedTinker = TelegramLoggerHandler::sanitizeCliArgs($argvTinkerRaw);
        $this->assertStringNotContainsString('DB::table', $sanitizedTinker);
        $this->assertStringContainsString('artisan tinker [REDACTED]', $sanitizedTinker);
    }

    /**
     * Test sanitasi konten sensitif pada pesan error dan trace
     */
    public function test_sensitive_content_redaction(): void
    {
        $rawMessage = 'Failed connecting to database with password="super_secret_pw_999" and Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9';
        $sanitized = TelegramLoggerHandler::sanitizeSensitiveContent($rawMessage);

        $this->assertStringNotContainsString('super_secret_pw_999', $sanitized);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $sanitized);
        $this->assertStringContainsString('password="[REDACTED]"', $sanitized);
        $this->assertStringContainsString('Bearer [REDACTED]', $sanitized);

        $rawKeys = 'Groq API failed with key gsk_1234567890abcdef12345678 and OpenAI sk-1234567890abcdef12345678';
        $sanitizedKeys = TelegramLoggerHandler::sanitizeSensitiveContent($rawKeys);
        $this->assertStringNotContainsString('gsk_1234567890abcdef12345678', $sanitizedKeys);
        $this->assertStringNotContainsString('sk-1234567890abcdef12345678', $sanitizedKeys);
        $this->assertStringContainsString('[REDACTED]', $sanitizedKeys);
    }
}
