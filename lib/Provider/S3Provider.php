<?php

declare(strict_types=1);

namespace OCA\Importer\Provider;

use OCP\Http\Client\IClientService;

/**
 * S3 provider using AWS Signature Version 4.
 * Supports AWS and any S3-compatible endpoint (MinIO, ERDA-S3, etc.).
 *
 * Credentials keys: access_key, secret_key, region, endpoint (optional, defaults to AWS).
 */
class S3Provider implements IImportProvider {
	public function __construct(private IClientService $httpClientService) {}

	public function getId(): string          { return 's3'; }
	public function getDisplayName(): string { return 'S3 / Object Store'; }
	public function getAuthType(): string    { return self::AUTH_KEY; }

	// ── URL parsing ──────────────────────────────────────────────────────────

	/**
	 * Accept s3://bucket/prefix  or  https://endpoint/bucket/prefix
	 */
	private function parseS3Url(string $url, array $creds): array {
		if (str_starts_with($url, 's3://')) {
			$path   = substr($url, 5);
			[$bucket, $prefix] = explode('/', $path, 2) + ['', ''];
			$endpoint = $this->buildEndpoint($creds);
		} else {
			$parts  = parse_url($url);
			$scheme = $parts['scheme'] ?? 'https';
			$host   = $parts['host']   ?? '';
			$port   = isset($parts['port']) ? ':' . $parts['port'] : '';
			$pathParts = explode('/', ltrim($parts['path'] ?? '/', '/'), 2);
			$bucket = $pathParts[0];
			$prefix = $pathParts[1] ?? '';
			$endpoint = "$scheme://$host$port";
		}
		return ['endpoint' => $endpoint, 'bucket' => $bucket, 'prefix' => $prefix];
	}

	private function buildEndpoint(array $creds): string {
		if (!empty($creds['endpoint'])) {
			return rtrim($creds['endpoint'], '/');
		}
		$region = $creds['region'] ?? 'us-east-1';
		return "https://s3.$region.amazonaws.com";
	}

	// ── AWS Signature v4 ─────────────────────────────────────────────────────

	private function sign(string $method, string $url, array $creds, string $body = ''): array {
		$region    = $creds['region']     ?? 'us-east-1';
		$accessKey = $creds['access_key'] ?? '';
		$secretKey = $creds['secret_key'] ?? '';

		$parts  = parse_url($url);
		$host   = $parts['host'];
		if (isset($parts['port'])) $host .= ':' . $parts['port'];
		$path   = $parts['path']  ?? '/';
		$query  = $parts['query'] ?? '';

		$now      = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$date     = $now->format('Ymd');
		$datetime = $now->format('Ymd\THis\Z');

		$payloadHash = hash('sha256', $body);

		$headers = [
			'host'                 => $host,
			'x-amz-content-sha256' => $payloadHash,
			'x-amz-date'           => $datetime,
		];
		ksort($headers);

		$canonicalHeaders  = implode("\n", array_map(fn($k, $v) => "$k:$v", array_keys($headers), $headers)) . "\n";
		$signedHeadersList = implode(';', array_keys($headers));

		// Sort query string parameters
		$queryArr = [];
		if ($query) {
			parse_str($query, $queryArr);
			ksort($queryArr);
		}
		$canonicalQuery = http_build_query($queryArr, '', '&', PHP_QUERY_RFC3986);

		$canonicalRequest = implode("\n", [
			$method,
			$this->uriEncodePath($path),
			$canonicalQuery,
			$canonicalHeaders,
			$signedHeadersList,
			$payloadHash,
		]);

		$scope         = "$date/$region/s3/aws4_request";
		$stringToSign  = "AWS4-HMAC-SHA256\n$datetime\n$scope\n" . hash('sha256', $canonicalRequest);

		$signingKey = $this->hmac(
			$this->hmac($this->hmac($this->hmac("AWS4$secretKey", $date), $region), 's3'),
			'aws4_request',
			true
		);
		$signature  = hash_hmac('sha256', $stringToSign, $signingKey);

		$authHeader = "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, "
			. "SignedHeaders=$signedHeadersList, Signature=$signature";

		return array_merge($headers, ['authorization' => $authHeader]);
	}

