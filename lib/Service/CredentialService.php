<?php

declare(strict_types=1);

namespace OCA\Importer\Service;

use OCP\IDBConnection;
use OCP\Security\ICredentialsManager;
use OCP\Security\ICrypto;

/**
 * Stores per-user provider credentials in NC's encrypted credentials store.
 *
 * Key format: "importer/{provider}/{baseUrl}"  e.g. "importer/webdav/https://example.com/files/"
 * The baseUrl is whatever the user entered — full URL or plain hostname.
 * Lookup uses longest-prefix match so credentials for "https://host/a/" cover "https://host/a/b/c".
 *
 * Value: JSON-encoded array, shape depends on provider auth type:
 *   basic: {username, password}
 *   key:   {access_key, secret_key, region, endpoint}
 */
class CredentialService {
	private const PREFIX    = 'importer/';
	private const DB_TABLE  = 'storages_credentials';

	public function __construct(
		private ICredentialsManager $credentialsManager,
		private IDBConnection       $db,
		private ICrypto             $crypto,
	) {}

	public function store(string $userId, string $provider, string $baseUrl, array $creds): void {
		$this->credentialsManager->store($userId, $this->key($provider, $baseUrl), $creds);
	}

	/**
	 * Find credentials for $url by longest-prefix match among stored base URLs.
	 * Falls back to hostname-only match if no prefix matches.
	 */
	public function retrieve(string $userId, string $provider, string $url): array {
		$rows = $this->rawRows($userId, $provider);

		// Longest prefix match
		$bestKey   = '';
		$bestCreds = [];
		foreach ($rows as $baseUrl => $creds) {
			if (str_starts_with($url, $baseUrl) && strlen($baseUrl) > strlen($bestKey)) {
				$bestKey   = $baseUrl;
				$bestCreds = $creds;
			}
		}
		if ($bestCreds !== []) return $bestCreds;

		// Hostname fallback: stored key may be just a hostname
		$urlHost = parse_url($url, PHP_URL_HOST) ?? '';
		foreach ($rows as $baseUrl => $creds) {
			$storedHost = parse_url($baseUrl, PHP_URL_HOST) ?? $baseUrl;
			if ($storedHost === $urlHost) return $creds;
		}

		// S3 fallback: s3:// URLs carry no endpoint info; return first stored credential
		// (covers single-account setups regardless of whether endpoint is AWS or self-hosted)
		if (str_starts_with($url, 's3://') && !empty($rows)) {
			return reset($rows);
		}

		return [];
	}

	public function delete(string $userId, string $provider, string $baseUrl): void {
		$this->credentialsManager->delete($userId, $this->key($provider, $baseUrl));
	}

	/** @return array{provider:string, host:string, creds:array}[] */
	public function listAll(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('identifier', 'credentials')
			->from(self::DB_TABLE)
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->like('identifier', $qb->createNamedParameter(self::PREFIX . '%')));

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		$out = [];
		foreach ($rows as $row) {
			$key   = $row['identifier'];
			$rest  = substr($key, strlen(self::PREFIX));
			$slash = strpos($rest, '/');
			if ($slash === false) continue;
			$provider = substr($rest, 0, $slash);
			$baseUrl  = substr($rest, $slash + 1);
			try {
				$creds = json_decode($this->crypto->decrypt($row['credentials']), true) ?? [];
			} catch (\Throwable) {
				continue;
			}
			$safe  = ['username' => $creds['username'] ?? $creds['access_key'] ?? ''];
			$out[] = ['provider' => $provider, 'host' => $baseUrl, 'creds' => $safe];
		}
		return $out;
	}

	/** @return array<string,array> baseUrl => decrypted creds for a given provider */
	private function rawRows(string $userId, string $provider): array {
		$prefix = self::PREFIX . $provider . '/';
		$qb     = $this->db->getQueryBuilder();
		$qb->select('identifier', 'credentials')
			->from(self::DB_TABLE)
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->like('identifier', $qb->createNamedParameter($prefix . '%')));

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		$out = [];
		foreach ($rows as $row) {
			$baseUrl = substr($row['identifier'], strlen($prefix));
			try {
				$out[$baseUrl] = json_decode($this->crypto->decrypt($row['credentials']), true) ?? [];
			} catch (\Throwable) {
				// skip corrupt entries
			}
		}
		return $out;
	}

	private function key(string $provider, string $baseUrl): string {
		return self::PREFIX . $provider . '/' . $baseUrl;
	}
}
