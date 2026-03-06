<?php

namespace Pterodactyl\Services\AutoBackups;

use Aws\S3\S3Client;
use RuntimeException;
use GuzzleHttp\Client;
use Pterodactyl\Models\AutoBackupProfile;

class AutoBackupDestinationUploader
{
    private const DROPBOX_SINGLE_UPLOAD_MAX_BYTES = 150 * 1024 * 1024;

    /**
     * Uploads the given backup archive to the destination configured on a profile.
     *
     * @return array<string, mixed>
     */
    public function upload(AutoBackupProfile $profile, string $localPath, string $remoteFileName): array
    {
        $config = $profile->destination_config;

        return match ($profile->destination_type) {
            's3' => $this->uploadToS3($config, $localPath, $remoteFileName),
            'dropbox' => $this->uploadToDropbox($config, $localPath, $remoteFileName),
            'google_drive' => $this->uploadToGoogleDrive($config, $localPath, $remoteFileName),
            default => throw new RuntimeException('Unsupported auto backup destination type.'),
        };
    }

    /**
     * Deletes a previously uploaded object on the destination configured on a profile.
     *
     * @param array<string, mixed> $object
     */
    public function delete(AutoBackupProfile $profile, array $object): void
    {
        $config = $profile->destination_config;

        match ($profile->destination_type) {
            's3' => $this->deleteFromS3($config, $object),
            'dropbox' => $this->deleteFromDropbox($config, $object),
            'google_drive' => $this->deleteFromGoogleDrive($config, $object),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    protected function uploadToS3(array $config, string $localPath, string $remoteFileName): array
    {
        $bucket = trim((string) ($config['bucket'] ?? ''));
        $region = trim((string) ($config['region'] ?? ''));
        $accessKey = trim((string) ($config['access_key_id'] ?? ''));
        $secretKey = trim((string) ($config['secret_access_key'] ?? ''));

        if ($bucket === '' || $region === '' || $accessKey === '' || $secretKey === '') {
            throw new RuntimeException('Missing required S3 destination credentials.');
        }

        $pathPrefix = trim((string) ($config['path_prefix'] ?? ''), '/');
        $key = ltrim(($pathPrefix !== '' ? $pathPrefix . '/' : '') . $remoteFileName, '/');

        $client = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => trim((string) ($config['endpoint'] ?? '')) ?: null,
            'use_path_style_endpoint' => (bool) ($config['use_path_style'] ?? false),
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);

        $result = $client->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => fopen($localPath, 'rb'),
            'ContentType' => 'application/gzip',
        ]);

