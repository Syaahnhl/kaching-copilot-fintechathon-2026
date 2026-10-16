<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TelegramGetChatId extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:get-chat-id';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get Telegram Chat ID from recent bot messages';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $token = config('services.telegram.bot_token');

        if (empty($token) || $token === '<ISI TOKEN BOT KACHING DI SINI>') {
            $this->error('Please set TELEGRAM_BOT_TOKEN in your .env file first.');
            return;
        }

        $this->info('Fetching updates from Telegram...');

        try {
            $response = Http::get("https://api.telegram.org/bot{$token}/getUpdates");
            
            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['result']) && count($data['result']) > 0) {
                    $this->info('Recent messages:');
                    $found = false;
                    foreach ($data['result'] as $update) {
                        if (isset($update['message']['chat']['id'])) {
                            $chatId = $update['message']['chat']['id'];
                            $username = $update['message']['chat']['username'] ?? $update['message']['chat']['first_name'] ?? 'Unknown';
                            $this->line("Chat ID: {$chatId} (User/Group: {$username})");
                            $found = true;
                        }
                    }
                    if ($found) {
                        $this->info('Update your .env with the correct TELEGRAM_CHAT_ID.');
                    } else {
                        $this->warn('No chat ID found in the recent updates.');
                    }
                } else {
                    $this->warn('No recent messages found. Please send a message to your bot first, then run this command again.');
                }
            } else {
                $this->error('Failed to connect to Telegram API. Response: ' . $response->body());
            }
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
        }
    }
}
