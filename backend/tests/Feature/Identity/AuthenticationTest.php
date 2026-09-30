<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-9';

    /**
     * Requests from the SPA origin receive Sanctum's stateful (session) handling.
     */
    private function fromSpa(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost:3000']);
    }

    private function login(string $email, string $password = self::PASSWORD)
    {
        return $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    /**
     * Test requests share one application; drop cached guard state to mimic a new request.
     */
    private function nextRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $response = $this->login($user->email);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Logged in successfully.',
                'data' => ['user' => ['id' => $user->id, 'email' => $user->email, 'name' => $user->name]],
            ]);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id, 'entity_id' => $user->id]);
    }

    public function test_login_email_is_case_insensitive(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com', 'password' => self::PASSWORD]);

        $this->login('  OWNER@Example.com ')->assertOk();

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_response_never_exposes_secrets(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $response = $this->login($user->email)->assertOk();

        $response->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');
        $this->assertStringNotContainsString($user->password, $response->getContent());
        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent());
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->login($user->email, 'Wrong-Password-1')
            ->assertUnprocessable()
            ->assertJson(['success' => false, 'message' => 'Validation failed.'])
            ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest('web');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.login']);
    }

    public function test_unknown_and_inactive_accounts_get_the_same_error_as_wrong_password(): void
    {
        $active = User::factory()->create(['password' => self::PASSWORD]);
        $inactive = User::factory()->inactive()->create(['password' => self::PASSWORD]);

        $wrongPassword = $this->login($active->email, 'Wrong-Password-1')->json('errors');
        $unknown = $this->login('nobody@example.com')->assertUnprocessable()->json('errors');
        $deactivated = $this->login($inactive->email)->assertUnprocessable()->json('errors');

        $this->assertSame($wrongPassword, $unknown);
        $this->assertSame($wrongPassword, $deactivated);
        $this->assertGuest('web');
    }

    public function test_login_input_is_validated(): void
    {
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        for ($i = 0; $i < 5; $i++) {
            $this->login($user->email, 'Wrong-Password-1')->assertUnprocessable();
        }

        $this->login($user->email)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJson(['success' => false, 'message' => 'Too many requests. Please try again later.']);
        $this->assertGuest('web');
    }

    public function test_login_requires_an_allowed_application_origin(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertBadRequest()
            ->assertJson(['success' => false]);
    }

    public function test_authenticated_user_can_fetch_current_user(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->login($user->email)->assertOk();
        $this->nextRequest();

        $this->fromSpa()->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Operation completed successfully.',
                'data' => ['user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at->toIso8601String(),
                ],
                    'permissions' => [],
                    'branches' => [],
                ],
            ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);

        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->putJson('/api/v1/auth/password')->assertUnauthorized();
    }

    public function test_unauthenticated_non_json_request_gets_401_not_a_redirect(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_bearer_tokens_are_not_accepted(): void
    {
        $this->withToken('1|forged-token-value')->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_user_can_log_out(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->login($user->email)->assertOk();
        $this->nextRequest();

        $this->fromSpa()->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Logged out successfully.']);

        $this->assertGuest('web');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);

        $this->nextRequest();
        $this->fromSpa()->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_deactivated_user_loses_existing_session(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->login($user->email)->assertOk();

        $user->update(['is_active' => false]);
        $this->nextRequest();

        $this->fromSpa()->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertGuest('web');

        $user->update(['is_active' => true]);
        $this->nextRequest();

        // The session was destroyed, so reactivation does not revive it.
        $this->fromSpa()->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
