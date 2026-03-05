<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\ExternalPanelConnection;
use Pterodactyl\Services\External\ExternalPanelConnectionService;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Requests\Api\Client\Account\ExternalPanels\StoreExternalPanelConnectionRequest;
use Pterodactyl\Http\Requests\Api\Client\Account\ExternalPanels\UpdateExternalPanelConnectionRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExternalPanelConnectionController extends ClientApiController
{
    public function __construct(private ExternalPanelConnectionService $service)
    {
        parent::__construct();
    }

    public function index(ClientApiRequest $request): array
    {
        $connections = $request->user()->externalPanelConnections()->orderByDesc('default_connection')->latest()->get();

        return [
            'object' => 'list',
            'data' => $connections->map(fn (ExternalPanelConnection $connection) => $this->transformConnection($connection))->values()->all(),
        ];
    }

    public function store(StoreExternalPanelConnectionRequest $request): array
    {
        $payload = $this->normalizeAllowedOriginAlias($request->validated());
        $connection = $this->service->create($request->user(), $payload);

        return $this->transformConnection($connection);
    }

    public function update(UpdateExternalPanelConnectionRequest $request, int $connection): array
    {
        $model = $request->user()->externalPanelConnections()->where('id', $connection)->firstOrFail();
        $payload = $this->normalizeAllowedOriginAlias($request->validated());
        $this->service->update($model, $payload);

        return $this->transformConnection($model->refresh());
    }

    public function verify(ClientApiRequest $request, int $connection): array
    {
        $model = $request->user()->externalPanelConnections()->where('id', $connection)->firstOrFail();
        $isConnected = $this->service->verify($model);

        if (!$isConnected) {
            return [
                'object' => 'external_panel_connection_verification',
                'attributes' => [
                    'connection_id' => $model->id,
                    'verified' => false,
                    'status' => 'disconnected',
                ],
            ];
        }

        return [
            'object' => 'external_panel_connection_verification',
            'attributes' => [
                'connection_id' => $model->id,
                'verified' => true,
                'status' => 'connected',
                'last_verified_at' => optional($model->refresh()->last_verified_at)->toAtomString(),
            ],
        ];
    }

    public function delete(ClientApiRequest $request, int $connection): JsonResponse
    {
        $model = $request->user()->externalPanelConnections()->where('id', $connection)->firstOrFail();
        $model->delete();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function export(ClientApiRequest $request): JsonResponse
    {
        $connections = $request->user()->externalPanelConnections()->orderByDesc('default_connection')->latest()->get();

        $payload = [
            'object' => 'external_panel_connections_export',
            'version' => 1,
            'exported_at' => now()->toAtomString(),
            'source_panel' => config('app.url', $request->getSchemeAndHttpHost()),
            'connections' => $connections->map(function (ExternalPanelConnection $connection): array {
                try {
                    $apiKey = $connection->api_key;
                } catch (\Throwable $exception) {
                    Log::warning('Failed to decrypt API key while exporting external panel connection.', [
                        'connection_id' => $connection->id,
                        'user_id' => $connection->user_id,
                        'error' => $exception->getMessage(),
                    ]);
                    $apiKey = '';
                }

                return [
                    'name' => $connection->name,
                    'panel_url' => $connection->panel_url,
                    'websocket_origin' => $connection->websocket_origin,
                    'default_connection' => (bool) $connection->default_connection,
                    'api_key' => $apiKey,
                ];
            })->values()->all(),
        ];

        $filename = 'external-panel-connections-' . now()->format('Ymd-His') . '.json';

        return new JsonResponse($payload, Response::HTTP_OK, [
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    public function import(Request $request): array
    {
        $payload = $this->readImportPayload($request);
        $entries = Arr::get($payload, 'connections');
        if (!is_array($entries)) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'The import payload must contain a connections array.');
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $defaultConnectionId = null;
        $user = $request->user();

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $skipped++;
                $errors[] = sprintf('Entry %d is invalid.', $index + 1);
                continue;
            }

            $panelUrlInput = trim((string) Arr::get($entry, 'panel_url', ''));
            $apiKey = trim((string) Arr::get($entry, 'api_key', ''));
            if ($panelUrlInput === '' || $apiKey === '') {
                $skipped++;
                $errors[] = sprintf('Entry %d is missing panel_url or api_key.', $index + 1);
                continue;
            }

            $name = Arr::get($entry, 'name');
            $name = is_string($name) ? trim($name) : null;
            $name = $name === '' ? null : $name;
            $defaultConnection = (bool) Arr::get($entry, 'default_connection', false);

            try {
                $panelUrl = $this->service->normalizeUrl($panelUrlInput);
                $websocketOrigin = $this->service->normalizeOrigin(
                    Arr::get($entry, 'websocket_origin', Arr::get($entry, 'allowed_origin')),
                    $panelUrl
                );

                $existing = $user->externalPanelConnections()
                    ->where('panel_url', $panelUrl)
                    ->where(function ($query) use ($name) {
                        if ($name === null) {
                            $query->whereNull('name');
                        } else {
                            $query->where('name', $name);
                        }
                    })
                    ->first();

                if ($existing instanceof ExternalPanelConnection) {
                    $existing->fill([
                        'websocket_origin' => $websocketOrigin,
                        'default_connection' => $defaultConnection,
                    ]);
                    $existing->api_key_encrypted = $apiKey;
                    $existing->last_verified_at = null;
                    $existing->save();
                    $updated++;

                    if ($defaultConnection) {
                        $defaultConnectionId = $existing->id;
                    }

                    continue;
                }

                $connection = new ExternalPanelConnection();
                $connection->fill([
                    'user_id' => $user->id,
                    'name' => $name,
                    'panel_url' => $panelUrl,
                    'websocket_origin' => $websocketOrigin,
                    'api_key_encrypted' => $apiKey,
                    'default_connection' => $defaultConnection,
                    'last_verified_at' => null,
                ]);
                $connection->save();
                $imported++;

                if ($defaultConnection) {
                    $defaultConnectionId = $connection->id;
                }
            } catch (\Throwable $exception) {
                $skipped++;
                $errors[] = sprintf('Entry %d failed: %s', $index + 1, $exception->getMessage());
            }
        }

        if (!is_null($defaultConnectionId)) {
            $user->externalPanelConnections()
                ->where('id', '!=', $defaultConnectionId)
                ->where('default_connection', true)
                ->update(['default_connection' => false]);
        }

        return [
            'object' => 'external_panel_connection_import',
            'attributes' => [
                'total' => count($entries),
                'imported' => $imported,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
            ],
        ];
    }

    protected function transformConnection(ExternalPanelConnection $connection): array
    {
        return [
            'object' => ExternalPanelConnection::RESOURCE_NAME,
            'attributes' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'panel_url' => $connection->panel_url,
                'websocket_origin' => $connection->websocket_origin,
                'allowed_origin' => $connection->websocket_origin,
                'default_connection' => $connection->default_connection,
                'last_verified_at' => optional($connection->last_verified_at)->toAtomString(),
                'status' => $connection->last_verified_at ? 'connected' : 'disconnected',
            ],
        ];
    }

    protected function normalizeAllowedOriginAlias(array $payload): array
    {
        if (!array_key_exists('websocket_origin', $payload) && array_key_exists('allowed_origin', $payload)) {
            $payload['websocket_origin'] = $payload['allowed_origin'];
        }

        return $payload;
    }

    protected function readImportPayload(Request $request): array
    {
        $contents = '';
        if ($request->hasFile('import_file')) {
            $file = $request->file('import_file');
            if (!$file || !$file->isValid()) {
                throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'The uploaded import file is not valid.');
            }

            $contents = (string) file_get_contents($file->getRealPath());
        } else {
            $payload = $request->input('payload');
            if (is_array($payload)) {
                return $payload;
            }

            if (is_string($payload) && trim($payload) !== '') {
                $contents = $payload;
            } else {
                $contents = (string) $request->getContent();
            }
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'Unable to parse import JSON payload.');
        }

        return $decoded;
    }
}
