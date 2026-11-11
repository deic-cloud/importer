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
	public function __construct(private IClientService $httpClientService, private HttpStream $httpStream) {}

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

		// No access key stored: an anonymous request, which public buckets (open
		// data on AWS etc.) answer. A request signed with an empty key is refused
		// ("AuthorizationHeaderMalformed").
		if ($accessKey === '') {
			return ['host' => $host];
		}

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

		// Sort query string parameters — avoid parse_str() which converts hyphens to underscores
		$queryArr = [];
		if ($query) {
			foreach (explode('&', $query) as $pair) {
				[$k, $v] = explode('=', $pair, 2) + ['', ''];
				$queryArr[rawurldecode($k)] = rawurldecode($v);
			}
			ksort($queryArr);
		}
		$canonicalQuery = implode('&', array_map(
			fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v),
			array_keys($queryArr), $queryArr
		));

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
			$this->hmac($this->hmac($this->hmac("AWS4$secretKey", $date, true), $region, true), 's3', true),
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

	/** Each path segment URI-encoded exactly once (the path may arrive encoded or not). */
	private function uriEncodePath(string $path): string {
		return implode('/', array_map(fn($s) => rawurlencode(rawurldecode($s)), explode('/', $path)));
	}

	/** The request URL of an object: keys may contain spaces and other characters that need encoding. */
	private function objectUrl(string $endpoint, string $bucket, string $key): string {
		return "$endpoint/$bucket/" . $this->uriEncodePath(ltrim($key, '/'));
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
		$baseUrl = "$endpoint/$bucket?list-type=2&delimiter=%2F" . ($prefix !== '' ? "&prefix=" . rawurlencode($prefix) : '');
		$client  = $this->httpClientService->newClient();
		$results = [];
		$token   = '';
		// S3 returns at most 1000 keys per request: follow the continuation token.
		do {
			$listUrl = $baseUrl . ($token !== '' ? '&continuation-token=' . rawurlencode($token) : '');
			$resp    = $client->get($listUrl, ['headers' => $this->sign('GET', $listUrl, $creds), 'timeout' => 60]);
			$xml     = new \SimpleXMLElement((string) $resp->getBody());
			$this->collect($xml, $bucket, $results);
			$token = ((string) ($xml->IsTruncated ?? '')) === 'true' ? (string) ($xml->NextContinuationToken ?? '') : '';
		} while ($token !== '');

		return $results;
	}

	private function collect(\SimpleXMLElement $xml, string $bucket, array &$results): void {
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
	}

	/** A real stream, read as it arrives (see HttpStream). */
	public function getStream(string $url, array $creds) {
		['endpoint' => $endpoint, 'bucket' => $bucket, 'prefix' => $key] = $this->parseS3Url($url, $creds);
		$getUrl = $this->objectUrl($endpoint, $bucket, $key);
		return $this->httpStream->open($getUrl, $this->sign('GET', $getUrl, $creds), 3);
	}

	public function getFileSize(string $url, array $creds): int {
		['endpoint' => $endpoint, 'bucket' => $bucket, 'prefix' => $key] = $this->parseS3Url($url, $creds);
		$headUrl = $this->objectUrl($endpoint, $bucket, $key);
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
