<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Tuvens;

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\Event;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * S2S promo-field push for tuvens-linked events (contract §5).
 *
 * PUT /tuvens/events/{event_id} — behind TuvensInternalApiMiddleware. Field
 * ownership is disjoint: this endpoint accepts ONLY the tuvens-owned promo
 * fields (title, description, dates, timezone, venue) and never touches
 * ticketing fields (products, prices, currency, capacity, sale status).
 *
 * Honors X-Idempotency-Key: a repeated key replays the original response
 * without reapplying the update.
 */
class UpdateTuvensEventAction extends BaseAction
{
    private const IDEMPOTENCY_TTL_HOURS = 24;

    public function __construct(
        private readonly UpdateEventHandler $updateEventHandler,
    ) {
    }

    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone:all'],
            'venue' => ['nullable', 'array'],
            'venue.name' => ['nullable', 'string', 'max:255'],
            'venue.address_line_1' => ['nullable', 'string', 'max:255'],
            'venue.city' => ['nullable', 'string', 'max:85'],
            'venue.state_or_region' => ['nullable', 'string', 'max:85'],
            'venue.zip_or_postal_code' => ['nullable', 'string', 'max:85'],
            'venue.country' => ['nullable', 'string', 'max:2'],
        ]);

        $event = Event::where('id', $eventId)
            ->whereJsonContains('attributes', [[
                'name' => CreateTuvensEventAction::TUVENS_EVENT_ID_ATTRIBUTE,
            ]])
            ->first();

        if (!$event) {
            return response()->json([
                'message' => 'No tuvens-linked event with this id',
                'error' => 'NOT_FOUND',
            ], 404);
        }

        $idempotencyKey = $request->header('X-Idempotency-Key');
        $cacheKey = $idempotencyKey
            ? 'tuvens-promo-put:' . $eventId . ':' . hash('sha256', $idempotencyKey)
            : null;

        if ($cacheKey && ($replay = Cache::get($cacheKey))) {
            return response()->json($replay, 200)->header('X-Idempotent-Replay', 'true');
        }

        $venue = $validated['venue'] ?? null;

        $updated = $this->updateEventHandler->handle(new UpdateEventDTO(
            title: $validated['title'] ?? $event->title,
            category: null,
            account_id: $event->account_id,
            id: $event->id,
            start_date: $validated['start_date'] ?? $event->start_date?->toDateTimeString(),
            end_date: $validated['end_date'] ?? $event->end_date?->toDateTimeString(),
            description: $validated['description'] ?? $event->description,
            timezone: $validated['timezone'] ?? $event->timezone,
            // Currency is a hi.events-owned ticketing field — never pushed here.
            currency: $event->currency,
            location_details: $venue ? new AddressDTO(
                venue_name: $venue['name'] ?? null,
                address_line_1: $venue['address_line_1'] ?? null,
                city: $venue['city'] ?? null,
                state_or_region: $venue['state_or_region'] ?? null,
                zip_or_postal_code: $venue['zip_or_postal_code'] ?? null,
                country: $venue['country'] ?? null,
            ) : null,
            status: $event->status,
        ));

        $response = [
            'event_id' => $updated->getId(),
            'updated_at' => now()->toIso8601String(),
        ];

        if ($cacheKey) {
            Cache::put($cacheKey, $response, now()->addHours(self::IDEMPOTENCY_TTL_HOURS));
        }

        return response()->json($response, 200);
    }
}
