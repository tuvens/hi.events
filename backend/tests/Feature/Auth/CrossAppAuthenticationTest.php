<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use HiEvents\Models\Account;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\AccountUser;
use HiEvents\Models\Organizer;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrossAppAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const CROSS_APP_AUTH_ROUTE = '/auth/cross-app/validate';

    private const VALIDATE_URL = 'test-main-backend.com/api/service/hi-events/session/validate';

    public function setUp(): void
    {
        parent::setUp();

        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => [
                'percentage' => 1.5,
                'fixed' => 0,
            ],
        ]);

        config(['services.main_backend' => [
            'url' => 'https://test-main-backend.com',
            'shared_secret' => 'test-shared-secret',
            'timeout' => 10,
        ]]);
    }

    public function test_valid_code_exchanges_for_local_session(): void
    {
        Http::fake([
            self::VALIDATE_URL => Http::response([
                'user_id' => 'tuvens-user-123',
                'email' => 'organiser@example.com',
                'name' => 'Test Organiser',
                'organiser' => true,
            ], 200),
        ]);

        $response = $this->postJson(self::CROSS_APP_AUTH_ROUTE, ['code' => 'one-time-code']);

        $response->assertOk();
        $response->assertHeader('X-Auth-Token');

        $user = User::where('external_user_id', 'tuvens-user-123')->first();
        $this->assertNotNull($user);
        $this->assertEquals('organiser@example.com', $user->email);

        $account = Account::where('external_account_id', 'tuvens-user-123')->first();
        $this->assertNotNull($account);
        $this->assertNotNull($account->account_verified_at);

        $this->assertTrue(
            AccountUser::where('user_id', $user->id)->where('account_id', $account->id)->exists()
        );
        $this->assertTrue(Organizer::where('account_id', $account->id)->exists());
    }

    public function test_code_is_sent_in_body_and_signed(): void
    {
        Http::fake([
            self::VALIDATE_URL => Http::response([
                'user_id' => 'tuvens-user-123',
                'email' => 'organiser@example.com',
                'organiser' => true,
            ], 200),
        ]);

        $this->postJson(self::CROSS_APP_AUTH_ROUTE, ['code' => 'one-time-code']);

        Http::assertSent(function ($request) {
            $timestamp = $request->header('X-Tuvens-Timestamp')[0] ?? null;
            $signature = $request->header('X-Tuvens-Signature')[0] ?? null;

            return $request->method() === 'POST' &&
                !str_contains($request->url(), 'one-time-code') &&
                $request->data()['code'] === 'one-time-code' &&
                $timestamp !== null &&
                $signature === hash_hmac('sha256', $timestamp . '.' . $request->body(), 'test-shared-secret');
        });
    }

    public function test_missing_code_returns_401(): void
    {
        Http::fake();

        $this->postJson(self::CROSS_APP_AUTH_ROUTE, [])->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_invalid_code_returns_401(): void
    {
        Http::fake([
            self::VALIDATE_URL => Http::response(['error' => 'Invalid code'], 401),
        ]);

        $this->postJson(self::CROSS_APP_AUTH_ROUTE, ['code' => 'bad-code'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'UNAUTHORIZED');

        $this->assertEquals(0, User::where('email', 'organiser@example.com')->count());
    }

    public function test_main_backend_error_returns_401(): void
    {
        Http::fake([
            self::VALIDATE_URL => Http::response(['error' => 'Server error'], 500),
        ]);

        $this->postJson(self::CROSS_APP_AUTH_ROUTE, ['code' => 'any-code'])->assertStatus(401);
    }

    public function test_code_in_query_string_is_ignored(): void
    {
        Http::fake();

        $this->postJson(self::CROSS_APP_AUTH_ROUTE . '?code=query-code&session=query-session', [])
            ->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_mock_session_tokens_are_not_special_cased(): void
    {
        Http::fake([
            self::VALIDATE_URL => Http::response(['error' => 'Invalid code'], 401),
        ]);

        $this->postJson(self::CROSS_APP_AUTH_ROUTE, ['code' => 'mock_session_12345'])
            ->assertStatus(401);

        $this->assertEquals(0, User::count());
    }

    public function test_existing_user_is_linked_not_duplicated(): void
    {
        $existing = User::factory()->create([
            'email' => 'organiser@example.com',
            'external_user_id' => null,
        ]);

        Http::fake([
            self::VALIDATE_URL => Http::response([
                'user_id' => 'tuvens-user-123',
                'email' => 'organiser@example.com',
                'name' => 'Test Organiser',
                'organiser' => true,
            ], 200),
        ]);

        $this->postJson(self::CROSS_APP_AUTH_ROUTE, ['code' => 'one-time-code'])->assertOk();

        $this->assertEquals(1, User::where('email', 'organiser@example.com')->count());
        $existing->refresh();
        $this->assertEquals('tuvens-user-123', $existing->external_user_id);
    }

    public function test_repeat_login_reuses_mapped_account(): void
    {
        Http::fake([
            self::VALIDATE_URL => Http::response([
                'user_id' => 'tuvens-user-123',
                'email' => 'organiser@example.com',
                'name' => 'Test Organiser',
                'organiser' => true,
            ], 200),
        ]);

        $this->postJson(self::CROSS_APP_AUTH_ROUTE, ['code' => 'code-1'])->assertOk();
        $this->postJson(self::CROSS_APP_AUTH_ROUTE, ['code' => 'code-2'])->assertOk();

        $this->assertEquals(1, User::where('external_user_id', 'tuvens-user-123')->count());
        $this->assertEquals(1, Account::where('external_account_id', 'tuvens-user-123')->count());
        // Each code is validated with tuvens-api — nothing is served from a local cache.
        Http::assertSentCount(2);
    }

    public function test_shared_account_is_not_claimed_by_tuvens_login(): void
    {
        $existing = User::factory()->create([
            'email' => 'organiser@example.com',
            'external_user_id' => null,
        ]);
        $teammate = User::factory()->create(['email' => 'teammate@example.com']);

        $sharedAccount = Account::factory()->create(['external_account_id' => null]);
        foreach ([$existing, $teammate] as $member) {
            AccountUser::create([
                'user_id' => $member->id,
                'account_id' => $sharedAccount->id,
                'role' => 'OWNER',
                'status' => 'ACTIVE',
            ]);
        }

        Http::fake([
            self::VALIDATE_URL => Http::response([
                'user_id' => 'tuvens-user-123',
                'email' => 'organiser@example.com',
                'name' => 'Test Organiser',
                'organiser' => true,
            ], 200),
        ]);

        $this->postJson(self::CROSS_APP_AUTH_ROUTE, ['code' => 'one-time-code'])->assertOk();

        $sharedAccount->refresh();
        $this->assertNull($sharedAccount->external_account_id);

        $newAccount = Account::where('external_account_id', 'tuvens-user-123')->first();
        $this->assertNotNull($newAccount);
        $this->assertNotEquals($sharedAccount->id, $newAccount->id);
    }

    public function test_response_without_organiser_flag_is_refused(): void
    {
        Http::fake([
            self::VALIDATE_URL => Http::response([
                'user_id' => 'tuvens-user-123',
                'email' => 'organiser@example.com',
                'name' => 'Not An Organiser',
            ], 200),
        ]);

        $this->postJson(self::CROSS_APP_AUTH_ROUTE, ['code' => 'one-time-code'])
            ->assertStatus(401);

        $this->assertEquals(0, User::where('external_user_id', 'tuvens-user-123')->count());
    }

    public function test_exchange_endpoint_is_throttled(): void
    {
        Http::fake([
            self::VALIDATE_URL => Http::response(['error' => 'Invalid code'], 401),
        ]);

        foreach (range(1, 10) as $i) {
            $this->postJson(self::CROSS_APP_AUTH_ROUTE, ['code' => "code-{$i}"])->assertStatus(401);
        }

        $this->postJson(self::CROSS_APP_AUTH_ROUTE, ['code' => 'code-11'])->assertStatus(429);
    }
}
