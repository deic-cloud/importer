<?php

declare(strict_types=1);

namespace OCA\Importer\Service;

use OCA\Importer\Db\ImportJob;
use OCA\Importer\Db\ImportJobMapper;
use OCA\Importer\Provider\IImportProvider;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\Lock\LockedException;

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

	public function queueJob(string $userId, string $provider, string $sourceUrl, string $destination, bool $overwrite = false): ImportJob {
		$this->getProvider($provider); // validate

		$job = new ImportJob();
		$job->setUserId($userId);
		$job->setProvider($provider);
		$job->setSourceUrl($sourceUrl);
		$job->setDestination($destination);
		$job->setOverwrite($overwrite);
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

	/** Returns true if the file was written, false if it was skipped (exists + !overwrite). */
	private function writeFile(\OCP\Files\Folder $folder, string $name, mixed $stream, bool $overwrite): bool {
		if ($folder->nodeExists($name)) {
			if (!$overwrite) return false;
			$folder->get($name)->putContent($stream);
		} else {
			$folder->newFile($name)->putContent($stream);
		}
		return true;
	}

	private function ensureFolder(\OCP\Files\Folder $base, string $path): \OCP\Files\Folder {
		$folder = $base;
		foreach (array_filter(explode('/', $path)) as $part) {
			// Retry each level: parallel workers may race to create the same folder
			for ($attempt = 0; $attempt < 5; $attempt++) {
				try {
					$folder = $folder->nodeExists($part)
						? $folder->get($part)
						: $folder->newFolder($part);
					break;
				} catch (LockedException $e) {
					if ($attempt === 4) throw $e;
					usleep(300000); // 300ms between retries
				}
			}
		}
		return $folder;
	}

	private function runJob(ImportJob $job, bool $overwrite = false): void {
		$provider = $this->getProvider($job->getProvider());
		$uid      = $job->getUserId();

		$creds = $this->credentialService->retrieve($uid, $job->getProvider(), $job->getSourceUrl());

		$filename = rawurldecode(basename(parse_url($job->getSourceUrl(), PHP_URL_PATH) ?: 'download') ?: 'download');
		$stream   = $provider->getStream($job->getSourceUrl(), $creds);

		$destination = $job->getDestination();

		if (str_starts_with($destination, 'grant:')) {
			// grant:{gid}:{subpath} — write inside files/.uga_grants/{gid}/ via NC API so the file cache is updated
			[, $gid, $subPath] = explode(':', $destination, 3) + ['', '', ''];
			$destPath   = '.uga_grants/' . $gid;
			if ($subPath !== '') {
				$destPath .= '/' . ltrim($subPath, '/');
			}
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$destFolder = $this->ensureFolder($userFolder, $destPath);
			try {
				$written = $this->writeFile($destFolder, $filename, $stream, $overwrite);
			} finally {
				if (is_resource($stream)) fclose($stream);
			}
		} else {
			// NC home — use NC files API
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$destFolder = $this->ensureFolder($userFolder, $destination);
			try {
				$written = $this->writeFile($destFolder, $filename, $stream, $overwrite);
			} finally {
				if (is_resource($stream)) fclose($stream);
			}
		}

		$job->setProgress(100);
		if (!$written) {
			$job->setStatus('skipped');
		}
	}

	/**
	 * Claim the next queued job for the user and run it synchronously.
	 * Returns the finished job, or null if no queued jobs remain.
	 */
	public function claimAndProcess(string $userId, bool $overwrite = false): ?ImportJob {
		$job = $this->mapper->claimNextQueued($userId);
		if ($job === null) return null;

		try {
			$this->runJob($job, $overwrite);
			if ($job->getStatus() !== 'skipped') {
				$job->setStatus('done');
			}
			$job->setProgress(100);
		} catch (\Throwable $e) {
			$job->setStatus('failed');
			$job->setErrorMessage($e->getMessage());
		}

		$job->setUpdatedAt(time());
		$this->mapper->update($job);
		return $job;
	}

	public function listRemote(string $userId, string $provider, string $url): array {
		$p    = $this->getProvider($provider);
		$creds = $this->credentialService->retrieve($userId, $provider, $url);
		return $p->listDirectory($url, $creds);
	}
}
