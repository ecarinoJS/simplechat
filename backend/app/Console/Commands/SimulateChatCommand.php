<?php

namespace App\Console\Commands;

use App\Http\Controllers\ChatController;
use App\Models\User;
use App\Services\AzurePubSubPublisher;
use Illuminate\Http\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SimulateChatCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chat:simulate {--messages=5 : Number of messages to exchange}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate a chat conversation between two test users';

    /**
     * Execute the console command.
     */
    public function handle(AzurePubSubPublisher $publisher): int
    {
        $messageCount = (int) $this->option('messages');

        $this->info('=== SimpleChat Two-User Simulation ===');
        $this->newLine();

        // Get or create test users
        $user1 = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User 1',
                'password' => bcrypt('password'),
            ]
        );

        $user2 = User::firstOrCreate(
            ['email' => 'testuser2@example.com'],
            [
                'name' => 'Test User 2',
                'password' => bcrypt('password'),
            ]
        );

        $this->info("User 1: {$user1->name} ({$user1->email})");
        $this->info("User 2: {$user2->name} ({$user2->email})");
        $this->newLine();

        // Sample conversation messages
        $user1Messages = [
            'Hey there!',
            'How are you doing today?',
            'Did you see the latest news?',
            'That sounds interesting!',
            'I agree with you.',
            'Thanks for chatting!',
            'Have a great day!',
        ];

        $user2Messages = [
            'Hi! Good to see you.',
            "I'm doing great, thanks!",
            'No, what happened?',
            'Tell me more about it.',
            "That's a good point.",
            'You too!',
            'See you later!',
        ];

        $this->info("Simulating {$messageCount} message exchanges...");
        $this->newLine();

        $successCount = 0;
        $failCount = 0;
        $chatController = new ChatController();

        for ($i = 0; $i < $messageCount; $i++) {
            // Determine which user sends the message
            $sender = ($i % 2 === 0) ? $user1 : $user2;
            $messages = ($i % 2 === 0) ? $user1Messages : $user2Messages;
            $messageContent = $messages[$i % count($messages)];

            // Create a mock request
            $request = new Request([
                'content' => $messageContent,
            ]);

            // Mock authentication for the user
            auth()->login($sender);

            try {
                // Call the ChatController method
                $response = $chatController->sendMessage($request, $publisher);

                if ($response->getStatusCode() === 200) {
                    $successCount++;
                    $this->line("<fg=green>✓</> <fg=cyan>{$sender->name}:</> {$messageContent}");
                } else {
                    $failCount++;
                    $this->line("<fg=red>✗</> <fg=cyan>{$sender->name}:</> {$messageContent} <fg=red>(failed)</>");
                }
            } catch (\Exception $e) {
                $failCount++;
                $this->line("<fg=red>✗</> <fg=cyan>{$sender->name}:</> {$messageContent} <fg=red>(error: {$e->getMessage()})</>");
            }

            // Small delay between messages
            usleep(500000); // 0.5 seconds
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Messages sent: {$successCount}");
        if ($failCount > 0) {
            $this->error("Messages failed: {$failCount}");
        }

        return Command::SUCCESS;
    }
}
