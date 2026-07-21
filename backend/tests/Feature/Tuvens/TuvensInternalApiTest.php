<?php

declare(strict_types=1);

namespace Tests\Feature\Tuvens;

use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\Event;
use HiEvents\Models\User;
use HiEvents\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TuvensInternalApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-s2s-secret';

    public function setUp(): void
    {
        parent::setUp();

        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);

        config(['services.main_backend' => [
            'url' => 'https://api.tuvens.com',
            'shared_secret' => self::SECRET,
            'timeout' => 10,
        ]]);
        config(['app.frontend_url' => 'https://tickets.tuvens.com']);
        config(['filesystems.public' => 'public']);
        \Illuminate\Support\Facades\Storage::fake('public');
    }

    private function signedHeaders(array $body, ?string $onBehalfOf = 'tuvens-user-1', ?int $timestamp = null): array
    {
        $timestamp ??= now()->getTimestamp();
        $raw = json_encode($body);

        $headers = [
            'X-Tuvens-Timestamp' => (string)$timestamp,
            'X-Tuvens-Signature' => hash_hmac('sha256', $timestamp . '.' . $raw, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ];

        if ($onBehalfOf !== null) {
            $headers['X-On-Behalf-Of'] = $onBehalfOf;
        }

        return $headers;
    }

    private function postSigned(string $uri, array $body, array $headers): \Illuminate\Testing\TestResponse
    {
        // Each S2S call is a fresh request; drop guard state the previous
        // in-process request left behind (auth()->setUser in the action).
        $this->app['auth']->forgetGuards();

        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars($headers), json_encode($body));
    }

    private function putSigned(string $uri, array $body, array $headers): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->call('PUT', $uri, [], [], [], $this->transformHeadersToServerVars($headers), json_encode($body));
    }

    private function createBody(array $overrides = []): array
    {
        return array_merge([
            'tuvens_event_id' => 'tuvens-evt-42',
            'title' => 'Tuvens Test Event',
            'start_date' => '2026-09-01 19:00:00',
            'end_date' => '2026-09-01 23:00:00',
            'description' => 'A test event',
            'timezone' => 'Europe/Dublin',
            'currency' => 'EUR',
            'user' => ['email' => 'organiser@example.com', 'name' => 'Test Organiser'],
        ], $overrides);
    }

    public function test_rejects_missing_signature(): void
    {
        $this->postJson('/tuvens/events', $this->createBody())->assertStatus(401);
    }

    public function test_rejects_bad_signature(): void
    {
        $body = $this->createBody();
        $headers = $this->signedHeaders($body);
        $headers['X-Tuvens-Signature'] = str_repeat('0', 64);

        $this->postSigned('/tuvens/events', $body, $headers)->assertStatus(401);
    }

    public function test_rejects_stale_timestamp(): void
    {
        $body = $this->createBody();
        $headers = $this->signedHeaders($body, timestamp: now()->getTimestamp() - 400);

        $this->postSigned('/tuvens/events', $body, $headers)->assertStatus(401);
    }

    public function test_creates_draft_event_with_webhook_and_provisions_user(): void
    {
        $body = $this->createBody();

        $response = $this->postSigned('/tuvens/events', $body, $this->signedHeaders($body));

        $response->assertStatus(201)
            ->assertJsonStructure(['event_id', 'event_url', 'widget_embed_url', 'webhook_secret']);

        $eventId = $response->json('event_id');
        $this->assertEquals("https://tickets.tuvens.com/manage/event/{$eventId}", $response->json('event_url'));
        $this->assertEquals("https://tickets.tuvens.com/widget/{$eventId}", $response->json('widget_embed_url'));

        $event = Event::find($eventId);
        $this->assertEquals('DRAFT', $event->status);
        $storedAttributes = json_decode(
            (string)\Illuminate\Support\Facades\DB::table('events')->where('id', $eventId)->value('attributes'),
            true
        );
        $this->assertContains(
            ['name' => 'tuvens_event_id', 'value' => 'tuvens-evt-42', 'is_public' => false],
            $storedAttributes ?? []
        );

        $this->assertNotNull(User::where('external_user_id', 'tuvens-user-1')->first());

        $webhook = Webhook::where('event_id', $eventId)->first();
        $this->assertNotNull($webhook);
        $this->assertEquals('https://api.tuvens.com/api/webhooks/ticketing/hi-events', $webhook->url);
        $this->assertEquals($webhook->secret, $response->json('webhook_secret'));
        $this->assertContains('product.updated', $webhook->event_types);
    }

    public function test_create_is_idempotent_per_tuvens_event(): void
    {
        $body = $this->createBody();

        $first = $this->postSigned('/tuvens/events', $body, $this->signedHeaders($body));
        $second = $this->postSigned('/tuvens/events', $body, $this->signedHeaders($body));

        $first->assertStatus(201);
        $second->assertStatus(200);
        $this->assertEquals($first->json('event_id'), $second->json('event_id'));
        $this->assertEquals($first->json('webhook_secret'), $second->json('webhook_secret'));
        $this->assertEquals(1, Event::count());
    }

    public function test_unknown_user_without_identity_is_rejected(): void
    {
        $body = $this->createBody(['user' => null]);

        $this->postSigned('/tuvens/events', $body, $this->signedHeaders($body))
            ->assertStatus(422)
            ->assertJsonPath('error', 'USER_NOT_PROVISIONED');
    }

    public function test_missing_on_behalf_of_is_rejected(): void
    {
        $body = $this->createBody();

        $this->postSigned('/tuvens/events', $body, $this->signedHeaders($body, onBehalfOf: null))
            ->assertStatus(400);
    }

    public function test_promo_put_updates_only_promo_fields_and_honors_idempotency(): void
    {
        $createBody = $this->createBody();
        $eventId = $this->postSigned('/tuvens/events', $createBody, $this->signedHeaders($createBody))
            ->json('event_id');

        $putBody = [
            'title' => 'Updated Title',
            'description' => 'Updated description',
            'currency' => 'USD', // ticketing-owned: must be ignored
        ];
        $headers = $this->signedHeaders($putBody);
        $headers['X-Idempotency-Key'] = 'tuvens:42:1000';

        $this->putSigned("/tuvens/events/{$eventId}", $putBody, $headers)->assertStatus(200);

        $event = Event::find($eventId);
        $this->assertEquals('Updated Title', $event->title);
        $this->assertEquals('EUR', $event->currency);

        // Replay with the same key: no error, flagged as replay.
        $replay = $this->putSigned("/tuvens/events/{$eventId}", $putBody, $headers);
        $replay->assertStatus(200)->assertHeader('X-Idempotent-Replay', 'true');
    }

    public function test_promo_put_rejects_non_tuvens_event(): void
    {
        $body = ['title' => 'X'];

        $this->putSigned('/tuvens/events/999999', $body, $this->signedHeaders($body))
            ->assertStatus(404);
    }
}
