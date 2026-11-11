<?php

declare(strict_types=1);

namespace OCA\Importer\Provider;

use OCP\Security\IRemoteHostValidator;

/**
 * Opens an HTTP(S) download as a real stream, read as the data arrives.
 *
 * Nextcloud's HTTP client (Guzzle on the curl handler) ignores stream => true and downloads the
 * whole response into a temporary file first: no progress during the download, every file written
 * twice, temp space as large as the largest file. PHP's own http stream does not buffer.
 *
 * What the client did for us is kept: every host, also after a redirect, must pass Nextcloud's
 * remote-host check (no local or internal addresses unless the admin allows them), and the
 * credentials are dropped when a redirect leaves the original host (as Guzzle does).
 */
class HttpStream {
	public function __construct(private IRemoteHostValidator $hostValidator) {}

	/**
	 * @param array<string,string> $headers request headers, e.g. Authorization
	 * @return resource
	 */
	public function open(string $url, array $headers = [], int $maxRedirects = 10) {
		$origin = $this->hostOf($url);
		for ($hop = 0; $hop <= $maxRedirects; $hop++) {
			$host = $this->hostOf($url);
			if (!$this->hostValidator->isValid(parse_url($url, PHP_URL_HOST) ?: '')) {
				throw new \RuntimeException('Not allowed to download from ' . $host);
			}
			$send = $host === $origin ? $headers : array_diff_key($headers, ['Authorization' => 1, 'authorization' => 1]);
			$lines = "User-Agent: Nextcloud importer\r\n";
			foreach ($send as $k => $v) {
				if (strtolower($k) !== 'host') {          // PHP sends Host itself
					$lines .= $k . ': ' . $v . "\r\n";
				}
			}
			$ctx = stream_context_create(['http' => [
				'method'          => 'GET',
				'header'          => $lines,
				'follow_location' => 0,                   // redirects are followed below, host by host
				'ignore_errors'   => true,                // so that the status can be read
				'timeout'         => 120,                 // per read, not for the whole file
			]]);
			$h = @fopen($url, 'rb', false, $ctx);
			if ($h === false) {
				$err = error_get_last()['message'] ?? 'unknown error';
				throw new \RuntimeException('Could not open ' . $url . ': ' . $err);
			}
			$meta = stream_get_meta_data($h)['wrapper_data'] ?? [];
			$status = 0;
			$location = '';
			foreach ($meta as $line) {
				if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
					$status = (int)$m[1];
				} elseif (stripos($line, 'Location:') === 0) {
					$location = trim(substr($line, 9));
				}
			}
			if ($status >= 200 && $status < 300) {
				return $h;
			}
			fclose($h);
			if ($status >= 300 && $status < 400 && $location !== '') {
				$url = $this->resolve($url, $location);
				continue;
			}
			throw new \RuntimeException('Download failed: HTTP ' . $status . ' for ' . $url);
		}
		throw new \RuntimeException('Too many redirects for ' . $url);
	}

	private function hostOf(string $url): string {
		$p = parse_url($url);
		return strtolower(($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : ''));
	}

	/** An absolute URL for a Location header, which may be relative. */
	private function resolve(string $base, string $location): string {
		if (preg_match('#^https?://#i', $location)) {
			return $location;
		}
		$p = parse_url($base);
		$root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
		if (str_starts_with($location, '/')) {
			return $root . $location;
		}
		return $root . preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/') . $location;
	}
}
