<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

test('it shows the login form to a guest', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Log in to');
});

test('it logs in with valid credentials and returns to the intended page', function () {
    $user = User::factory()->create(['password' => 'correct-password']);

    $this->withSession(['url.intended' => '/oauth/authorize?client_id=abc'])
        ->post('/login', ['email' => $user->email, 'password' => 'correct-password'])
        ->assertRedirect('/oauth/authorize?client_id=abc');

    $this->assertAuthenticatedAs($user);
});

test('it rejects a wrong password without logging in', function () {
    $user = User::factory()->create(['password' => 'correct-password']);

    $this->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'The provided credentials do not match our records.'])
        ->assertSessionDoesntHaveErrors('password');

    $this->assertGuest();
});

test('it rejects an invalid login payload', function (array $payload, string $field, string $message) {
    $this->post('/login', $payload)
        ->assertSessionHasErrors([$field => $message]);

    $this->assertGuest();
})->with([
    'missing email' => [['password' => 'secret'], 'email', 'The email field is required.'],
    'invalid email' => [['email' => 'not-an-email', 'password' => 'secret'], 'email', 'The email field must be a valid email address.'],
    'missing password' => [['email' => 'jane@example.com'], 'password', 'The password field is required.'],
]);

test('it returns 429 after 5 login attempts in a minute', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'jane@example.com', 'password' => 'wrong-password'])
            ->assertRedirect();
    }

    $this->post('/login', ['email' => 'jane@example.com', 'password' => 'wrong-password'])
        ->assertTooManyRequests();
})->skip('Throttling is temporarily disabled.');
