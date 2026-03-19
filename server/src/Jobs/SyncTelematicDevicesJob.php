<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Events\TelematicStatusUpdated;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Class SyncTelematicDevicesJob.
 *
 * Job for discovering and syncing devices from a provider.
 */
class SyncTelematicDevicesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public Telematic $telematic;
    public array $options;
    public int $tries   = 3;
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(Telematic $telematic, array $options = [])
    {
        $this->telematic = $telematic;
        $this->options   = $options;
        $this->queue     = 'telematics-sync';
    }

    /**
     * Execute the job.
     */
    public function handle(TelematicProviderRegistry $registry, TelematicService $service): void
    {
        $correlationId = \Illuminate\Support\Str::uuid()->toString();

        Log::info('Device discovery started', [
            'correlation_id' => $correlationId,
            'telematic_uuid' => $this->telematic->uuid,
            'provider'       => $this->telematic->provider,
        ]);

        try {
            $provider = $registry->resolve($this->telematic->provider);
            $provider->connect($this->telematic);

            $cursor      = null;
            $totalSynced = 0;

            do {
                $response = $provider->fetchDevices([
                    'limit'   => $this->options['limit'] ?? 100,
                    'cursor'  => $cursor,
                    'filters' => $this->options['filters'] ?? [],
                ]);

                foreach ($response['devices'] as $devicePayload) {
                    $normalizedDevice = $provider->normalizeDevice($devicePayload);
                    $service->linkDevice($this->telematic, $normalizedDevice);
                    $totalSynced++;
                }

                $cursor = $response['next_cursor'];

                Log::info('Device discovery progress', [
                    'correlation_id' => $correlationId,
                    'synced'         => $totalSynced,
                    'has_more'       => $response['has_more'],
                ]);

                event(new TelematicStatusUpdated(
                    $this->telematic->uuid,
                    $this->telematic->provider,
                    'sync_progress',
                    [
                        'correlation_id' => $correlationId,
                        'synced'         => $totalSynced,
                        'has_more'       => (bool) $response['has_more'],
                        'next_cursor'    => $cursor,
                    ]
                ));
            } while ($response['has_more'] && $cursor);

            $this->telematic->meta = array_merge($this->telematic->meta ?? [], [
                'last_sync_at'        => now()->toDateTimeString(),
                'last_sync_result'    => 'success',
                'last_synced_devices' => $totalSynced,
            ]);
            $this->telematic->save();

            Log::info('Device discovery completed', [
                'correlation_id' => $correlationId,
                'total_synced'   => $totalSynced,
            ]);

            event(new TelematicStatusUpdated(
                $this->telematic->uuid,
                $this->telematic->provider,
                'sync_completed',
                [
                    'correlation_id' => $correlationId,
                    'total_synced'   => $totalSynced,
                ]
            ));
        } catch (\Exception $e) {
            Log::error('Device discovery failed', [
                'correlation_id' => $correlationId,
                'error'          => $e->getMessage(),
            ]);

            $this->telematic->meta = array_merge($this->telematic->meta ?? [], [
                'last_sync_at'     => now()->toDateTimeString(),
                'last_sync_result' => 'failed',
                'last_sync_error'  => $e->getMessage(),
            ]);
            $this->telematic->save();

            event(new TelematicStatusUpdated(
                $this->telematic->uuid,
                $this->telematic->provider,
                'sync_failed',
                [
                    'correlation_id' => $correlationId,
                    'error'          => $e->getMessage(),
                ]
            ));

            throw $e;
        }
    }

    /**
     * Get the job ID.
     */
    public function getJobId(): string
    {
        return $this->job->getJobId() ?? \Illuminate\Support\Str::uuid()->toString();
    }
}
