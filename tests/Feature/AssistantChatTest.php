<?php

use App\Ai\Agents\KnowledgeBaseAssistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'test@example.com']);
});

test('it answers a single question and exits', function () {
    KnowledgeBaseAssistant::fake(['Answer']);

    $this->artisan('assistant:chat', ['question' => 'How do I invite someone?'])
        ->expectsOutputToContain('Answer')
        ->assertSuccessful();

    KnowledgeBaseAssistant::assertPrompted('How do I invite someone?');
});

test('it saves the conversation for the user', function () {
    KnowledgeBaseAssistant::fake(['Answer']);

    $this->artisan('assistant:chat', ['question' => 'Hello'])->assertSuccessful();

    expect(DB::table('agent_conversation_messages')->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and(DB::table('agent_conversations')->value('participant_id'))->toBe($this->user->id);
});

test('it runs the search tool the model calls', function () {
    Storage::fake('docs');
    Storage::disk('docs')->put('billing.md', "# Billing\n\n## Finding invoices\n\nOpen the Invoices page.");

    KnowledgeBaseAssistant::fake([
        new ToolCall('call_1', 'search-knowledge-base', ['query' => 'invoices']),
        'Answer',
    ]);

    $this->artisan('assistant:chat', ['question' => 'Where are my invoices?'])
        ->expectsOutputToContain('Searching docs: "invoices"')
        ->expectsOutputToContain('Answer')
        ->assertSuccessful();

    expect(DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps'))
        ->toContain('Open the Invoices page.');
});

test('it keeps the same conversation until the user exits', function () {
    KnowledgeBaseAssistant::fake(['First', 'Second']);

    $this->artisan('assistant:chat')
        ->expectsQuestion('You', 'First question')
        ->expectsQuestion('You', 'Second question')
        ->expectsQuestion('You', 'exit')
        ->assertSuccessful();

    KnowledgeBaseAssistant::assertPromptedTimes(2);
    KnowledgeBaseAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Second question'
        && collect($prompt->agent->messages())->contains(fn ($message): bool => $message->content === 'First question'));

    expect(DB::table('agent_conversations')->count())->toBe(1)
        ->and(DB::table('agent_conversation_messages')->count())->toBe(4);
});

test('it starts a new conversation without the continue option', function () {
    KnowledgeBaseAssistant::fake(['First', 'Second']);

    $this->artisan('assistant:chat', ['question' => 'One'])->assertSuccessful();
    $this->artisan('assistant:chat', ['question' => 'Two'])->assertSuccessful();

    expect(DB::table('agent_conversations')->count())->toBe(2);
});

test('it resumes the latest conversation with the continue option', function () {
    KnowledgeBaseAssistant::fake(['First', 'Second']);

    $this->artisan('assistant:chat', ['question' => 'One'])->assertSuccessful();
    $this->artisan('assistant:chat', ['question' => 'Two', '--continue' => true])->assertSuccessful();

    expect(DB::table('agent_conversations')->count())->toBe(1)
        ->and(DB::table('agent_conversation_messages')->count())->toBe(4);
});

test('it fails with a hint when the user does not exist', function () {
    KnowledgeBaseAssistant::fake();

    $this->artisan('assistant:chat', ['question' => 'Hello', '--user' => 'nobody@example.com'])
        ->expectsOutputToContain('php artisan db:seed')
        ->assertFailed();

    KnowledgeBaseAssistant::assertNeverPrompted();
});

test('it reports provider errors', function () {
    KnowledgeBaseAssistant::fake(fn () => throw new RateLimitedException('Slow down.'));

    $this->artisan('assistant:chat', ['question' => 'Hello'])
        ->expectsOutputToContain('Slow down.')
        ->assertFailed();
});

test('it reports a rejected API key without crashing', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401)]);

    $this->artisan('assistant:chat', ['question' => 'Hello'])
        ->expectsOutputToContain('401')
        ->assertFailed();
});

test('it requires a question without an interactive terminal', function () {
    KnowledgeBaseAssistant::fake();

    $this->artisan('assistant:chat', ['--no-interaction' => true])
        ->expectsOutputToContain('Pass a question')
        ->assertFailed();

    KnowledgeBaseAssistant::assertNeverPrompted();
});

test('it keeps the session open after a provider error', function () {
    $attempts = 0;

    KnowledgeBaseAssistant::fake(function () use (&$attempts) {
        return ++$attempts === 1 ? throw new RateLimitedException('Slow down.') : 'Recovered';
    });

    $this->artisan('assistant:chat')
        ->expectsQuestion('You', 'First')
        ->expectsOutputToContain('Slow down.')
        ->expectsQuestion('You', 'Second')
        ->expectsOutputToContain('Recovered')
        ->expectsQuestion('You', 'exit')
        ->assertSuccessful();
});
