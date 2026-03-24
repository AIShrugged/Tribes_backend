<?php

namespace App\Services\Artifact;

use App\Models\ArtifactEvent;
use App\Models\ArtifactSnapshot;
use App\Models\Chat;
use Illuminate\Support\Str;

class ArtifactStateService
{
    private const SNAPSHOT_EVERY = 50;

    /**
     * Load current artifact state for a chat.
     * Reconstructs from latest snapshot + events recorded after it.
     */
    public function loadState(Chat $chat): array
    {
        $snapshot = ArtifactSnapshot::where('chat_id', $chat->id)
            ->latest('id')
            ->first();

        $eventsQuery = ArtifactEvent::where('chat_id', $chat->id)
            ->orderBy('id');

        if ($snapshot) {
            // Only replay events recorded after the snapshot
            $eventsQuery->where('id', '>', function ($sub) use ($snapshot) {
                $sub->select('id')
                    ->from('artifact_events')
                    ->where('event_id', $snapshot->last_event_id)
                    ->limit(1);
            });
        }

        $events = $eventsQuery->get();

        $state = $snapshot?->state_json ?? $this->emptyState();

        foreach ($events as $event) {
            $state = $this->applyEvent($state, $event);
        }

        return $state;
    }

    /**
     * Record a new artifact event and persist it.
     * Creates a snapshot every SNAPSHOT_EVERY events.
     */
    public function recordEvent(Chat $chat, string $type, array $payload): ArtifactEvent
    {
        $event = ArtifactEvent::create([
            'chat_id'    => $chat->id,
            'event_id'   => (string) Str::ulid(),
            'type'       => $type,
            'payload'    => $payload,
            'created_at' => now(),
        ]);

        $eventCount = ArtifactEvent::where('chat_id', $chat->id)->count();

        if ($eventCount % self::SNAPSHOT_EVERY === 0) {
            $this->createSnapshot($chat, $event);
        }

        return $event;
    }

    /**
     * Apply a single event to the state and return the updated state.
     */
    private function applyEvent(array $state, ArtifactEvent $event): array
    {
        $payload = $event->payload;

        return match ($event->type) {
            'artifact.create' => $this->applyCreate($state, $payload),
            'artifact.update' => $this->applyUpdate($state, $payload),
            'artifact.delete' => $this->applyDelete($state, $payload),
            'layout.set'      => $this->applyLayoutSet($state, $payload),
            default           => $state,
        };
    }

    private function applyCreate(array $state, array $payload): array
    {
        $id = $payload['id'];

        $state['artifacts'][$id] = [
            'id'     => $id,
            'type'   => $payload['type'],
            'title'  => $payload['title'] ?? '',
            'data'   => $payload['data'],
            'status' => 'ready',
        ];

        // Auto-append to layout if not already present
        $layoutIds = array_column($state['layout']['items'] ?? [], 'id');
        if (! in_array($id, $layoutIds)) {
            $state['layout']['items'][] = ['id' => $id];
        }

        return $state;
    }

    private function applyUpdate(array $state, array $payload): array
    {
        $id = $payload['id'];

        if (! isset($state['artifacts'][$id])) {
            return $state;
        }

        if (isset($payload['title'])) {
            $state['artifacts'][$id]['title'] = $payload['title'];
        }

        if (isset($payload['data'])) {
            $state['artifacts'][$id]['data'] = $payload['data'];
        }

        return $state;
    }

    private function applyDelete(array $state, array $payload): array
    {
        $id = $payload['id'];

        unset($state['artifacts'][$id]);

        $state['layout']['items'] = array_values(
            array_filter($state['layout']['items'] ?? [], fn ($item) => $item['id'] !== $id)
        );

        return $state;
    }

    private function applyLayoutSet(array $state, array $payload): array
    {
        $state['layout'] = $payload['layout'];

        return $state;
    }

    private function createSnapshot(Chat $chat, ArtifactEvent $lastEvent): void
    {
        $state = $this->loadState($chat);

        ArtifactSnapshot::create([
            'chat_id'       => $chat->id,
            'last_event_id' => $lastEvent->event_id,
            'state_json'    => $state,
            'created_at'    => now(),
        ]);
    }

    private function emptyState(): array
    {
        return [
            'artifacts' => [],
            'layout'    => ['items' => []],
        ];
    }
}
