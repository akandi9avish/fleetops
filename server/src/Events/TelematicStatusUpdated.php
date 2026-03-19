<?php

namespace Fleetbase\FleetOps\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class TelematicStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $telematicUuid;
    public string $provider;
    public string $eventType;
    public array $payload;
    public string $eventId;
    public string $sentAt;

    public function __construct(string $telematicUuid, string $provider, string $eventType, array $payload = [])
    {
        $this->telematicUuid = $telematicUuid;
        $this->provider      = $provider;
        $this->eventType     = $eventType;
        $this->payload       = $payload;
        $this->eventId       = uniqid('telematic_event_');
        $this->sentAt        = Carbon::now()->toDateTimeString();
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('telematics'),
            new Channel('telematic.' . $this->telematicUuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'telematic.status';
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->eventId,
            'event'      => $this->broadcastAs(),
            'created_at' => $this->sentAt,
            'data'       => [
                'telematic_uuid' => $this->telematicUuid,
                'provider'       => $this->provider,
                'type'           => $this->eventType,
                'payload'        => $this->payload,
            ],
        ];
    }
}
