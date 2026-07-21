<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Tuvens;

use HiEvents\DataTransferObjects\AttributesDTO;
use HiEvents\DomainObjects\Status\WebhookStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\Event;
use HiEvents\Models\User;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\Webhook\CreateWebhookHandler;
use HiEvents\Services\Application\Handlers\Webhook\DTO\CreateWebhookDTO;
use HiEvents\Services\Infrastructure\CrossApp\CrossAppAccountMappingService;
use HiEvents\Services\Infrastructure\CrossApp\SecureCrossAppAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * S2S create for tuvens-linked events (contract §3).
 *
 * POST /tuvens/events — behind TuvensInternalApiMiddleware. Creates a draft
 * event carrying attributes[tuvens_event_id], auto-registers a per-event
 * outgoing webhook pointing at tuvens-api's receiver, and returns the URLs
 * and per-link webhook secret tuvens stores on ticketing_event_link.
 *
 * Idempotent on (account, tuvens_event_id): a repeat create returns the
 * existing link instead of a duplicate event.
 */
class CreateTuvensEventAction extends BaseAction
{
    public const TUVENS_EVENT_ID_ATTRIBUTE = 'tuvens_event_id';

    private const WEBHOOK_EVENT_TYPES = [
        'product.created',
        'product.updated',
        'product.deleted',
        'order.created',
        'order.refunded',
        'order.cancelled',
        'event.updated',
        'event.archived',
    ];

    public function __construct(
        private readonly CreateEventHandler            $createEventHandler,
        private readonly CreateWebhookHandler          $createWebhookHandler,
        private readonly SecureCrossAppAuthService     $crossAppAuthService,
        private readonly CrossAppAccountMappingService $accountMappingService,
        private readonly LoggerInterface               $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tuvens_event_id' => ['required'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone:all'],
            'currency' => ['nullable', 'string', 'size:3'],
            'venue' => ['nullable', 'array'],
            'user' => ['nullable', 'array'],
            'user.email' => ['required_with:user', 'email'],
            'user.name' => ['nullable', 'string', 'max:255'],
        ]);

        $tuvensUserId = $request->attributes->get('tuvens_user_id');

        if (!$tuvensUserId) {
            return response()->json([
                'message' => 'X-On-Behalf-Of header is required',
                'error' => 'BAD_REQUEST',
            ], 400);
        }

        $user = User::where('external_user_id', $tuvensUserId)->first();

        if (!$user && empty($validated['user']['email'])) {
            return response()->json([
                'message' => 'Unknown tuvens user and no user identity supplied for provisioning',
                'error' => 'USER_NOT_PROVISIONED',
            ], 422);
        }

        $identity = [
            'user_id' => $tuvensUserId,
            'email' => $user?->email ?? $validated['user']['email'],
            'name' => $user?->first_name ?? ($validated['user']['name'] ?? ''),
        ];

        $userDomainObject = $this->crossAppAuthService->createLocalSession($identity);
        $account = $this->accountMappingService->mapTuvensUserToHiEventsAccount($userDomainObject, $identity);

        // Domain services resolve the acting user from the auth context; act
        // as the on-behalf-of user for the remainder of this request.
        auth()->setUser(User::find($userDomainObject->getId()));

        $tuvensEventId = (string)$validated['tuvens_event_id'];

        $existingEvent = $this->findExistingLinkedEvent($account->getId(), $tuvensEventId);
        if ($existingEvent) {
            return $this->linkResponse($existingEvent->id, status: 200);
        }

        $organizer = \HiEvents\Models\Organizer::where('account_id', $account->getId())->firstOrFail();

        $event = $this->createEventHandler->handle(new CreateEventDTO(
            title: $validated['title'],
            organizer_id: $organizer->id,
            account_id: $account->getId(),
            user_id: $userDomainObject->getId(),
            start_date: $validated['start_date'],
            end_date: $validated['end_date'] ?? null,
            description: $validated['description'] ?? null,
            attributes: collect([
                new AttributesDTO(
                    name: self::TUVENS_EVENT_ID_ATTRIBUTE,
                    value: $tuvensEventId,
                    is_public: false,
                ),
            ]),
            timezone: $validated['timezone'] ?? null,
            currency: $validated['currency'] ?? null,
        ));

        $webhook = $this->createWebhookHandler->handle(new CreateWebhookDTO(
            url: rtrim((string)config('services.main_backend.url'), '/') . '/api/webhooks/ticketing/hi-events',
            eventTypes: self::WEBHOOK_EVENT_TYPES,
            userId: $userDomainObject->getId(),
            accountId: $account->getId(),
            status: WebhookStatus::ENABLED,
            eventId: $event->getId(),
        ));

        $this->logger->info('Created tuvens-linked event via S2S', [
            'event_id' => $event->getId(),
            'tuvens_event_id' => $tuvensEventId,
            'account_id' => $account->getId(),
        ]);

        return $this->linkResponse($event->getId(), status: 201, webhookSecret: $webhook->getSecret());
    }

    private function findExistingLinkedEvent(int $accountId, string $tuvensEventId): ?Event
    {
        return Event::where('account_id', $accountId)
            ->whereJsonContains('attributes', [[
                'name' => self::TUVENS_EVENT_ID_ATTRIBUTE,
                'value' => $tuvensEventId,
            ]])
            ->first();
    }

    private function linkResponse(int $eventId, int $status, ?string $webhookSecret = null): JsonResponse
    {
        $frontendUrl = rtrim((string)config('app.frontend_url'), '/');

        if ($webhookSecret === null) {
            // Idempotent replay: return the secret of the webhook registered
            // at first creation so tuvens can re-store it if it lost the row.
            $webhook = \HiEvents\Models\Webhook::where('event_id', $eventId)->first();
            $webhookSecret = $webhook?->secret;
        }

        return response()->json([
            'event_id' => $eventId,
            'event_url' => "{$frontendUrl}/manage/event/{$eventId}",
            'widget_embed_url' => "{$frontendUrl}/widget/{$eventId}",
            'webhook_secret' => $webhookSecret,
        ], $status);
    }
}
