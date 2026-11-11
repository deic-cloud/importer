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
		$options = [
			'timeout'   => 0,
			'nextcloud' => ['allow_local_address' => true],
		];
		if (!empty($creds['username'])) {
			$options['auth'] = [$creds['username'], $creds['password'] ?? ''];
		}
		$client   = $this->httpClientService->newClient();
		$response = $client->get($url, $options);
		$body     = $response->getBody();
		if (is_resource($body)) {
			rewind($body);
			return $body;
		}
		if (is_string($body)) {
			$h = fopen('php://temp', 'r+');
			fwrite($h, $body);
			rewind($h);
			return $h;
		}
		// PSR-7 stream object — detach to get the underlying resource
		if (is_object($body) && method_exists($body, 'detach')) {
			$res = $body->detach();
			if (is_resource($res)) return $res;
		}
		throw new \RuntimeException('Could not obtain stream from HTTP response');
	}

	public function getFileSize(string $url, array $creds): int {
		$options = [
			'timeout'   => 15,
			'nextcloud' => ['allow_local_address' => true],
		];
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
