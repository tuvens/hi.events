<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\CrossApp;

use HiEvents\Models\Account;
use HiEvents\Models\AccountUser;
use HiEvents\Models\User;
use HiEvents\Models\Organizer;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Services\Domain\Organizer\CreateDefaultOrganizerSettingsService;
use Psr\Log\LoggerInterface;

/**
 * Handles isometric account mapping between Tuvens and Hi.Events
 * 
 * This service ensures that:
 * 1. Users from Tuvens are properly mapped to Hi.Events accounts
 * 2. Account relationships are maintained bidirectionally
 * 3. New and existing users are handled consistently
 * 4. External account IDs are tracked for proper mapping
 */
class CrossAppAccountMappingService
{
    public function __construct(
        private AccountRepositoryInterface $accountRepository,
        private AccountUserRepositoryInterface $accountUserRepository,
        private CreateDefaultOrganizerSettingsService $createDefaultOrganizerSettingsService,
        private LoggerInterface $logger
    ) {}

    /**
     * Maps a Tuvens user to Hi.Events account system
     * 
     * @param UserDomainObject $user The Hi.Events user
     * @param array $tuvensUserData User data from Tuvens API containing user_id, email, name, account_id
     * @return AccountDomainObject The mapped Hi.Events account
     */
    public function mapTuvensUserToHiEventsAccount(UserDomainObject $user, array $tuvensUserData): AccountDomainObject
    {
        // The validate response may not carry a distinct account id; the
        // Tuvens user id is the stable fallback key for the 1:1 organiser case.
        $tuvensAccountId = (string) ($tuvensUserData['account_id'] ?? $tuvensUserData['user_id']);
        $tuvensUserId = (string) $tuvensUserData['user_id'];
        $userEmail = $tuvensUserData['email'];
        $userName = $tuvensUserData['name'] ?? '';

        $this->logger->info('Starting account mapping for Tuvens user', [
            'hi_events_user_id' => $user->getId(),
            'tuvens_user_id' => $tuvensUserId,
            'tuvens_account_id' => $tuvensAccountId,
            'user_email' => $userEmail,
        ]);

        // Step 1: Check if we already have an account mapped to this Tuvens account
        $existingAccount = $this->findAccountByTuvensId($tuvensAccountId);
        
        if ($existingAccount) {
            $this->logger->info('Found existing Hi.Events account for Tuvens account', [
                'hi_events_account_id' => $existingAccount->getId(),
                'tuvens_account_id' => $tuvensAccountId,
            ]);
            
            // Ensure user is associated with this account
            $this->ensureUserAccountAssociation($user, $existingAccount);
            
            // Ensure account is verified since it's a Tuvens account
            $this->ensureAccountIsVerified($existingAccount);
            
            // Ensure account has an organizer for event creation
            $this->ensureAccountHasOrganizer($existingAccount, $userEmail, $userName);
            
            return $existingAccount;
        }

        // Step 2: Reuse one of the user's existing accounts only when it is
        // safe to do so: the account must not be tuvens-mapped already and must
        // have no members other than this user. Stamping a shared account with
        // an external id would silently hand a Tuvens login control of an
        // account other people rely on, so in every other case a fresh account
        // is created instead.
        $mappableAccount = $this->findSoleMemberUnmappedAccount($user);

        if ($mappableAccount) {
            $this->logger->info('Mapping existing sole-member Hi.Events account to Tuvens account', [
                'hi_events_account_id' => $mappableAccount->getId(),
                'tuvens_account_id' => $tuvensAccountId,
            ]);

            $this->updateAccountWithTuvensMapping($mappableAccount, $tuvensAccountId, $userEmail, $userName);

            // Ensure account has an organizer for event creation
            $this->ensureAccountHasOrganizer($mappableAccount, $userEmail, $userName);

            return $mappableAccount;
        }

        // Step 3: Create new account for this Tuvens account
        $this->logger->info('Creating new Hi.Events account for Tuvens account', [
            'tuvens_account_id' => $tuvensAccountId,
            'user_email' => $userEmail,
        ]);
        
        $newAccount = $this->createAccountForTuvensUser($tuvensAccountId, $userEmail, $userName);
        $this->ensureUserAccountAssociation($user, $newAccount);
        
        // Ensure the new account has an organizer for event creation
        $this->ensureAccountHasOrganizer($newAccount, $userEmail, $userName);
        
        return $newAccount;
    }

