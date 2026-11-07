<?php

use App\Mcp\Servers\KnowledgeBaseServer;
use App\Mcp\Tools\SearchKnowledgeBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

const KNOWLEDGE_BASE_ENDPOINT = '/mcp/knowledge-base';

beforeEach(function () {
    Storage::fake('docs');
    usePassportTestKeys();
});

/**
 * Build a JSON-RPC request body for the MCP endpoint.
 *
 * @param  array<string, mixed>  $params
 * @return array{jsonrpc: string, id: int, method: string, params: array<string, mixed>}
 */
function mcpMessage(string $method, array $params = []): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
}

test('it returns matching docs from the search tool', function () {
    Storage::disk('docs')->put('billing.md', 'Open the Invoices page.');

    KnowledgeBaseServer::tool(SearchKnowledgeBase::class, ['query' => 'invoices'])
        ->assertOk()
        ->assertSee('Source: billing.md')
        ->assertSee('Open the Invoices page.');
});

test('it rejects an invalid query', function (array $arguments, string $message) {
    KnowledgeBaseServer::tool(SearchKnowledgeBase::class, $arguments)
        ->assertHasErrors([$message]);
})->with([
    'missing' => [[], 'The query field is required.'],
    'too long' => [['query' => str_repeat('a', 501)], 'The query field must not be greater than 500 characters.'],
]);

test('it lists the search tool for a client with a valid token', function () {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);

    $this->postJson(KNOWLEDGE_BASE_ENDPOINT, mcpMessage('tools/list'))
        ->assertOk()
        ->assertJsonCount(1, 'result.tools')
        ->assertJsonPath('result.tools.0.name', 'search-knowledge-base');
});

test('it runs the search tool for a client with a valid token', function () {
    Storage::disk('docs')->put('billing.md', 'Open the Invoices page.');
    Passport::actingAs(User::factory()->create(), ['mcp:use']);

    $this->postJson(KNOWLEDGE_BASE_ENDPOINT, mcpMessage('tools/call', [
        'name' => 'search-knowledge-base',
        'arguments' => ['query' => 'invoices'],
    ]))
        ->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertSee('Open the Invoices page.');
});

test('it returns 401 for a missing token', function () {
    $this->postJson(KNOWLEDGE_BASE_ENDPOINT, mcpMessage('tools/list'))
        ->assertUnauthorized();
});

test('it returns 401 for an invalid token', function () {
    $this->withToken('not-a-real-token')
        ->postJson(KNOWLEDGE_BASE_ENDPOINT, mcpMessage('tools/list'))
        ->assertUnauthorized();
});

test('it returns 401 instead of a login redirect when the client does not ask for JSON', function () {
    $this->post(KNOWLEDGE_BASE_ENDPOINT, mcpMessage('tools/list'))
        ->assertUnauthorized();
});

test('it returns 429 after 60 requests in a minute', function () {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);

    foreach (range(1, 60) as $attempt) {
        $this->postJson(KNOWLEDGE_BASE_ENDPOINT, mcpMessage('tools/list'))
            ->assertOk();
    }

    $this->postJson(KNOWLEDGE_BASE_ENDPOINT, mcpMessage('tools/list'))
        ->assertTooManyRequests();
})->skip('Throttling is temporarily disabled.');
