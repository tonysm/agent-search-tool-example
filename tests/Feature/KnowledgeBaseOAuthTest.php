<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const OAUTH_REDIRECT_URI = 'http://localhost:33418/callback';

beforeEach(function () {
    $this->withoutVite();
    usePassportTestKeys();
});

/**
 * Register an MCP client the way a client like Claude Code does, returning its ID.
 */
function registerOAuthClient(): string
{
    return test()->postJson('/oauth/register', [
        'client_name' => 'Test client',
        'redirect_uris' => [OAUTH_REDIRECT_URI],
    ])->assertSuccessful()->json('client_id');
}

/**
 * Build the browser URL a client sends the user to, using the given PKCE verifier.
 */
function oauthAuthorizeUrl(string $clientId, string $codeVerifier): string
{
    return '/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => OAUTH_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'client-state',
        'code_challenge' => strtr(rtrim(base64_encode(hash('sha256', $codeVerifier, true)), '='), '+/', '-_'),
        'code_challenge_method' => 'S256',
    ]);
}

/**
 * Get the query string values from the redirect back to the client.
 *
 * @return array<string, string>
 */
function oauthRedirectQuery(TestResponse $response): array
{
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query;
}

test('it points unauthenticated clients at the OAuth metadata', function () {
    $response = $this->postJson('/mcp/knowledge-base', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
        ->assertUnauthorized();

    expect($response->headers->get('WWW-Authenticate'))->toContain('/.well-known/oauth-protected-resource/mcp/knowledge-base');
});

test('it advertises the authorization server for the MCP endpoint', function () {
    $this->getJson('/.well-known/oauth-protected-resource/mcp/knowledge-base')
        ->assertOk()
        ->assertJsonPath('resource', url('/mcp/knowledge-base'))
        ->assertJsonPath('scopes_supported', ['mcp:use']);
});

test('it sends a guest to the login page before asking for consent', function () {
    $this->get(oauthAuthorizeUrl(registerOAuthClient(), Str::random(64)))
        ->assertRedirectToRoute('login');
});

test('it issues a token that can use the MCP server after login and consent', function () {
    $user = User::factory()->create(['password' => 'correct-password']);
    $codeVerifier = Str::random(64);
    $clientId = registerOAuthClient();
    $authorizeUrl = oauthAuthorizeUrl($clientId, $codeVerifier);

    $this->get($authorizeUrl)->assertRedirectToRoute('login');

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])
        ->assertRedirectContains('/oauth/authorize');

    $consentPage = $this->get($authorizeUrl)
        ->assertOk()
        ->assertSee('Authorize Test client')
        ->assertSee($user->email);

    $approval = $this->post('/oauth/authorize', [
        'state' => '',
        'client_id' => $clientId,
        'auth_token' => $consentPage->viewData('authToken'),
    ])->assertRedirect();

    expect(oauthRedirectQuery($approval)['state'])->toBe('client-state');

    $accessToken = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => OAUTH_REDIRECT_URI,
        'code_verifier' => $codeVerifier,
        'code' => oauthRedirectQuery($approval)['code'],
    ])->assertOk()->json('access_token');

    Auth::forgetGuards();

    $this->withToken($accessToken)
        ->postJson('/mcp/knowledge-base', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'search-knowledge-base');
});

test('it returns access_denied to the client when the user cancels', function () {
    $user = User::factory()->create();
    $clientId = registerOAuthClient();

    $consentPage = $this->actingAs($user)
        ->get(oauthAuthorizeUrl($clientId, Str::random(64)))
        ->assertOk();

    $denial = $this->delete('/oauth/authorize', [
        'state' => '',
        'client_id' => $clientId,
        'auth_token' => $consentPage->viewData('authToken'),
    ])->assertRedirect();

    expect(oauthRedirectQuery($denial))
        ->toHaveKey('error', 'access_denied')
        ->not->toHaveKey('code');
});

test('it returns 429 after 10 client registrations in a minute', function () {
    foreach (range(1, 10) as $attempt) {
        registerOAuthClient();
    }

    $this->postJson('/oauth/register', [
        'client_name' => 'Test client',
        'redirect_uris' => [OAUTH_REDIRECT_URI],
    ])->assertTooManyRequests();
})->skip('Throttling is temporarily disabled.');
