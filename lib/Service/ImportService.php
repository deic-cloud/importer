<?php

declare(strict_types=1);

namespace OCA\Importer\Service;

use OCA\Importer\Db\ImportJob;
use OCA\Importer\Db\ImportJobMapper;
use OCA\Importer\Provider\IImportProvider;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;

class ImportService {
	/** @var IImportProvider[] */
	private array $providers;

	public function __construct(
		private ImportJobMapper $mapper,
		private IRootFolder     $rootFolder,
		private CredentialService $credentialService,
		IImportProvider ...$providers,
	) {
		foreach ($providers as $p) {
			$this->providers[$p->getId()] = $p;
		}
	}

	/** @return IImportProvider[] */
	public function getProviders(): array {
		return $this->providers;
	}

	public function getProvider(string $id): IImportProvider {
		return $this->providers[$id] ?? throw new \InvalidArgumentException("Unknown provider: $id");
	}

	public function queueJob(string $userId, string $provider, string $sourceUrl, string $destination): ImportJob {
		$this->getProvider($provider); // validate

		$job = new ImportJob();
		$job->setUserId($userId);
		$job->setProvider($provider);
		$job->setSourceUrl($sourceUrl);
		$job->setDestination($destination);
		$job->setStatus('queued');
		$job->setProgress(0);
		$job->setCreatedAt(time());
		$job->setUpdatedAt(time());
		return $this->mapper->insert($job);
	}

	/** @return ImportJob[] */
	public function listJobs(string $userId): array {
		return $this->mapper->findByUser($userId);
	}

	public function deleteJob(string $userId, int $id): void {
		try {
			$job = $this->mapper->findByIdAndUser($id, $userId);
			if ($job->getStatus() === 'running') {
				throw new \RuntimeException('Cannot delete a running job');
			}
			$this->mapper->delete($job);
		} catch (DoesNotExistException) {
			// Already gone — that's fine
		}
	}

	/**
	 * Run the next queued job. Called by DownloadJob background worker.
	 * Returns the job that was processed, or null if queue was empty.
	 */
	public function processNextJob(): ?ImportJob {
		$jobs = $this->mapper->findQueued();
		if (empty($jobs)) return null;

		$job = $jobs[0];
		$job->setStatus('running');
		$job->setUpdatedAt(time());
		$this->mapper->update($job);

		try {
			$this->runJob($job);
			$job->setStatus('done');
			$job->setProgress(100);
		} catch (\Throwable $e) {
			$job->setStatus('failed');
			$job->setErrorMessage($e->getMessage());
		}

		$job->setUpdatedAt(time());
		$this->mapper->update($job);
		return $job;
	}

	private function runJob(ImportJob $job): void {
		$provider = $this->getProvider($job->getProvider());
		$uid      = $job->getUserId();

		// Resolve credentials
		$host  = parse_url($job->getSourceUrl(), PHP_URL_HOST) ?? '';
		$creds = $this->credentialService->retrieve($uid, $job->getProvider(), $host);

		// Ensure destination folder exists in NC files
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$destPath   = $job->getDestination();
		if (!$userFolder->nodeExists($destPath)) {
			$userFolder->newFolder($destPath);
		}
		$destFolder = $userFolder->get($destPath);
		if (!($destFolder instanceof \OCP\Files\Folder)) {
			throw new \RuntimeException("Destination is not a folder: $destPath");
		}

		// Filename from URL
		$filename = basename(parse_url($job->getSourceUrl(), PHP_URL_PATH) ?: 'download');
		if ($filename === '') $filename = 'download';

		// Stream download → NC file
		$stream = $provider->getStream($job->getSourceUrl(), $creds);
		try {
			$ncFile = $destFolder->newFile($filename);
			$ncFile->putContent($stream);
		} finally {
			if (is_resource($stream)) fclose($stream);
		}

		$job->setProgress(100);
	}

	public function listRemote(string $userId, string $provider, string $url): array {
		$p    = $this->getProvider($provider);
		$host = parse_url($url, PHP_URL_HOST) ?? '';
		$creds = $this->credentialService->retrieve($userId, $provider, $host);
		return $p->listDirectory($url, $creds);
	}
}
