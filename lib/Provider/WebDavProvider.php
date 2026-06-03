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
		$requestUrl = rtrim($url, '/') . '/';
		$resp       = $client->request('PROPFIND', $requestUrl, $opts);

		$dom = new \DOMDocument();
		$dom->loadXML((string) $resp->getBody());
		$xp = new \DOMXPath($dom);
		$xp->registerNamespace('d', 'DAV:');

		// Self-path to skip the collection's own response entry
		$selfPath = rtrim(rawurldecode(parse_url($requestUrl, PHP_URL_PATH) ?? ''), '/');
		$parsed   = parse_url($url);
		$origin   = $parsed['scheme'] . '://' . $parsed['host']
			. (isset($parsed['port']) ? ':' . $parsed['port'] : '');

		$results = [];
		foreach ($xp->query('//d:response') as $response) {
			$hrefNodes = $xp->query('d:href', $response);
			if ($hrefNodes->length === 0) continue;
			$href     = trim($hrefNodes->item(0)->textContent);
			$hrefPath = rtrim(rawurldecode(parse_url($href, PHP_URL_PATH) ?? $href), '/');

			if ($hrefPath === $selfPath) continue; // skip the collection itself

			$name  = rawurldecode(basename(rtrim($href, '/')));
			$isDir = $xp->query('d:propstat/d:prop/d:resourcetype/d:collection', $response)->length > 0;
			$sizeN = $xp->query('d:propstat/d:prop/d:getcontentlength', $response);
			$size  = $sizeN->length > 0 ? (int) $sizeN->item(0)->textContent : 0;

			$itemUrl = str_starts_with($href, 'http') ? $href : $origin . $href;

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
		// With stream:true NC's getBody() already calls detach() → returns a PHP resource directly
		if (is_resource($body)) {
			return $body;
		}
		// PSR-7 stream object that hasn't been detached yet
		if (is_object($body) && method_exists($body, 'detach')) {
			$res = $body->detach();
			if (is_resource($res)) return $res;
		}
		// Fallback: buffer string response in a temp stream
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
