<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LeonardoRRC\VotifierClient\Server\NuVotifier;
use LeonardoRRC\VotifierClient\Server\Votifier;
use LeonardoRRC\VotifierClient\Vote\ClassicVote;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\External\ExternalServerRepository;

class VotifierController extends ExternalServerApiController
{
    public function __construct(ExternalServerRepository $repository)
    {
        parent::__construct($repository);
    }

    public function sendClassic(Request $request, string $externalServer): JsonResponse
    {
        $payload = $this->validateClassicPayload($request);
        $this->assertExternalServerAccess($request, $externalServer);

        try {
            $server = (new Votifier())
                ->setHost($payload['host'])
                ->setPort((int) $payload['port'])
                ->setPublicKey($payload['publicKey']);

            $server->sendVote($this->makeVote($request, $payload['username']));

            return new JsonResponse(['message' => 'Vote sent successfully!']);
        } catch (\Throwable $exception) {
            Log::warning('External Votifier classic vote failed.', [
                'external_server' => $externalServer,
                'error' => $exception->getMessage(),
            ]);

            throw new DisplayException('Failed to send vote.');
        }
    }

    public function sendNu(Request $request, string $externalServer): JsonResponse
    {
        $payload = $this->validateClassicPayload($request);
        $this->assertExternalServerAccess($request, $externalServer);

        try {
            $server = (new NuVotifier())
                ->setHost($payload['host'])
                ->setPort((int) $payload['port'])
                ->setPublicKey($payload['publicKey']);

            $server->sendVote($this->makeVote($request, $payload['username']));

            return new JsonResponse(['message' => 'Vote sent successfully!']);
        } catch (\Throwable $exception) {
            Log::warning('External Votifier NuVotifier vote failed.', [
                'external_server' => $externalServer,
                'error' => $exception->getMessage(),
            ]);

            throw new DisplayException('Failed to send vote.');
        }
    }

    public function sendNuV2(Request $request, string $externalServer): JsonResponse
    {
        $payload = $request->validate([
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'token' => 'required|string|min:1|max:512',
            'username' => 'required|string|min:1|max:64',
        ]);
        $this->assertExternalServerAccess($request, $externalServer);

        try {
            $server = (new NuVotifier())
                ->setHost($payload['host'])
                ->setPort((int) $payload['port'])
                ->setProtocolV2(true)
                ->setToken($payload['token']);

            $server->sendVote($this->makeVote($request, $payload['username']));

            return new JsonResponse(['message' => 'Vote sent successfully!']);
        } catch (\Throwable $exception) {
            Log::warning('External Votifier NuVotifier v2 vote failed.', [
                'external_server' => $externalServer,
                'error' => $exception->getMessage(),
            ]);

            throw new DisplayException('Failed to send vote.');
        }
    }

    private function validateClassicPayload(Request $request): array
    {
        return $request->validate([
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'publicKey' => 'required|string|min:1|max:10000',
            'username' => 'required|string|min:1|max:64',
        ]);
    }

    private function assertExternalServerAccess(Request $request, string $externalServer): void
    {
        $this->repository->getServer($this->externalIdentifier($externalServer), $request->user());
    }

    private function makeVote(Request $request, string $username): ClassicVote
    {
        return (new ClassicVote())
            ->setUsername($username)
            ->setServiceName('Panel Test Vote')
            ->setAddress($request->ip());
    }
}

