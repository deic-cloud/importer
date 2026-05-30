<?php

declare(strict_types=1);

namespace OCA\Importer\Controller;

use OCA\Importer\AppInfo\Application;
use OCA\Importer\Service\CredentialService;
use OCA\Importer\Service\ImportService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends OCSController {
	public function __construct(
		IRequest                $request,
		private IUserSession    $userSession,
		private ImportService   $importService,
		private CredentialService $credentialService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('Not logged in');
	}

	// ── Jobs ─────────────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listJobs(): DataResponse {
		$jobs = array_map(fn($j) => $j->jsonSerialize(), $this->importService->listJobs($this->uid()));
		return new DataResponse($jobs);
	}

	#[NoAdminRequired]
	public function queueJob(string $provider, string $sourceUrl, string $destination): DataResponse {
		try {
			$job = $this->importService->queueJob($this->uid(), $provider, $sourceUrl, $destination);
			return new DataResponse($job->jsonSerialize());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	#[NoAdminRequired]
	public function deleteJob(int $id): DataResponse {
		try {
			$this->importService->deleteJob($this->uid(), $id);
			return new DataResponse([]);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	// ── Credentials ──────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listCredentials(): DataResponse {
		return new DataResponse($this->credentialService->listAll($this->uid()));
	}

	#[NoAdminRequired]
	public function saveCredentials(string $provider, string $host, array $creds): DataResponse {
		// Validate provider exists
		try {
			$this->importService->getProvider($provider);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
		$this->credentialService->store($this->uid(), $provider, $host, $creds);
		return new DataResponse([]);
	}

	#[NoAdminRequired]
	public function deleteCredentials(string $provider, string $host): DataResponse {
		$this->credentialService->delete($this->uid(), $provider, $host);
		return new DataResponse([]);
	}

	// ── Remote listing ───────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listRemote(string $provider, string $url): DataResponse {
		try {
			$entries = $this->importService->listRemote($this->uid(), $provider, $url);
			return new DataResponse($entries);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}
}
