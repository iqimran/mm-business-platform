<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-9';

    private const NEW_PASSWORD = 'Brand-New-Pass-7';

    private function fromSpa(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost:3000']);
    }

    private function loginAs(User $user): void
    {
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    private function changePassword(array $overrides = [])
    {
        return $this->fromSpa()->putJson('/api/v1/auth/password', array_merge([
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $overrides));
    }

    public function test_user_can_change_password(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->loginAs($user);

        $this->changePassword()
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Password changed successfully.']);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed', 'user_id' => $user->id]);

        // The session that changed the password stays signed in.
        $this->app['auth']->forgetGuards();
        $this->fromSpa()->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_change_password_requires_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->loginAs($user);

        $this->changePassword(['current_password' => 'Wrong-Password-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_new_password_must_meet_policy_and_be_confirmed(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->loginAs($user);

        $this->changePassword(['password' => 'short', 'password_confirmation' => 'short'])
            ->assertJsonValidationErrors('password');
        $this->changePassword(['password_confirmation' => 'Mismatch-Pass-1'])
            ->assertJsonValidationErrors('password');
        $this->changePassword(['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertJsonValidationErrors('password');
    }

    public function test_password_change_elsewhere_signs_out_other_sessions(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->loginAs($user);

        // Simulates the password being changed from another device.
        $user->forceFill(['password' => self::NEW_PASSWORD])->save();
        $this->app['auth']->forgetGuards();

        $this->fromSpa()->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_reset_link_is_sent_to_active_user_and_points_to_frontend(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => strtoupper($user->email)])
            ->assertOk()
            ->assertJson(['success' => true]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, config('app.frontend_url').'/reset-password?token=');
        });
    }

    public function test_forgot_password_does_not_reveal_whether_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $inactive = User::factory()->inactive()->create();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json();
        $deactivated = $this->postJson('/api/v1/auth/forgot-password', ['email' => $inactive->email])->assertOk()->json();

        $this->assertSame($known, $unknown);
        $this->assertSame($known, $deactivated);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        Notification::fake();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'someone@example.com'])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'someone@example.com'])->assertTooManyRequests();
    }

    public function test_user_can_reset_password_with_valid_token_once(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $token = Password::broker()->createToken($user);
        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Password has been reset.']);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset', 'user_id' => $user->id]);

        // Tokens are single-use.
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable();
    }

    public function test_reset_with_invalid_token_or_unknown_email_gets_generic_error(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $base = ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];

        $badToken = $this->postJson('/api/v1/auth/reset-password', $base + ['token' => 'invalid', 'email' => $user->email])
            ->assertUnprocessable()->json('errors');
        $unknownEmail = $this->postJson('/api/v1/auth/reset-password', $base + ['token' => 'invalid', 'email' => 'nobody@example.com'])
            ->assertUnprocessable()->json('errors');

        $this->assertSame($badToken, $unknownEmail);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_inactive_user_cannot_reset_password(): void
    {
        $user = User::factory()->inactive()->create(['password' => self::PASSWORD]);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }
}
