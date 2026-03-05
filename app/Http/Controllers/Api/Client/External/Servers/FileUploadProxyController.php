<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Services\External\ExternalFileUploadProxyTicketService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FileUploadProxyController extends ClientApiController
{
    public function __construct(private ExternalFileUploadProxyTicketService $ticketService)
    {
        parent::__construct();
    }

    public function __invoke(Request $request, string $externalServer): Response|JsonResponse
    {
        $ticket = trim((string) $request->query('ticket', ''));
        if ($ticket === '') {
            throw new HttpException(422, 'Upload proxy ticket is missing.');
        }

        try {
            $upstreamUrl = $this->ticketService->resolveUpstreamUrl($ticket, (int) $request->user()->id, $externalServer);
        } catch (\Throwable $exception) {
            throw new HttpException(401, $exception->getMessage(), $exception);
        }

        $file = $request->file('files');
        if (is_array($file)) {
            $file = Arr::first($file);
        }

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new HttpException(422, 'No file was uploaded.');
        }

        $targetUrl = $this->appendDirectoryQuery($upstreamUrl, $request->query('directory'));
        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new HttpException(500, 'Unable to read the uploaded file.');
        }

        try {
            $response = Http::timeout(120)
                ->connectTimeout(8)
                ->attach('files', $stream, $file->getClientOriginalName())
                ->post($targetUrl);
        } finally {
            fclose($stream);
        }

        if (!$response->successful()) {
            throw new HttpException($response->status(), $this->extractErrorMessage($response));
        }

        $payload = $response->json();
        if (is_array($payload)) {
            return new JsonResponse($payload, $response->status());
        }

        $body = $response->body();
        if (trim($body) === '') {
            return new Response('', $response->status());
        }

        return new Response($body, $response->status(), [
            'Content-Type' => $response->header('Content-Type', 'text/plain'),
        ]);
    }

    protected function appendDirectoryQuery(string $url, mixed $directory): string
    {
        if (!is_string($directory) || trim($directory) === '') {
            return $url;
        }

        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return $url;
        }

        $query = [];
        parse_str((string) ($parsed['query'] ?? ''), $query);
        $query['directory'] = $directory;
        $parsed['query'] = http_build_query($query);

        return $this->buildUrl($parsed);
    }

    protected function buildUrl(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return sprintf('%s%s%s%s%s%s%s', $scheme, $auth, $host, $port, $path, $query, $fragment);
    }

    protected function extractErrorMessage(\Illuminate\Http\Client\Response $response): string
    {
        $payload = $response->json();
        if (is_array($payload)) {
            $detail = Arr::get($payload, 'errors.0.detail');
            if (is_string($detail) && $detail !== '') {
                return $detail;
            }

            $error = Arr::get($payload, 'error');
            if (is_string($error) && $error !== '') {
                return $error;
            }

            $message = Arr::get($payload, 'message');
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        $body = trim($response->body());
        if ($body !== '') {
            return Str::limit($body, 300);
        }

        return sprintf('External file upload failed with status code %d.', $response->status());
    }
}
