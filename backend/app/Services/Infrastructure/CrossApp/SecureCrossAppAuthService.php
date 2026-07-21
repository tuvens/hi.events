<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\CrossApp;

use HiEvents\DomainObjects\UserDomainObject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

/**
 * Exchanges a one-time Tuvens authorization code for a verified identity.
 *
 * hi.events never sees Cognito tokens: the browser delivers a short-lived,
 * single-use code (URL fragment -> POST body), and this service validates it
 * server-to-server against tuvens-api. The signed response is the sole source
 * of identity truth; local users are provisioned from it.
 */
class SecureCrossAppAuthService
{
    private ?string $mainBackendUrl;

    private ?string $sharedSecret;

    private int $timeout;

    public function __construct(private readonly LoggerInterface $logger)
    {
        $this->mainBackendUrl = config('services.main_backend.url');
        $this->sharedSecret = config('services.main_backend.shared_secret');
        $this->timeout = (int)config('services.main_backend.timeout', 10);
    }

    /**
     * Validate a one-time authorization code with tuvens-api.
     *
     * Codes are single-use and short-lived, so results are never cached.
     *
     * @return array{user_id: string|int, email: string, name?: string, organiser?: bool, account_id?: string|int, tuvens_event_id?: string|int}|null
     */
    public function validateSessionWithMainBackend(string $code): ?array
    {
        if (!$this->mainBackendUrl || !$this->sharedSecret) {
            $this->logger->warning('Cross-app authentication configuration missing', [
                'main_backend_url' => $this->mainBackendUrl ? 'configured' : 'missing',
                'shared_secret' => $this->sharedSecret ? 'configured' : 'missing',
            ]);
            return null;
        }

        try {
            $requestUrl = $this->mainBackendUrl . '/api/service/hi-events/session/validate';
            $body = json_encode(['code' => $code], JSON_THROW_ON_ERROR);
            $timestamp = (string)now()->getTimestamp();

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Tuvens-Timestamp' => $timestamp,
                'X-Tuvens-Signature' => $this->signRequest($timestamp, $body),
            ])
                ->timeout($this->timeout)
                ->withBody($body, 'application/json')
                ->post($requestUrl);

            if (!$response->successful()) {
                $this->logger->warning('Session validation failed', [
                    'status' => $response->status(),
                ]);
                return null;
            }

            $userData = $response->json();

            if (!is_array($userData) || empty($userData['user_id']) || empty($userData['email'])) {
                $this->logger->warning('Session validation response missing required identity fields');
                return null;
            }

            $this->logger->info('Session validation successful', [
                'user_id' => $userData['user_id'],
            ]);

            return $userData;
        } catch (\Throwable $e) {
            $this->logger->error('Session validation error', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Provision (or fetch) the local user for a validated Tuvens identity.
     */
    public function createLocalSession(array $userData): UserDomainObject
    {
        $user = $this->findOrCreateUser(
            (string)$userData['user_id'],
            $userData['email'],
            $userData['name'] ?? '',
        );

        $this->logger->info('Local session created', [
            'user_id' => $user->getId(),
        ]);

        return $user;
    }

    private function signRequest(string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $this->sharedSecret);
    }

    private function findOrCreateUser(string $externalUserId, string $email, string $name): UserDomainObject
    {
        $user = \HiEvents\Models\User::where('external_user_id', $externalUserId)->first();

        if (!$user) {
            $user = DB::transaction(function () use ($externalUserId, $email, $name) {
                $user = \HiEvents\Models\User::where('email', $email)->first();

                if ($user) {
                    if (!$user->external_user_id) {
                        $user->update(['external_user_id' => $externalUserId]);
                        $this->logger->info('Updated existing user with external_user_id', [
                            'user_id' => $user->id,
                        ]);
                    }

                    return $user;
                }

                // Re-check inside the transaction so concurrent first logins
                // for the same Tuvens user create exactly one row.
                $existingUser = \HiEvents\Models\User::where('external_user_id', $externalUserId)->first();
                if ($existingUser) {
                    return $existingUser;
                }

                $user = \HiEvents\Models\User::create([
                    'external_user_id' => $externalUserId,
                    'email' => $email,
                    'first_name' => $name,
                    'last_name' => '',
                    // Identity is verified upstream by tuvens-api before a code is minted.
                    'email_verified_at' => now(),
                    // Cross-app users never authenticate with a local password.
                    'password' => bcrypt(bin2hex(random_bytes(32))),
                    'timezone' => 'UTC',
                ]);

                $this->logger->info('Created new user from cross-app auth', [
                    'user_id' => $user->id,
                ]);

                return $user;
            });
        }

        return UserDomainObject::hydrateFromModel($user);
    }
}