    /**
     * Find Hi.Events account by Tuvens external account ID
     */
    private function findAccountByTuvensId(string $tuvensAccountId): ?AccountDomainObject
    {
        $account = Account::where('external_account_id', $tuvensAccountId)->first();
        
        if ($account) {
            return AccountDomainObject::hydrateFromModel($account);
        }
        
        return null;
    }

    /**
     * Find an account this user belongs to that has no Tuvens mapping and no
     * other members, i.e. one that can be claimed without affecting anyone else.
     */
    private function findSoleMemberUnmappedAccount(UserDomainObject $user): ?AccountDomainObject
    {
        foreach ($this->getUserAccounts($user) as $account) {
            $accountModel = Account::find($account->getId());

            if (!$accountModel || $accountModel->external_account_id !== null) {
                continue;
            }

            $otherMembers = AccountUser::where('account_id', $account->getId())
                ->where('user_id', '!=', $user->getId())
                ->exists();

            if (!$otherMembers) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Get all accounts associated with a user
     */
    private function getUserAccounts(UserDomainObject $user): \Illuminate\Support\Collection
    {
        return $this->accountUserRepository
            ->loadRelation(new Relationship(domainObject: AccountDomainObject::class, name: 'account'))
            ->findWhere(['user_id' => $user->getId()])
            ->map(fn($accountUser) => $accountUser->getAccount());
    }

    /**
     * Ensure user is associated with an account
     */
    // Role must be a backed value of the Role enum — LoginService calls
    // Role::from() on it, so an invalid value bricks login for the user.
    private function ensureUserAccountAssociation(UserDomainObject $user, AccountDomainObject $account, ?string $role = null): void
    {
        $existing = AccountUser::where('user_id', $user->getId())
            ->where('account_id', $account->getId())
            ->first();

        if (!$existing) {
            $accountUser = new AccountUser();
            $accountUser->user_id = $user->getId();
            $accountUser->account_id = $account->getId();
            $accountUser->role = $role ?? Role::ADMIN->value;
            $accountUser->status = 'ACTIVE';
            $accountUser->save();

            $this->logger->info('Created user-account association', [
                'user_id' => $user->getId(),
                'account_id' => $account->getId(),
                'role' => $role,
            ]);
        } else {
            $this->logger->info('User-account association already exists', [
                'user_id' => $user->getId(),
                'account_id' => $account->getId(),
            ]);
        }
    }

    /**
     * Update existing account with Tuvens mapping
     */
    private function updateAccountWithTuvensMapping(AccountDomainObject $account, string $tuvensAccountId, string $email, string $name): void
    {
        $accountModel = Account::find($account->getId());
        
        if ($accountModel) {
            $accountModel->external_account_id = $tuvensAccountId;
            
            // Update account details if they're better/more complete from Tuvens
            if (empty($accountModel->email) && !empty($email)) {
                $accountModel->email = $email;
            }
            
            if (empty($accountModel->name)) {
                $accountModel->name = $this->generateAccountName($name, $email);
            }
            
            // Mark account as verified since Tuvens users are already verified
            if (empty($accountModel->account_verified_at)) {
                $accountModel->account_verified_at = now();
            }
            
            $accountModel->save();

            $this->logger->info('Updated Hi.Events account with Tuvens mapping', [
                'hi_events_account_id' => $account->getId(),
                'tuvens_account_id' => $tuvensAccountId,
                'updated_name' => $accountModel->name,
            ]);
        }
    }

    /**
     * Create new Hi.Events account for Tuvens user
     */
    private function createAccountForTuvensUser(string $tuvensAccountId, string $email, string $name): AccountDomainObject
    {
        $account = new Account();
        $account->name = $this->generateAccountName($name, $email);
        $account->email = $email;
        $account->timezone = 'UTC';
        $account->currency_code = 'EUR';
        $account->short_id = $this->generateUniqueShortId($name, $email);
        $account->external_account_id = $tuvensAccountId;
        // Mark Tuvens accounts as verified since they're already verified in Tuvens
        $account->account_verified_at = now();
        $account->save();

        $this->logger->info('Created new Hi.Events account for Tuvens user', [
            'hi_events_account_id' => $account->id,
            'tuvens_account_id' => $tuvensAccountId,
            'account_name' => $account->name,
            'short_id' => $account->short_id,
        ]);

        return AccountDomainObject::hydrateFromModel($account);
    }

    /**
     * Generate account name from user data
     */
    private function generateAccountName(string $name, string $email): string
    {
        if (!empty($name)) {
            return $name . "'s Account";
        }
        
        // Extract name from email
        $emailName = explode('@', $email)[0];
        $emailName = ucfirst(str_replace(['.', '_', '-'], ' ', $emailName));
        
        return $emailName . "'s Account";
    }

    /**
     * Generate unique short ID for account
     */
    private function generateUniqueShortId(string $name, string $email): string
    {
        $base = 'tuvens';

        if (!empty($name)) {
            $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
        } else {
            $emailName = explode('@', $email)[0];
            $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $emailName));
        }
        
        $base = substr($base, 0, 8);
        $counter = 1;
        $shortId = $base;
        
        while (Account::where('short_id', $shortId)->exists()) {
            $shortId = $base . $counter;
            $counter++;
        }
        
        return $shortId;
    }

