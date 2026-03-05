<?php

namespace Pterodactyl\BlueprintFramework\Extensions\votifiertester;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use LeonardoRRC\VotifierClient\Server\Votifier;
use LeonardoRRC\VotifierClient\Server\NuVotifier;
use LeonardoRRC\VotifierClient\Vote\ClassicVote;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;

class VotiferController extends ClientApiController
{
    public function sendClassic(Request $request, string $uuid)
    {
        $host = $request->input('host');
        $port = $request->input('port');
        $publicKey = $request->input('publicKey');
        $username = $request->input('username');

        try {
            $server = (new Votifier())
                ->setHost($host)
                ->setPort((int)$port)
                ->setPublicKey($publicKey);

            $vote = (new ClassicVote())
                ->setUsername($username)
                ->setServiceName('Your vote list')
                ->setAddress($request->ip());

            $server->sendVote($vote);

            return response()->json(['message' => 'Vote sent successfully!']);
        } catch (\Exception $e) {
            Log::error('Error sending vote: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send vote.'], 500);
        }
    }

    public function sendNu(Request $request, string $uuid)
    {
        $host = $request->input('host');
        $port = $request->input('port');
        $publicKey = $request->input('publicKey');
        $username = $request->input('username');

        try {
            $server = (new NuVotifier())
                ->setHost($host)
                ->setPort((int)$port)
                ->setPublicKey($publicKey);

            $vote = (new ClassicVote())
                ->setUsername($username)
                ->setServiceName('Your vote list')
                ->setAddress($request->ip());

            $server->sendVote($vote);

            return response()->json(['message' => 'Vote sent successfully!']);
        } catch (\Exception $e) {
            Log::error('Error sending vote: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send vote.'], 500);
        }
    }

    public function sendNuV2(Request $request, string $uuid)
    {
        $host = $request->input('host');
        $port = $request->input('port');
        $token = $request->input('token');
        $username = $request->input('username');

        try {
            $server = (new NuVotifier())
                ->setHost($host)
                ->setPort((int)$port)
                ->setProtocolV2(true)
                ->setToken($token);

            $vote = (new ClassicVote())
                ->setUsername($username)
                ->setServiceName('Your vote list')
                ->setAddress($request->ip());

            $server->sendVote($vote);

            return response()->json(['message' => 'Vote sent successfully!']);
        } catch (\Exception $e) {
            Log::error('Error sending vote: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send vote.'], 500);
        }
    }
}