	private function hmac(string $key, string $data, bool $raw = false): string {
		return hash_hmac('sha256', $data, $key, $raw);
	}

	private function uriEncodePath(string $path): string {
		return implode('/', array_map(fn($s) => rawurlencode($s), explode('/', $path)));
	}

	// ── HTTP helper ──────────────────────────────────────────────────────────

	private function request(string $method, string $url, array $creds, array $extraHeaders = []): \OCP\Http\Client\IResponse {
		$headers = array_merge($this->sign($method, $url, $creds), $extraHeaders);
		$client  = $this->httpClientService->newClient();
		$opts    = ['headers' => $headers, 'timeout' => 0];
		if ($method === 'GET') {
			$opts['stream'] = true;
		}
		return $client->$method === 'GET' ? $client->get($url, $opts) : $client->get($url, $opts);
	}

	// ── IImportProvider ──────────────────────────────────────────────────────

	public function listDirectory(string $url, array $creds): array {
		['endpoint' => $endpoint, 'bucket' => $bucket, 'prefix' => $prefix] = $this->parseS3Url($url, $creds);

		$prefix  = rtrim($prefix, '/');
		if ($prefix !== '') $prefix .= '/';
		$listUrl = "$endpoint/$bucket?list-type=2&delimiter=%2F" . ($prefix !== '' ? "&prefix=" . rawurlencode($prefix) : '');

		$headers = $this->sign('GET', $listUrl, $creds);
		$client  = $this->httpClientService->newClient();
		$resp    = $client->get($listUrl, ['headers' => $headers, 'timeout' => 15]);
		$xml     = new \SimpleXMLElement((string) $resp->getBody());

		$results = [];

		// Common prefixes = sub-directories
		foreach ($xml->CommonPrefixes ?? [] as $cp) {
			$p    = (string) $cp->Prefix;
			$name = basename(rtrim($p, '/'));
			$results[] = [
				'name'   => $name,
				'url'    => "s3://$bucket/$p",
				'is_dir' => true,
				'size'   => 0,
			];
		}

		// Contents = files
		foreach ($xml->Contents ?? [] as $obj) {
			$key  = (string) $obj->Key;
			if (str_ends_with($key, '/')) continue; // directory marker
			$name = basename($key);
			$results[] = [
				'name'   => $name,
				'url'    => "s3://$bucket/$key",
				'is_dir' => false,
				'size'   => (int) (string) $obj->Size,
			];
		}

		return $results;
	}

	public function getStream(string $url, array $creds) {
		['endpoint' => $endpoint, 'bucket' => $bucket, 'prefix' => $key] = $this->parseS3Url($url, $creds);
		$getUrl  = "$endpoint/$bucket/" . ltrim($key, '/');
		$headers = $this->sign('GET', $getUrl, $creds);
		$client  = $this->httpClientService->newClient();
		$resp    = $client->get($getUrl, ['headers' => $headers, 'stream' => true, 'timeout' => 0]);
		$body    = $resp->getBody();
		if (method_exists($body, 'detach')) {
			return $body->detach();
		}
		$h = fopen('php://temp', 'r+');
		fwrite($h, (string) $body);
		rewind($h);
		return $h;
	}

	public function getFileSize(string $url, array $creds): int {
		['endpoint' => $endpoint, 'bucket' => $bucket, 'prefix' => $key] = $this->parseS3Url($url, $creds);
		$headUrl = "$endpoint/$bucket/" . ltrim($key, '/');
		$headers = $this->sign('HEAD', $headUrl, $creds);
		try {
			$client = $this->httpClientService->newClient();
			$resp   = $client->head($headUrl, ['headers' => $headers, 'timeout' => 15]);
			$len    = $resp->getHeader('Content-Length');
			return $len ? (int) $len : -1;
		} catch (\Throwable) {
			return -1;
		}
	}
}
