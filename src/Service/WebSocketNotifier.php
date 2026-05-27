<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Notifies the Node socket.io server to push events to connected clients.
 * No-op when WEBSOCKET_BROADCAST_URL is not configured.
 */
final class WebSocketNotifier
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $broadcastUrl = '',
        private string $internalKey = '',
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function notifyUser(int $userId, string $event, array $data = []): void
    {
        $url = trim($this->broadcastUrl);
        $key = trim($this->internalKey);
        if ($url === '' || $key === '') {
            return;
        }

        try {
            $this->httpClient->request('POST', rtrim($url, '/') . '/broadcast', [
                'headers' => ['X-Internal-Key' => $key],
                'json' => [
                    'room' => 'user:' . $userId,
                    'event' => $event,
                    'data' => $data,
                ],
                'timeout' => 3,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('WebSocket broadcast failed: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** Broadcast to all authenticated clients in a shared room (e.g. `catalog` for stock updates). */
    public function notifyRoom(string $room, string $event, array $data = []): void
    {
        $url = trim($this->broadcastUrl);
        $key = trim($this->internalKey);
        if ($url === '' || $key === '') {
            return;
        }
        $room = trim($room);
        if ($room === '') {
            return;
        }

        try {
            $this->httpClient->request('POST', rtrim($url, '/') . '/broadcast', [
                'headers' => ['X-Internal-Key' => $key],
                'json' => [
                    'room' => $room,
                    'event' => $event,
                    'data' => $data,
                ],
                'timeout' => 3,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('WebSocket broadcast failed: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
