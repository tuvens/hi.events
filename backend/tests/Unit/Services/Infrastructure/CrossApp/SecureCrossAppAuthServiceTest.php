<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\CrossApp;

use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\User;
use HiEvents\Services\Infrastructure\CrossApp\SecureCrossAppAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SecureCrossAppAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_SECRET = 'test-shared-secret';

    private SecureCrossAppAuthService $service;

    public function setUp(): void
    {
        parent::setUp();

        config(['services.main_backend' => [
            'url' => 'https://test-main-backend.com',
            'shared_secret' => self::SHARED_SECRET,
            'timeout' => 10,
        ]]);

        $this->service = new SecureCrossAppAuthService(new NullLogger());
    }

    public function test_validate_session_success_and_request_signature(): void
    {
        $code = 'one-time-code-123';
        $userData = [
            'user_id' => 'tuvens-user-123',
            'email' => 'organiser@example.com',
            'name' => 'Test Organiser',
            'organiser' => true,
        ];

        Http::fake([
            'test-main-backend.com/api/service/hi-events/session/validate' => Http::response($userData, 200),
        ]);

        $result = $this->service->validateSessionWithMainBackend($code);

        $this->assertEquals($userData, $result);

        Http::assertSent(function ($request) use ($code) {
            $timestamp = $request->header('X-Tuvens-Timestamp')[0] ?? null;
            $signature = $request->header('X-Tuvens-Signature')[0] ?? null;
            $expectedSignature = $timestamp === null
                ? null
                : hash_hmac('sha256', $timestamp . '.' . $request->body(), self::SHARED_SECRET);

            return $request->url() === 'https://test-main-backend.com/api/service/hi-events/session/validate' &&
                $request->method() === 'POST' &&
                $request->data()['code'] === $code &&
                $timestamp !== null &&
                $signature !== null &&
                hash_equals($expectedSignature, $signature);
        });
    }

    public function test_validate_session_failure_returns_null(): void
    {
        Http::fake([
            'test-main-backend.com/api/service/hi-events/session/validate' => Http::response([
                'error' => 'Invalid code',
            ], 401),
        ]);

        $this->assertNull($this->service->validateSessionWithMainBackend('bad-code'));
    }

    public function test_validate_session_with_missing_config_returns_null_without_request(): void
    {
        config(['services.main_backend.shared_secret' => null]);
        $service = new SecureCrossAppAuthService(new NullLogger());

        Http::fake();

        $this->assertNull($service->validateSessionWithMainBackend('any-code'));
        Http::assertNothingSent();
    }

    public function test_validate_session_rejects_response_missing_identity_fields(): void
    {
        Http::fake([
            'test-main-backend.com/api/service/hi-events/session/validate' => Http::response([
                'email' => 'organiser@example.com',
            ], 200),
        ]);

        $this->assertNull($this->service->validateSessionWithMainBackend('code'));
    }

    public function test_validate_session_is_never_cached(): void
    {
        $userData = [
            'user_id' => 'tuvens-user-123',
            'email' => 'organiser@example.com',
        ];

        Http::fake([
            'test-main-backend.com/api/service/hi-events/session/validate' => Http::response($userData, 200),
        ]);

        $this->service->validateSessionWithMainBackend('code-1');
        $this->service->validateSessionWithMainBackend('code-1');

        Http::assertSentCount(2);
    }

    public function test_create_local_session_creates_new_user(): void
    {
        $userData = [
            'user_id' => 'tuvens-user-123',
            'email' => 'organiser@example.com',
            'name' => 'Test Organiser',
        ];

        $result = $this->service->createLocalSession($userData);

        $this->assertInstanceOf(UserDomainObject::class, $result);
        $this->assertEquals($userData['email'], $result->getEmail());

        $user = User::where('external_user_id', 'tuvens-user-123')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_create_local_session_links_existing_user_by_email(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'organiser@example.com',
            'external_user_id' => null,
        ]);

        $result = $this->service->createLocalSession([
            'user_id' => 'tuvens-user-123',
            'email' => 'organiser@example.com',
            'name' => 'Updated Name',
        ]);

        $this->assertInstanceOf(UserDomainObject::class, $result);
        $this->assertEquals($existingUser->id, $result->getId());

        $existingUser->refresh();
        $this->assertEquals('tuvens-user-123', $existingUser->external_user_id);
    }

    public function test_create_local_session_is_idempotent_for_same_external_user(): void
    {
        $userData = [
            'user_id' => 'tuvens-user-123',
            'email' => 'organiser@example.com',
            'name' => 'Test Organiser',
        ];

        $first = $this->service->createLocalSession($userData);
        $second = $this->service->createLocalSession($userData);

        $this->assertEquals($first->getId(), $second->getId());
        $this->assertEquals(1, User::where('external_user_id', 'tuvens-user-123')->count());
    }
}