    /**
     * Get the Hi.Events account ID for a given Tuvens account ID
     * This is used for quick lookups during authentication
     */
    public function getHiEventsAccountId(string $tuvensAccountId): ?int
    {
        $account = Account::where('external_account_id', $tuvensAccountId)->first();
        return $account?->id;
    }

    /**
     * Get the Tuvens account ID for a given Hi.Events account ID
     * This enables reverse mapping if needed
     */
    public function getTuvensAccountId(int $hiEventsAccountId): ?string
    {
        $account = Account::find($hiEventsAccountId);
        return $account?->external_account_id;
    }

    /**
     * Validate account mapping consistency
     * This can be used for debugging and maintenance
     */
    public function validateAccountMapping(UserDomainObject $user): array
    {
        $userAccounts = $this->getUserAccounts($user);
        $mappingInfo = [];

        foreach ($userAccounts as $account) {
            $mappingInfo[] = [
                'hi_events_account_id' => $account->getId(),
                'account_name' => $account->getName(),
                'tuvens_account_id' => $this->getTuvensAccountId($account->getId()),
                'has_tuvens_mapping' => !empty($this->getTuvensAccountId($account->getId())),
            ];
        }

        return $mappingInfo;
    }

    /**
     * Ensure account has an organizer for seamless event creation
     * This auto-creates an organizer so Tuvens users don't need to manually create one
     */
    private function ensureAccountHasOrganizer(AccountDomainObject $account, string $userEmail, string $userName): void
    {
        // Check if account already has an organizer
        $existingOrganizer = Organizer::where('account_id', $account->getId())->first();
        
        if ($existingOrganizer) {
            $this->logger->info('Account already has organizer', [
                'account_id' => $account->getId(),
                'organizer_id' => $existingOrganizer->id,
                'organizer_name' => $existingOrganizer->name,
            ]);
            return;
        }

        // Create organizer for the account
        $organizer = new Organizer();
        $organizer->name = $this->generateOrganizerName($userName, $userEmail); 
        $organizer->email = $userEmail;
        $organizer->account_id = $account->getId();
        $organizer->timezone = 'UTC';
        $organizer->currency = 'EUR';
        $organizer->status = 'ACTIVE';
        $organizer->save();

        // Organizer settings must exist before events can be created for the
        // account (event creation derives homepage theme settings from them).
        $this->createDefaultOrganizerSettingsService->createOrganizerSettings(
            OrganizerDomainObject::hydrateFromModel($organizer)
        );

        $this->logger->info('Created organizer for Tuvens user account', [
            'account_id' => $account->getId(),
            'organizer_id' => $organizer->id,
            'organizer_name' => $organizer->name,
        ]);
    }

    /**
     * Generate organizer name from user data
     */
    private function generateOrganizerName(string $name, string $email): string
    {
        if (!empty($name)) {
            return $name;
        }
        
        // Extract name from email
        $emailName = explode('@', $email)[0];
        $emailName = ucfirst(str_replace(['.', '_', '-'], ' ', $emailName));
        
        return $emailName;
    }

    /**
     * Ensure account is marked as verified for Tuvens users
     */
    private function ensureAccountIsVerified(AccountDomainObject $account): void
    {
        $accountModel = Account::find($account->getId());
        
        if ($accountModel && empty($accountModel->account_verified_at)) {
            $accountModel->account_verified_at = now();
            $accountModel->save();
            
            $this->logger->info('Marked Tuvens account as verified', [
                'account_id' => $account->getId(),
                'verified_at' => $accountModel->account_verified_at,
            ]);
        }
    }
}