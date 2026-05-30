<?php

declare(strict_types=1);

namespace OCA\Importer\Provider;

use OCP\Http\Client\IClientService;

class WebDavProvider implements IImportProvider {
	public function __construct(private IClientService $httpClientService) {}

	public function getId(): string          { return 'webdav'; }
	public function getDisplayName(): string { return 'WebDAV'; }
	public function getAuthType(): string    { return self::AUTH_BASIC; }

	// ── IImportProvider ──────────────────────────────────────────────────────

	public function listDirectory(string $url, array $creds): array {
		$body = <<<XML
			<?xml version="1.0"?>
			<d:propfind xmlns:d="DAV:">
				<d:prop>
					<d:displayname/>
					<d:getcontentlength/>
					<d:resourcetype/>
				</d:prop>
			</d:propfind>
			XML;

		$opts = [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body'    => $body,
			'timeout' => 15,
		];
		if (!empty($creds['username'])) {
			$opts['auth'] = [$creds['username'], $creds['password'] ?? ''];
		}

		$client = $this->httpClientService->newClient();
		$resp   = $client->request('PROPFIND', rtrim($url, '/') . '/', $opts);
		$xml    = new \SimpleXMLElement((string) $resp->getBody());
		$xml->registerXPathNamespace('d', 'DAV:');

		$base    = rtrim($url, '/');
		$results = [];

		foreach ($xml->xpath('//d:response') as $i => $response) {
			if ($i === 0) continue; // first response is the collection itself

			$href    = (string) $response->xpath('d:href')[0];
			$name    = rawurldecode(basename(rtrim($href, '/')));
			$isDir   = count($response->xpath('d:propstat/d:prop/d:resourcetype/d:collection')) > 0;
			$sizeProp = $response->xpath('d:propstat/d:prop/d:getcontentlength');
			$size    = $sizeProp ? (int)(string)$sizeProp[0] : 0;

			// Build absolute URL — href may be path-only
			if (str_starts_with($href, 'http')) {
				$itemUrl = $href;
			} else {
				$parsed  = parse_url($url);
				$itemUrl = $parsed['scheme'] . '://' . $parsed['host']
					. (isset($parsed['port']) ? ':' . $parsed['port'] : '')
					. $href;
			}

			$results[] = [
				'name'   => $name,
				'url'    => $isDir ? rtrim($itemUrl, '/') . '/' : $itemUrl,
				'is_dir' => $isDir,
				'size'   => $size,
			];
		}

		return $results;
	}

	public function getStream(string $url, array $creds) {
		$opts = ['stream' => true, 'timeout' => 0];
		if (!empty($creds['username'])) {
			$opts['auth'] = [$creds['username'], $creds['password'] ?? ''];
		}
		$client = $this->httpClientService->newClient();
		$resp   = $client->get($url, $opts);
		$body   = $resp->getBody();
		if (method_exists($body, 'detach')) {
			return $body->detach();
		}
		$h = fopen('php://temp', 'r+');
		fwrite($h, (string) $body);
		rewind($h);
		return $h;
	}

	public function getFileSize(string $url, array $creds): int {
		$opts = ['timeout' => 15];
		if (!empty($creds['username'])) {
			$opts['auth'] = [$creds['username'], $creds['password'] ?? ''];
		}
		try {
			$client = $this->httpClientService->newClient();
			$resp   = $client->head($url, $opts);
			$len    = $resp->getHeader('Content-Length');
			return $len ? (int) $len : -1;
		} catch (\Throwable) {
			return -1;
		}
	}
}
