<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Auth;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Services\Infrastructure\CrossApp\CrossAppAccountMappingService;
use HiEvents\Services\Infrastructure\CrossApp\SecureCrossAppAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Psr\Log\LoggerInterface;

/**
 * Exchanges a one-time Tuvens authorization code for a hi.events session.
 *
 * The SPA reads the code from the URL fragment, clears it from the address
 * bar, and POSTs it here in the request body — codes never travel in query
 * strings, so they cannot leak via logs, referrers, or browser history.
 */
class CrossAppAuthAction extends BaseAuthAction
{
    public function __construct(
        private readonly SecureCrossAppAuthService $authService,
        private readonly LoggerInterface $logger,
        private readonly JWTAuth $jwtAuth,
        private readonly AccountUserRepositoryInterface $accountUserRepository,
        private readonly CrossAppAccountMappingService $accountMappingService,
    ) {
    }

    /**
     * Route: POST /auth/cross-app/validate  (body: {"code": "..."})
     */
    public function validateSession(Request $request): JsonResponse
    {
        // Read strictly from the JSON request body — input() would also accept
        // query-string values, which is exactly the leak this flow removes.
        $code = $request->json('code');

        if (!is_string($code) || $code === '') {
            return response()->json([
                'message' => 'No authorization code provided',
                'error' => 'UNAUTHORIZED',
            ], 401);
        }

        try {
            $userData = $this->authService->validateSessionWithMainBackend($code);

            if (!$userData) {
                return response()->json([
                    'message' => 'Invalid or expired authorization code',
                    'error' => 'UNAUTHORIZED',
                ], 401);
            }

            // Defense in depth: tuvens-api's mint endpoint is organiser-gated,
            // but a session is only worth minting here for organisers — refuse
            // any validate response that doesn't say so explicitly.
            if (($userData['organiser'] ?? false) !== true) {
                $this->logger->warning('Cross-app validate response missing organiser flag');

                return response()->json([
                    'message' => 'Invalid or expired authorization code',
                    'error' => 'UNAUTHORIZED',
                ], 401);
            }

            $user = $this->authService->createLocalSession($userData);

            $mappedAccount = $this->accountMappingService->mapTuvensUserToHiEventsAccount($user, $userData);

            $accounts = $this->accountUserRepository
                ->loadRelation(new Relationship(domainObject: AccountDomainObject::class, name: 'account'))
                ->findWhere(['user_id' => $user->getId()])
                ->map(fn($accountUser) => $accountUser->getAccount());

            $userModel = \HiEvents\Models\User::find($user->getId());
            auth()->login($userModel);

            $token = $this->jwtAuth
                ->claims(['account_id' => $mappedAccount->getId()])
                ->fromUser($userModel);

            $this->logger->info('Cross-app authentication succeeded', [
                'user_id' => $user->getId(),
                'account_id' => $mappedAccount->getId(),
            ]);

            return $this->respondWithToken($token, $accounts);
        } catch (\Throwable $e) {
            $this->logger->error('Cross-app authentication error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Invalid or expired authorization code',
                'error' => 'UNAUTHORIZED',
            ], 401);
        }
    }
}
