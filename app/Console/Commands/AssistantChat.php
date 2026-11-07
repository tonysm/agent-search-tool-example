<?php

namespace App\Console\Commands;

use App\Ai\Agents\KnowledgeBaseAssistant;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

#[Signature('assistant:chat
    {question? : Ask one question and exit}
    {--user=test@example.com : Email of the conversation owner}
    {--continue : Resume this user\'s last conversation}')]
#[Description('Chat with the knowledge-base assistant')]
class AssistantChat extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::firstWhere('email', $this->option('user'));

        if ($user === null) {
            $this->error("No user found with the email [{$this->option('user')}]. Run `php artisan db:seed` to create the default user.");

            return self::FAILURE;
        }

        $conversationId = $this->option('continue')
            ? KnowledgeBaseAssistant::make()->continueLastConversation($user)->currentConversation()
            : null;

        $question = $this->argument('question');

        if ($question !== null) {
            return $this->answerOnce($user, $question, $conversationId);
        }

        if (! $this->input->isInteractive()) {
            $this->error('Pass a question to use this command without an interactive terminal.');

            return self::FAILURE;
        }

        while (true) {
            $input = text(label: 'You', required: true, hint: 'Type "exit" to quit.');

            if (in_array(mb_strtolower(trim($input)), ['exit', 'quit'], true)) {
                return self::SUCCESS;
            }

            try {
                $conversationId = $this->streamAnswer($user, $input, $conversationId);
            } catch (AiException|RequestException $exception) {
                $this->error($exception->getMessage());
            }
        }
    }

    /**
     * Answer a single question and report whether it succeeded.
     */
    private function answerOnce(User $user, string $question, ?string $conversationId): int
    {
        try {
            $this->streamAnswer($user, $question, $conversationId);
        } catch (AiException|RequestException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Stream the assistant's answer to the console and return the conversation ID.
     */
    private function streamAnswer(User $user, string $input, ?string $conversationId): ?string
    {
        $stream = KnowledgeBaseAssistant::make()
            ->continueOrStart($conversationId, as: $user)
            ->stream($input);

        $isMidLine = false;

        foreach ($stream as $event) {
            if ($event instanceof TextDelta) {
                $this->output->write($event->delta, false, OutputInterface::OUTPUT_RAW);
                $isMidLine = true;
            }

            if ($event instanceof ToolCall) {
                if ($isMidLine) {
                    $this->newLine();
                    $isMidLine = false;
                }

                $query = OutputFormatter::escape((string) ($event->toolCall->arguments['query'] ?? ''));

                $this->line("<fg=gray>Searching docs: \"{$query}\"</>");
            }
        }

        $this->newLine();

        return $stream->conversationId;
    }
}
