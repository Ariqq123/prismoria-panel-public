<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;
use Illuminate\Support\Arr;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Pterodactyl\Models\Filters\MultiFieldServerFilter;
use Pterodactyl\Services\External\ExternalServerRepository;
use Pterodactyl\Transformers\Api\Client\ServerTransformer;
use Pterodactyl\Http\Requests\Api\Client\GetServersRequest;

class ClientController extends ClientApiController
{
    /**
     * ClientController constructor.
     */
    public function __construct(private ExternalServerRepository $externalServerRepository)
    {
        parent::__construct();
    }

    /**
     * Return all the servers available to the client making the API
     * request, including servers the user has access to as a subuser.
     */
    public function index(GetServersRequest $request): array
    {
        $user = $request->user();
        $transformer = $this->getTransformer(ServerTransformer::class);
        $source = in_array($request->query('source', 'local'), ['local', 'external', 'all'], true)
            ? $request->query('source', 'local')
            : 'local';

        if ($source === 'local') {
            $builder = $this->buildLocalServerQuery($request, $transformer);
            $servers = $builder->paginate(min($request->query('per_page', 50), 100))->appends($request->query());

            return $this->fractal->transformWith($transformer)->collection($servers)->toArray();
        }

        $items = Collection::make();
        if ($source === 'all') {
            $items = $items->merge($this->localServerCollection($request, $transformer));
        }

        $query = $this->extractSearchQuery($request);
        $externalFetchMode = strtolower(trim((string) $request->query('external_fetch', 'cached')));
        $useCachedExternal = $externalFetchMode !== 'live';
        $cachedExternalOnly = $externalFetchMode === 'cached-only';

        $items = $items->merge($this->externalServerRepository->listServersForUser($user, [
            'query' => $query,
            'prefer_cached' => $useCachedExternal,
            'cached_only' => $cachedExternalOnly,
        ]));
        $items = $items->sortBy(fn (array $server) => mb_strtolower((string) Arr::get($server, 'attributes.name', '')))
            ->values();

        $perPage = min((int) $request->query('per_page', 50), 100);
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $perPage;
        $slice = $items->slice($offset, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $slice,
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return [
            'object' => 'list',
            'data' => $slice->all(),
            'meta' => [
                'pagination' => [
                    'total' => $paginator->total(),
                    'count' => $paginator->count(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'total_pages' => $paginator->lastPage(),
                    'links' => [
                        'previous' => $paginator->previousPageUrl(),
                        'next' => $paginator->nextPageUrl(),
                    ],
                ],
            ],
        ];
    }

    protected function buildLocalServerQuery(GetServersRequest $request, ServerTransformer $transformer): QueryBuilder
    {
        $user = $request->user();
        $builder = QueryBuilder::for(
            Server::query()->with($this->getIncludesForTransformer($transformer, ['node']))
        )->allowedFilters([
            'uuid',
            'name',
            'description',
            'external_id',
            AllowedFilter::custom('*', new MultiFieldServerFilter()),
        ]);

        $type = $request->input('type');
        // Either return all the servers the user has access to because they are an admin `?type=admin` or
        // just return all the servers the user has access to because they are the owner or a subuser of the
        // server. If ?type=admin-all is passed all servers on the system will be returned to the user, rather
        // than only servers they can see because they are an admin.
        if (in_array($type, ['admin', 'admin-all'])) {
            // If they aren't an admin but want all the admin servers don't fail the request, just
            // make it a query that will never return any results back.
            if (!$user->root_admin) {
                $builder->whereRaw('1 = 2');
            } else {
                $builder = $type === 'admin-all'
                    ? $builder
                    : $builder->whereNotIn('servers.id', $user->accessibleServers()->pluck('id')->all());
            }
        } elseif ($type === 'owner') {
            $builder = $builder->where('servers.owner_id', $user->id);
        } else {
            $builder = $builder->whereIn('servers.id', $user->accessibleServers()->pluck('id')->all());
        }

        return $builder;
    }

    protected function localServerCollection(GetServersRequest $request, ServerTransformer $transformer): Collection
    {
        $servers = $this->buildLocalServerQuery($request, $transformer)->get();

        return Collection::make(
            $this->fractal->transformWith($transformer)->collection($servers)->toArray()['data'] ?? []
        );
    }

    protected function extractSearchQuery(GetServersRequest $request): ?string
    {
        $filter = $request->query('filter');
        if (!is_array($filter)) {
            return null;
        }

        $search = Arr::get($filter, '*');

        return is_string($search) && strlen(trim($search)) > 0 ? trim($search) : null;
    }

    /**
     * Returns all the subuser permissions available on the system.
     */
    public function permissions(): array
    {
        return [
            'object' => 'system_permissions',
            'attributes' => [
                'permissions' => Permission::permissions(),
            ],
        ];
    }
}
