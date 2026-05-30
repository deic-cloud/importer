<?php

declare(strict_types=1);

namespace OCA\Importer\Service;

use OCP\Security\ICredentialsManager;

/**
 * Stores per-user provider credentials in NC's encrypted credentials store.
 *
 * Key format: "importer/{provider}/{host}"  e.g. "importer/ftp/ftp.example.com"
 * Value: JSON-encoded array, shape depends on provider auth type:
 *   basic: {username, password}
 *   key:   {access_key, secret_key, region, endpoint}
 */
class CredentialService {
	private const PREFIX = 'importer/';

	public function __construct(private ICredentialsManager $credentialsManager) {}

	public function store(string $userId, string $provider, string $host, array $creds): void {
		$this->credentialsManager->store($userId, $this->key($provider, $host), $creds);
	}

	public function retrieve(string $userId, string $provider, string $host): array {
		return $this->credentialsManager->retrieve($userId, $this->key($provider, $host)) ?? [];
	}

	public function delete(string $userId, string $provider, string $host): void {
		$this->credentialsManager->delete($userId, $this->key($provider, $host));
	}

	/** @return array{provider:string, host:string, creds:array}[] */
	public function listAll(string $userId): array {
		$all     = $this->credentialsManager->retrieveAll($userId);
		$results = [];
		foreach ($all as $key => $creds) {
			if (!str_starts_with($key, self::PREFIX)) continue;
			$parts = explode('/', substr($key, strlen(self::PREFIX)), 2);
			if (count($parts) !== 2) continue;
			// Never return secret values to the frontend — only metadata
			$safe = ['username' => $creds['username'] ?? $creds['access_key'] ?? ''];
			$results[] = [
				'provider' => $parts[0],
				'host'     => $parts[1],
				'creds'    => $safe,
			];
		}
		return $results;
	}

	private function key(string $provider, string $host): string {
		return self::PREFIX . $provider . '/' . $host;
	}
}
