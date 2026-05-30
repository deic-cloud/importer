<?php

declare(strict_types=1);

namespace OCA\Importer\Provider;

class FtpProvider implements IImportProvider {
	public function getId(): string          { return 'ftp'; }
	public function getDisplayName(): string { return 'FTP'; }
	public function getAuthType(): string    { return self::AUTH_BASIC; }

	// ── Helpers ──────────────────────────────────────────────────────────────

	/** @return resource (FTP connection) */
	private function connect(string $host, int $port, array $creds) {
		$ftp = ftp_connect($host, $port, 15);
		if ($ftp === false) {
			throw new \RuntimeException("FTP: cannot connect to $host:$port");
		}
		$user = $creds['username'] ?? 'anonymous';
		$pass = $creds['password'] ?? 'anonymous@';
		if (!ftp_login($ftp, $user, $pass)) {
			ftp_close($ftp);
			throw new \RuntimeException("FTP: authentication failed for $user@$host");
		}
		ftp_pasv($ftp, true);
		return $ftp;
	}

	private function parseUrl(string $url): array {
		$parts = parse_url($url);
		return [
			'host' => $parts['host'] ?? '',
			'port' => $parts['port'] ?? 21,
			'path' => $parts['path'] ?? '/',
		];
	}

	// ── IImportProvider ──────────────────────────────────────────────────────

	public function listDirectory(string $url, array $creds): array {
		['host' => $host, 'port' => $port, 'path' => $path] = $this->parseUrl($url);
		$ftp = $this->connect($host, $port, $creds);
		try {
			$raw = ftp_rawlist($ftp, $path);
			if ($raw === false) {
				throw new \RuntimeException("FTP: cannot list $path");
			}
		} finally {
			ftp_close($ftp);
		}

		$base    = rtrim($url, '/');
		$results = [];
		foreach ($raw as $line) {
			// Typical Unix listing: "drwxr-xr-x  2 user group  4096 Jan  1 00:00 dirname"
			$parts = preg_split('/\s+/', $line, 9);
			if (count($parts) < 9) continue;
			$name   = $parts[8];
			if ($name === '.' || $name === '..') continue;
			$isDir  = str_starts_with($parts[0], 'd');
			$size   = (int) $parts[4];
			$results[] = [
				'name'   => $name,
				'url'    => $base . '/' . $name,
				'is_dir' => $isDir,
				'size'   => $size,
			];
		}
		return $results;
	}

	public function getStream(string $url, array $creds) {
		['host' => $host, 'port' => $port, 'path' => $path] = $this->parseUrl($url);
		$ftp = $this->connect($host, $port, $creds);

		$tmp = fopen('php://temp', 'r+');
		if (!ftp_fget($ftp, $tmp, $path, FTP_BINARY)) {
			ftp_close($ftp);
			fclose($tmp);
			throw new \RuntimeException("FTP: failed to download $path");
		}
		ftp_close($ftp);
		rewind($tmp);
		return $tmp;
	}

	public function getFileSize(string $url, array $creds): int {
		['host' => $host, 'port' => $port, 'path' => $path] = $this->parseUrl($url);
		$ftp  = $this->connect($host, $port, $creds);
		$size = ftp_size($ftp, $path);
		ftp_close($ftp);
		return $size >= 0 ? $size : -1;
	}
}
