<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FileController extends ExternalServerApiController
{
    public function directory(Request $request, string $externalServer): array
    {
        return $this->proxyJson(
            $request,
            $externalServer,
            'GET',
            [
                $this->serverEndpoint($externalServer, 'files/list'),
                $this->serverEndpoint($externalServer, 'files/list-directory'),
            ],
            ['query' => $request->query()]
        );
    }

    public function contents(Request $request, string $externalServer): Response
    {
        $body = $this->repository->proxyText(
            $this->externalIdentifier($externalServer),
            $request->user(),
            'GET',
            [
                $this->serverEndpoint($externalServer, 'files/contents'),
                $this->serverEndpoint($externalServer, 'files/content'),
            ],
            $request,
            ['query' => $request->query()]
        );

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    public function download(Request $request, string $externalServer): array
    {
        return $this->proxyJson(
            $request,
            $externalServer,
            'GET',
            $this->serverEndpoint($externalServer, 'files/download'),
            ['query' => $request->query()]
        );
    }

    public function write(Request $request, string $externalServer)
    {
        return $this->proxyNoContent($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'files/write'), [
            'query' => $request->query(),
            'body' => $request->getContent(),
            'headers' => ['Content-Type' => 'text/plain'],
        ]);
    }

    public function create(Request $request, string $externalServer)
    {
        return $this->proxyNoContent($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'files/create-folder'), [
            'json' => $request->all(),
        ]);
    }

    public function rename(Request $request, string $externalServer)
    {
        return $this->proxyNoContent($request, $externalServer, 'PUT', $this->serverEndpoint($externalServer, 'files/rename'), [
            'json' => $request->all(),
        ]);
    }

    public function copy(Request $request, string $externalServer)
    {
        return $this->proxyNoContent($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'files/copy'), [
            'json' => $request->all(),
        ]);
    }

    public function compress(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'files/compress'), [
            'json' => $request->all(),
        ]);
    }

    public function decompress(Request $request, string $externalServer)
    {
        return $this->proxyNoContent($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'files/decompress'), [
            'json' => $request->all(),
        ]);
    }

    public function delete(Request $request, string $externalServer)
    {
        return $this->proxyNoContent($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'files/delete'), [
            'json' => $request->all(),
        ]);
    }

    public function chmod(Request $request, string $externalServer)
    {
        return $this->proxyNoContent($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'files/chmod'), [
            'json' => $request->all(),
        ]);
    }

    public function pull(Request $request, string $externalServer)
    {
        return $this->proxyNoContent($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'files/pull'), [
            'json' => $request->all(),
        ]);
    }
}
