<?php

namespace Fleetbase\FleetOps\Http\Controllers;

use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\Support\IdempotencyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Class TelematicWebhookController.
 *
 * Handles webhook ingestion from telematics providers.
 */
class TelematicWebhookController extends Controller
{
    protected TelematicProviderRegistry $registry;
    protected TelematicService $service;
    protected IdempotencyManager $idempotency;

    public function __construct(
        TelematicProviderRegistry $registry,
        TelematicService $service,
        IdempotencyManager $idempotency,
    ) {
        $this->registry    = $registry;
        $this->service     = $service;
        $this->idempotency = $idempotency;
    }

    /**
     * Handle provider webhook.
     */
    public function handle(Request $request, string $providerKey): JsonResponse
    {
        $correlationId = Str::uuid()->toString();

        Log::info('Webhook received', [
            'correlation_id' => $correlationId,
            'provider'       => $providerKey,
            'headers'        => $request->headers->all(),
        ]);

        // Check idempotency
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if ($idempotencyKey && $this->idempotency->isDuplicate($idempotencyKey)) {
            Log::info('Duplicate webhook detected', [
                'correlation_id'  => $correlationId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return response()->json(['status' => 'duplicate'], 200);
        }

        // Get provider
        $provider = $this->registry->resolve($providerKey);

        // Find telematic for this provider
        $telematic = Telematic::where('provider', $providerKey)->first();

        if (!$telematic) {
            Log::warning('No telematic found for provider', [
                'correlation_id' => $correlationId,
                'provider'       => $providerKey,
            ]);

            return response()->json(['error' => 'No telematic configured'], 404);
        }

        // Validate signature
        $signature   = $request->header('X-Webhook-Signature');
        $credentials = $this->service->getCredentials($telematic);

        if ($signature && !$provider->validateWebhookSignature($request->getContent(), $signature, $credentials)) {
            Log::warning('Invalid webhook signature', [
                'correlation_id' => $correlationId,
                'provider'       => $providerKey,
            ]);

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        // Process webhook
        try {
            $result = $provider->processWebhook($request->all(), $request->headers->all());

            $linkedDevicesByExternalId = [];

            // Link devices
            foreach (($result['devices'] ?? []) as $deviceData) {
                $device = $this->service->linkDevice($telematic, $deviceData);
                $deviceExternalId = data_get($deviceData, 'external_id', data_get($deviceData, 'device_id'));
                if ($deviceExternalId) {
                    $linkedDevicesByExternalId[(string) $deviceExternalId] = $device;
                }
            }

            // Store normalized events
            foreach (($result['events'] ?? []) as $eventData) {
                $eventDevice = $this->resolveLinkedDevice($telematic, $linkedDevicesByExternalId, $eventData);

                DeviceEvent::create([
                    'company_uuid' => $telematic->company_uuid,
                    'device_uuid'  => $eventDevice?->uuid,
                    'payload'      => data_get($eventData, 'payload', $eventData),
                    'meta'         => [
                        'telematic_uuid' => $telematic->uuid,
                        'provider'       => $telematic->provider,
                        'raw'            => $eventData,
                    ],
                    'event_type' => data_get($eventData, 'event_type', 'telematic_event'),
                    'severity'   => data_get($eventData, 'severity', 'info'),
                    'ident'      => data_get($eventData, 'external_id'),
                    'provider'   => $telematic->provider,
                    'state'      => data_get($eventData, 'state'),
                    'code'       => data_get($eventData, 'code'),
                    'reason'     => data_get($eventData, 'reason'),
                    'comment'    => data_get($eventData, 'message'),
                ]);
            }

            // Store latest normalized sensor readings
            foreach (($result['sensors'] ?? []) as $index => $sensorData) {
                $sensorDevice = $this->resolveLinkedDevice($telematic, $linkedDevicesByExternalId, $sensorData);
                $sensorInternalId = (string) (
                    data_get($sensorData, 'external_id')
                    ?? data_get($sensorData, 'sensor_id')
                    ?? data_get($sensorData, 'name')
                    ?? data_get($sensorData, 'sensor_type', 'sensor') . '-' . $index
                );

                $sensor = Sensor::firstOrNew([
                    'telematic_uuid' => $telematic->uuid,
                    'internal_id'    => $sensorInternalId,
                ]);

                $sensor->company_uuid    = $telematic->company_uuid;
                $sensor->device_uuid     = $sensorDevice?->uuid;
                $sensor->name            = data_get($sensorData, 'name', data_get($sensorData, 'sensor_type', 'Sensor'));
                $sensor->type            = data_get($sensorData, 'sensor_type', 'generic');
                $sensor->unit            = data_get($sensorData, 'unit');
                $sensor->last_value      = (string) data_get($sensorData, 'value', '');
                $sensor->last_reading_at = data_get($sensorData, 'recorded_at', now()->toDateTimeString());
                $sensor->status          = data_get($sensorData, 'status', 'active');
                $sensor->meta            = array_merge($sensor->meta ?? [], [
                    'telematic_uuid' => $telematic->uuid,
                    'provider'       => $telematic->provider,
                    'raw'            => $sensorData,
                ]);
                $sensor->save();
            }

            // Mark as processed
            if ($idempotencyKey) {
                $this->idempotency->markProcessed($idempotencyKey);
            }

            Log::info('Webhook processed successfully', [
                'correlation_id' => $correlationId,
                'devices_count'  => count($result['devices'] ?? []),
                'events_count'   => count($result['events'] ?? []),
                'sensors_count'  => count($result['sensors'] ?? []),
            ]);

            return response()->json(['status' => 'processed'], 200);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'correlation_id' => $correlationId,
                'error'          => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle custom provider ingest.
     */
    public function ingest(Request $request, string $id): JsonResponse
    {
        $telematic     = Telematic::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();
        $correlationId = Str::uuid()->toString();

        Log::info('Custom ingest received', [
            'correlation_id' => $correlationId,
            'telematic_uuid' => $id,
        ]);

        // Check idempotency
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if ($idempotencyKey && $this->idempotency->isDuplicate($idempotencyKey)) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        try {
            // Process devices
            if ($request->has('devices')) {
                foreach ($request->input('devices') as $deviceData) {
                    $this->service->linkDevice($telematic, $deviceData);
                }
            }

            // Mark as processed
            if ($idempotencyKey) {
                $this->idempotency->markProcessed($idempotencyKey);
            }

            Log::info('Custom ingest processed', [
                'correlation_id' => $correlationId,
                'devices_count'  => count($request->input('devices', [])),
            ]);

            return response()->json(['status' => 'ingested'], 200);
        } catch (\Exception $e) {
            Log::error('Custom ingest failed', [
                'correlation_id' => $correlationId,
                'error'          => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Ingest failed'], 500);
        }
    }

    /**
     * Resolve a linked device for normalized event/sensor payloads.
     *
     * @param  array<string, Device>  $linkedDevicesByExternalId
     */
    protected function resolveLinkedDevice(Telematic $telematic, array $linkedDevicesByExternalId, ?array $payload = null): ?Device
    {
        $externalId = (string) (
            data_get($payload, 'device_external_id')
            ?? data_get($payload, 'device_id')
            ?? data_get($payload, 'external_id')
            ?? ''
        );

        if ($externalId !== '' && isset($linkedDevicesByExternalId[$externalId])) {
            return $linkedDevicesByExternalId[$externalId];
        }

        if (count($linkedDevicesByExternalId) === 1) {
            return array_values($linkedDevicesByExternalId)[0];
        }

        return Device::where('telematic_uuid', $telematic->uuid)->latest('updated_at')->first();
    }
}
