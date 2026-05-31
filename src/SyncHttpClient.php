<?php

namespace Tether\Client;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Tether\Client\Support\TetherLog;
use Tether\Core\Mutation\Mutation;
use Tether\Core\Sync\PullResult;
use Tether\Core\Sync\PushResult;

/**
 * Thin HTTP wrapper for communicating with the Tether server sync endpoints.
 * All configuration is injected at construction time; no static config reads here.
 */
class SyncHttpClient
{
    /**
     * @var list<callable>
     */
    private array $middlewares = [];

    public function __construct(
        private readonly string $pushUrl,
        private readonly string $pullUrl,
        private readonly string $clientId,
    ) {}

    /**
     * Register a Guzzle-compatible middleware that will be applied to every
     * outbound HTTP request (both push and pull). Useful for adding auth headers,
     * signing requests, or logging.
     *
     * Middleware signature: `function (callable $handler): callable`
     *
     * Call this in AppServiceProvider::boot():
     *   app(\Tether\Client\SyncHttpClient::class)->withMiddleware(
     *       Middleware::mapRequest(fn ($req) => $req->withHeader('X-Token', '...'))
     *   );
     */
    public function withMiddleware(callable $middleware): static
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    /**
     * Push a batch of serialized mutations to the server.
     *
     * @param  list<Mutation>  $mutations
     */
    public function push(array $mutations): PushResult
    {
        TetherLog::debug('Sending push request', 1, [
            'mutation_count' => count($mutations),
        ]);

        $response = $this->buildRequest()->post($this->pushUrl, [
            'client_id' => $this->clientId,
            'mutations' => array_map(fn(Mutation $mutation): array => $mutation->toArray(), $mutations),
        ]);

        $response->throw();

        $json = $response->json();

        TetherLog::debug('Received push response', 1, [
            'applied_count' => count($json['applied'] ?? []),
            'rejected_count' => count($json['rejected'] ?? []),
            'conflict_count' => count($json['conflicts'] ?? []),
        ]);

        return PushResult::fromArray($json);
    }

    /**
     * Request a state snapshot of server-registered models changed after the given cursor.
     */
    public function pull(?int $cursor, ?int $limit = null): PullResult
    {
        TetherLog::debug('Sending pull request', 1, [
            'last_sync_cursor' => $cursor,
            'limit' => $limit,
        ]);

        $body = [
            'client_id'        => $this->clientId,
            'last_sync_cursor' => $cursor,
        ];

        if ($limit !== null) {
            $body['limit'] = $limit;
        }

        $response = $this->buildRequest()->post($this->pullUrl, $body);

        $response->throw();

        $json = $response->json();

        TetherLog::debug('Received pull response', 1, [
            'snapshot_count' => count($json['snapshots'] ?? []),
            'new_sync_cursor' => $json['new_sync_cursor'] ?? null,
            'has_more' => $json['has_more'] ?? false,
        ]);

        return PullResult::fromArray($json);
    }

    /**
     * Build a PendingRequest with all registered middlewares applied.
     */
    private function buildRequest(): PendingRequest
    {
        $version = \Composer\InstalledVersions::getPrettyVersion('tether/client') ?? 'dev';

        $request = Http::asJson()->withHeaders([
            'X-Tether-Client-Version' => $version,
        ]);

        foreach ($this->middlewares as $middleware) {
            $request = $request->withMiddleware($middleware);
        }

        return $request;
    }
}
