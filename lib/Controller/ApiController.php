<?php

declare(strict_types=1);

namespace OCA\Importer\Controller;

use OCA\Importer\AppInfo\Application;
use OCA\Importer\Service\CredentialService;
use OCA\Importer\Service\ImportService;
use OCP\App\IAppManager;
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
		private IAppManager     $appManager,
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
	public function queueJob(string $provider, string $sourceUrl, string $destination, bool $overwrite = false): DataResponse {
		try {
			$job = $this->importService->queueJob($this->uid(), $provider, $sourceUrl, $destination, $overwrite);
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

	// ── Grant groups ─────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listGrantGroups(): DataResponse {
		if (!$this->appManager->isInstalled('user_group_admin')) {
			return new DataResponse([]);
		}
		try {
			/** @var \OCA\UserGroupAdmin\Db\GroupMapper $mapper */
			$mapper = \OC::$server->get(\OCA\UserGroupAdmin\Db\GroupMapper::class);
			$groups = $mapper->findGrantGroupsForMember($this->uid());
			return new DataResponse(array_map(fn($g) => ['gid' => $g->getGid()], $groups));
		} catch (\Throwable) {
			return new DataResponse([]);
		}
	}

	#[NoAdminRequired]
	public function retryFailed(): DataResponse {
		$count = $this->importService->retryFailed($this->uid());
		return new DataResponse(['reset' => $count]);
	}

	#[NoAdminRequired]
	public function retryJob(int $id): DataResponse {
		$this->importService->retryJob($this->uid(), $id);
		return new DataResponse([]);
	}

	#[NoAdminRequired]
	public function prepareDestinations(): DataResponse {
		$this->importService->prepareDestinations($this->uid());
		return new DataResponse([]);
	}

	// ── Processing ───────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function processJob(bool $overwrite = false): DataResponse {
		set_time_limit(0);
		$job = $this->importService->claimAndProcess($this->uid(), $overwrite);
		if ($job === null) {
			return new DataResponse(['done' => true]);
		}
		return new DataResponse(array_merge($job->jsonSerialize(), ['done' => false]));
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
