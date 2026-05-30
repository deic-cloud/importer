<?php

declare(strict_types=1);

namespace OCA\Importer\Provider;

use OCP\Http\Client\IClientService;

class HttpProvider implements IImportProvider {
	public function __construct(private IClientService $httpClientService) {}

	public function getId(): string          { return 'http'; }
	public function getDisplayName(): string { return 'HTTP / HTTPS'; }
	public function getAuthType(): string    { return self::AUTH_BASIC; }

	public function listDirectory(string $url, array $creds): array {
		// HTTP has no standard directory listing — return empty so UI falls back
		// to direct-URL-only mode (no folder browse).
		return [];
	}

	public function getStream(string $url, array $creds) {
		$options = ['stream' => true, 'timeout' => 0];
		if (!empty($creds['username'])) {
			$options['auth'] = [$creds['username'], $creds['password'] ?? ''];
		}
		$client   = $this->httpClientService->newClient();
		$response = $client->get($url, $options);
		$stream   = $response->getBody();
		if (is_string($stream)) {
			$h = fopen('php://temp', 'r+');
			fwrite($h, $stream);
			rewind($h);
			return $h;
		}
		// GuzzleHttp PSR-7 stream — detach to get the underlying resource
		if (method_exists($stream, 'detach')) {
			return $stream->detach();
		}
		throw new \RuntimeException('Could not obtain stream from HTTP response');
	}

	public function getFileSize(string $url, array $creds): int {
		$options = ['timeout' => 15];
		if (!empty($creds['username'])) {
			$options['auth'] = [$creds['username'], $creds['password'] ?? ''];
		}
		try {
			$client   = $this->httpClientService->newClient();
			$response = $client->head($url, $options);
			$len      = $response->getHeader('Content-Length');
			return $len ? (int) $len : -1;
		} catch (\Throwable) {
			return -1;
		}
	}
}