        return [
            'id' => $key,
            'path' => $key,
            'provider' => 's3',
            'url' => (string) ($result['ObjectURL'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $object
     */
    protected function deleteFromS3(array $config, array $object): void
    {
        $bucket = trim((string) ($config['bucket'] ?? ''));
        $region = trim((string) ($config['region'] ?? ''));
        $accessKey = trim((string) ($config['access_key_id'] ?? ''));
        $secretKey = trim((string) ($config['secret_access_key'] ?? ''));
        $key = trim((string) ($object['id'] ?? $object['path'] ?? ''));

        if ($bucket === '' || $region === '' || $accessKey === '' || $secretKey === '' || $key === '') {
            return;
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => trim((string) ($config['endpoint'] ?? '')) ?: null,
            'use_path_style_endpoint' => (bool) ($config['use_path_style'] ?? false),
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);

        $client->deleteObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    protected function uploadToDropbox(array $config, string $localPath, string $remoteFileName): array
    {
        $token = trim((string) ($config['access_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Missing Dropbox access token.');
        }

        $folderPath = trim((string) ($config['folder_path'] ?? ''), '/');
        $remotePath = '/' . ltrim(($folderPath !== '' ? $folderPath . '/' : '') . $remoteFileName, '/');
        $fileSize = (int) filesize($localPath);

        if ($fileSize <= self::DROPBOX_SINGLE_UPLOAD_MAX_BYTES) {
            return $this->dropboxSingleUpload($token, $localPath, $remotePath);
        }

        return $this->dropboxChunkedUpload($token, $localPath, $remotePath);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $object
     */
    protected function deleteFromDropbox(array $config, array $object): void
    {
        $token = trim((string) ($config['access_token'] ?? ''));
        $path = trim((string) ($object['path'] ?? ''));
        if ($token === '' || $path === '') {
            return;
        }

        $client = $this->httpClient();
        $client->post('https://api.dropboxapi.com/2/files/delete_v2', [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['path' => $path], JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    protected function uploadToGoogleDrive(array $config, string $localPath, string $remoteFileName): array
    {
        $accessToken = $this->googleDriveAccessToken($config);
        $folderId = trim((string) ($config['folder_id'] ?? ''));
        $fileSize = (int) filesize($localPath);

        $metadata = ['name' => $remoteFileName];
        if ($folderId !== '') {
            $metadata['parents'] = [$folderId];
        }

        $client = $this->httpClient();
        $init = $client->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&fields=id,name,webViewLink', [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type' => 'application/gzip',
                'X-Upload-Content-Length' => (string) $fileSize,
            ],
            'body' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
        ]);

        $uploadUrl = $init->getHeaderLine('Location');
        if ($uploadUrl === '') {
            throw new RuntimeException('Google Drive upload session URL was not returned.');
        }

        $upload = $client->request('PUT', $uploadUrl, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/gzip',
                'Content-Length' => (string) $fileSize,
            ],
            'body' => fopen($localPath, 'rb'),
        ]);

        $payload = json_decode((string) $upload->getBody(), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Google Drive upload response could not be parsed.');
        }

        return [
            'id' => (string) ($payload['id'] ?? ''),
            'path' => (string) ($payload['name'] ?? $remoteFileName),
            'provider' => 'google_drive',
            'url' => (string) ($payload['webViewLink'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $object
     */
    protected function deleteFromGoogleDrive(array $config, array $object): void
    {
        $fileId = trim((string) ($object['id'] ?? ''));
        if ($fileId === '') {
            return;
        }

        $accessToken = $this->googleDriveAccessToken($config);
        $client = $this->httpClient();
        $client->delete(sprintf('https://www.googleapis.com/drive/v3/files/%s?supportsAllDrives=true', rawurlencode($fileId)), [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function dropboxSingleUpload(string $token, string $localPath, string $remotePath): array
    {
        $client = $this->httpClient();
        $response = $client->post('https://content.dropboxapi.com/2/files/upload', [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/octet-stream',
                'Dropbox-API-Arg' => json_encode([
                    'path' => $remotePath,
                    'mode' => 'overwrite',
                    'autorename' => false,
                    'mute' => true,
                    'strict_conflict' => false,
                ], JSON_UNESCAPED_SLASHES),
            ],
            'body' => fopen($localPath, 'rb'),
        ]);

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Dropbox upload response could not be parsed.');
        }

        return [
            'id' => (string) ($payload['id'] ?? ''),
            'path' => (string) ($payload['path_display'] ?? $remotePath),
            'provider' => 'dropbox',
            'url' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function dropboxChunkedUpload(string $token, string $localPath, string $remotePath): array
    {
        $chunkSize = 8 * 1024 * 1024;
        $size = (int) filesize($localPath);
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open backup archive for Dropbox chunked upload.');
        }

        $client = $this->httpClient();
        $offset = 0;
        $sessionId = null;

        try {
            while ($offset < $size) {
                $remaining = $size - $offset;
                $bytesToRead = min($chunkSize, $remaining);
                $chunk = fread($handle, $bytesToRead);
                if ($chunk === false) {
                    throw new RuntimeException('Failed to read backup archive during Dropbox chunked upload.');
                }

                if ($sessionId === null) {
                    $start = $client->post('https://content.dropboxapi.com/2/files/upload_session/start', [
                        'headers' => [
                            'Authorization' => "Bearer {$token}",
                            'Content-Type' => 'application/octet-stream',
                            'Dropbox-API-Arg' => '{"close":false}',
                        ],
                        'body' => $chunk,
                    ]);
                    $startPayload = json_decode((string) $start->getBody(), true);
                    $sessionId = (string) ($startPayload['session_id'] ?? '');
                    if ($sessionId === '') {
                        throw new RuntimeException('Dropbox upload session ID was not returned.');
                    }
                    $offset += strlen($chunk);
                    continue;
                }

                $isLast = ($offset + strlen($chunk)) >= $size;
                if ($isLast) {
                    $finish = $client->post('https://content.dropboxapi.com/2/files/upload_session/finish', [
                        'headers' => [
                            'Authorization' => "Bearer {$token}",
                            'Content-Type' => 'application/octet-stream',
                            'Dropbox-API-Arg' => json_encode([
                                'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
                                'commit' => [
                                    'path' => $remotePath,
                                    'mode' => 'overwrite',
                                    'autorename' => false,
                                    'mute' => true,
                                    'strict_conflict' => false,
                                ],
                            ], JSON_UNESCAPED_SLASHES),
                        ],
                        'body' => $chunk,
                    ]);
                    $finishPayload = json_decode((string) $finish->getBody(), true);
                    if (!is_array($finishPayload)) {
                        throw new RuntimeException('Dropbox chunked upload finish response could not be parsed.');
                    }

                    return [
                        'id' => (string) ($finishPayload['id'] ?? ''),
                        'path' => (string) ($finishPayload['path_display'] ?? $remotePath),
                        'provider' => 'dropbox',
                        'url' => '',
                    ];
                }

                $client->post('https://content.dropboxapi.com/2/files/upload_session/append_v2', [
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                        'Content-Type' => 'application/octet-stream',
                        'Dropbox-API-Arg' => json_encode([
                            'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
                            'close' => false,
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                    'body' => $chunk,
                ]);

                $offset += strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        throw new RuntimeException('Dropbox chunked upload failed to finish.');
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function googleDriveAccessToken(array $config): string
    {
        $authMode = strtolower(trim((string) ($config['auth_mode'] ?? '')));
        $serviceAccountJson = trim((string) ($config['service_account_json'] ?? ''));

        if ($authMode === 'service_account') {
            return $this->googleDriveAccessTokenFromServiceAccount($serviceAccountJson);
        }

        if ($authMode === 'oauth') {
            return $this->googleDriveAccessTokenFromOauth($config);
        }

        if ($serviceAccountJson !== '') {
            return $this->googleDriveAccessTokenFromServiceAccount($serviceAccountJson);
        }

        return $this->googleDriveAccessTokenFromOauth($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function googleDriveAccessTokenFromOauth(array $config): string
    {
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $refreshToken = trim((string) ($config['refresh_token'] ?? ''));

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new RuntimeException('Missing Google Drive OAuth credentials.');
        }

        $client = $this->httpClient();
        $response = $client->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ],
        ]);

        $payload = json_decode((string) $response->getBody(), true);
        $token = is_array($payload) ? (string) ($payload['access_token'] ?? '') : '';
        if ($token === '') {
            throw new RuntimeException('Google Drive access token could not be generated.');
        }

        return $token;
    }

    protected function googleDriveAccessTokenFromServiceAccount(string $serviceAccountJson): string
    {
        $serviceAccount = $this->parseGoogleServiceAccount($serviceAccountJson);
        $now = time();

        $header = $this->base64UrlEncode((string) json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES));

        $payload = $this->base64UrlEncode((string) json_encode([
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now - 5,
            'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES));

        $message = $header . '.' . $payload;
        $signature = '';

        $signed = openssl_sign($message, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
        if (!$signed || $signature === '') {
            throw new RuntimeException('Google Drive service account private key could not sign token assertion.');
        }

        $assertion = $message . '.' . $this->base64UrlEncode($signature);

        $client = $this->httpClient();
        $response = $client->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ($token === '') {
            throw new RuntimeException('Google Drive service account access token could not be generated.');
        }

        return $token;
    }

    /**
     * @return array{client_email:string,private_key:string}
     */
    protected function parseGoogleServiceAccount(string $raw): array
    {
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $decoded = base64_decode($raw, true);
            if (!is_string($decoded)) {
                throw new RuntimeException('Google service account JSON is invalid.');
            }

            $payload = json_decode($decoded, true);
            if (!is_array($payload)) {
                throw new RuntimeException('Google service account JSON is invalid.');
            }
        }

        $clientEmail = trim((string) ($payload['client_email'] ?? ''));
        $privateKey = str_replace('\\n', "\n", (string) ($payload['private_key'] ?? ''));

        if ($clientEmail === '' || trim($privateKey) === '') {
            throw new RuntimeException('Google service account JSON must include client_email and private_key.');
        }

        return [
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
        ];
    }

    protected function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    protected function httpClient(): Client
    {
        return new Client([
            'timeout' => 900,
            'connect_timeout' => 15,
            'http_errors' => true,
        ]);
    }
}
