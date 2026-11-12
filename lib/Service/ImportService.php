<?php

declare(strict_types=1);

namespace OCA\Importer\Service;

use OCA\Importer\Db\ImportJob;
use OCA\Importer\Db\ImportJobMapper;
use OCA\Importer\Provider\IImportProvider;
use OCP\AppFramework\Db\DoesNotExistException;
use Icewind\Streams\CallbackWrapper;
use OCP\Files\IRootFolder;
use OCP\Lock\LockedException;

class ImportService {
	/** A running job that has written no progress for this long is put back in the queue. */
	public const STALE_SECONDS = 900;
	/** Progress is written at most this often (seconds) - it is also the job's sign of life. */
	private const PROGRESS_INTERVAL = 2;

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

	public function queueJob(string $userId, string $provider, string $sourceUrl, string $destination, bool $overwrite = false, string $kind = 'file'): ImportJob {
		$this->getProvider($provider); // validate
		if (!in_array($kind, ['file', 'tree'], true)) {
			throw new \InvalidArgumentException("Unknown job kind: $kind");
		}

		$job = new ImportJob();
		$job->setKind($kind);
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

	/**
	 * Pre-create all destination folders for queued jobs, sequentially.
	 * Call this once before starting parallel download workers so no two
	 * workers race to mkdir the same path.
	 */
	public function prepareDestinations(string $userId): void {
		$jobs = $this->mapper->findQueuedByUser($userId);
		$userFolder = $this->rootFolder->getUserFolder($userId);

		$seen = [];
		foreach ($jobs as $job) {
			$dest = $job->getDestination();

			if (str_starts_with($dest, 'grant:')) {
				[, $gid, $subPath] = explode(':', $dest, 3) + ['', '', ''];
				$folderPath = '.uga_grants/' . $gid . ($subPath !== '' ? '/' . ltrim($subPath, '/') : '');
			} else {
				$folderPath = $dest;
			}

			if (isset($seen[$folderPath])) continue;
			$seen[$folderPath] = true;

			$this->ensureFolder($userFolder, $folderPath);
		}
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
	 * Run the next queued job (single files and whole trees). Called by the DownloadJob background
	 * worker; a tree job works until $deadline and is then put back in the queue to continue later.
	 * Returns the job, or null if the queue is empty.
	 */
	public function processNextJob(int $deadline = 0): ?ImportJob {
		$this->mapper->requeueStale(self::STALE_SECONDS);
		foreach ($this->mapper->findQueued() as $candidate) {
			if (!$this->mapper->claimById($candidate->getId())) {
				continue;                                   // taken by another worker
			}
			$job = $this->mapper->findByIdAndUser($candidate->getId(), $candidate->getUserId());
			return $this->runClaimed($job, $deadline ?: time() + 900);
		}
		return null;
	}

	/**
	 * One step of a whole-folder job, driven by the user's open Import page: claim it and
	 * work on it until $seconds have passed (checked between files), then hand it back to
	 * the queue, where the page's next step - or, if the page has been closed, the
	 * background job - continues. A job someone else is working on right now (the
	 * background job, another tab) is returned unchanged.
	 */
	public function processTreeStep(string $userId, int $id, int $seconds): ImportJob {
		$job = $this->mapper->findByIdAndUser($id, $userId);
		if ($job->getKind() !== 'tree' || !$this->mapper->claimById($id)) {
			return $job;
		}
		return $this->runClaimed($this->mapper->findByIdAndUser($id, $userId), time() + $seconds);
	}

	/** Run a claimed job: a tree until $deadline (then back to the queue), a file to the end. */
	private function runClaimed(ImportJob $job, int $deadline): ImportJob {
		try {
			if ($job->getKind() === 'tree') {
				$finished = $this->runTree($job, $deadline);
				if (!$finished) {
					$job->setStatus('queued');              // time is up - the next step or run continues
				} elseif ($job->getFilesFailed() > 0) {
					$job->setStatus('failed');
					$job->setErrorMessage($job->getFilesFailed() . ' files failed - retry to fetch them');
				} else {
					$job->setStatus('done');
					$job->setErrorMessage(null);
				}
				$job->setCurrentFile(null);
			} else {
				$this->runJob($job, $job->getOverwrite());
				if ($job->getStatus() !== 'skipped') {
					$job->setStatus('done');
				}
				$job->setProgress(100);
			}
		} catch (LockedException) {
			$job->setStatus('queued');
			$job->setCreatedAt(time());
		} catch (\Throwable $e) {
			$job->setStatus('failed');
			$job->setErrorMessage($e->getMessage());
		}
		$job->setUpdatedAt(time());
		$this->mapper->update($job);
		return $job;
	}

	private function runTree(ImportJob $job, int $deadline): bool {
		$provider = $this->getProvider($job->getProvider());
		$uid      = $job->getUserId();
		$creds    = $this->credentialService->retrieve($uid, $job->getProvider(), $job->getSourceUrl());

		$files = [];
		$this->walk($provider, $creds, $job->getSourceUrl(), '', $files, $job->getId());
		$bytesTotal = array_sum(array_column($files, 'size'));
		$job->setFilesTotal(count($files));
		$job->setBytesTotal($bytesTotal);

		$root = $this->destinationFolder($uid, $job->getDestination());
		$done = $failed = $bytesDone = 0;
		$this->mapper->updateProgress($job->getId(), ['files_total' => count($files), 'bytes_total' => $bytesTotal]);
		foreach ($files as $f) {
			$folder = $f['dir'] === '' ? $root : $this->ensureFolder($root, $f['dir']);
			if ($folder->nodeExists($f['name']) && ($f['size'] <= 0 || $folder->get($f['name'])->getSize() === $f['size'])) {
				$done++;
				$bytesDone += $f['size'];
				continue;                                   // already complete
			}
			if (time() >= $deadline) {
				$this->setTreeProgress($job, $done, $failed, $bytesDone, null);
				return false;
			}
			$this->setTreeProgress($job, $done, $failed, $bytesDone, ltrim($f['dir'] . '/' . $f['name'], '/'));
			try {
				$stream = $provider->getStream($f['url'], $creds);
				$base = $bytesDone;
				$stream = $this->withProgress($stream, $job->getId(), function (int $read) use ($base, $bytesTotal): array {
					return ['bytes_done' => $base + $read, 'progress' => $bytesTotal > 0 ? (int)(100 * ($base + $read) / $bytesTotal) : 0];
				});
				try {
					$this->writeFile($folder, $f['name'], $stream, true);
				} finally {
					if (is_resource($stream)) fclose($stream);
				}
				$done++;
				$bytesDone += $f['size'];
			} catch (\Throwable $e) {
				$failed++;
				$job->setErrorMessage($f['dir'] . '/' . $f['name'] . ': ' . $e->getMessage());
			}
		}
		$this->setTreeProgress($job, $done, $failed, $bytesDone, null);
		return true;
	}

	/** Collect all files below $url: ['dir' => relative folder, 'name', 'url', 'size']. */
	private function walk(IImportProvider $provider, array $creds, string $url, string $dir, array &$files, int $jobId): void {
		foreach ($provider->listDirectory($url, $creds) as $e) {
			if ($e['is_dir']) {
				$this->walk($provider, $creds, $e['url'], ltrim($dir . '/' . $e['name'], '/'), $files, $jobId);
				$this->mapper->updateProgress($jobId, []);  // sign of life while listing big trees
			} else {
				$files[] = ['dir' => $dir, 'name' => $e['name'], 'url' => $e['url'], 'size' => (int)($e['size'] ?? 0)];
			}
		}
	}

	private function setTreeProgress(ImportJob $job, int $done, int $failed, int $bytesDone, ?string $current): void {
		$job->setFilesDone($done);
		$job->setFilesFailed($failed);
		$job->setBytesDone($bytesDone);
		$job->setCurrentFile($current);
		$job->setProgress($job->getBytesTotal() > 0 ? (int)(100 * $bytesDone / $job->getBytesTotal()) : 0);
		$this->mapper->updateProgress($job->getId(), [
			'files_done' => $done, 'files_failed' => $failed, 'bytes_done' => $bytesDone,
			'current_file' => $current, 'progress' => $job->getProgress(),
		]);
	}

	/**
	 * Wrap a download stream so that the bytes read are written to the job every few seconds -
	 * the progress bar, and the job's sign of life. $fields maps bytes read so far to columns.
	 */
	private function withProgress($stream, int $jobId, callable $fields) {
		$read = 0;
		$last = time();
		return CallbackWrapper::wrap($stream, function (int $count) use (&$read, &$last, $jobId, $fields): void {
			$read += $count;
			if (time() - $last >= self::PROGRESS_INTERVAL) {
				$last = time();
				$this->mapper->updateProgress($jobId, $fields($read));
			}
		});
	}

	/** The folder a destination string points at: a path in the home, or grant:{gid}:{subpath}. */
	private function destinationFolder(string $uid, string $destination): \OCP\Files\Folder {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		if (str_starts_with($destination, 'grant:')) {
			[, $gid, $subPath] = explode(':', $destination, 3) + ['', '', ''];
			return $this->ensureFolder($userFolder, '.uga_grants/' . $gid . ($subPath !== '' ? '/' . ltrim($subPath, '/') : ''));
		}
		return $this->ensureFolder($userFolder, $destination);
	}

	/** Returns true if the file was written, false if it was skipped (exists + !overwrite). */
	private function writeFile(\OCP\Files\Folder $folder, string $name, mixed $stream, bool $overwrite): bool {
		for ($attempt = 0; $attempt < 10; $attempt++) {
			try {
				if ($folder->nodeExists($name)) {
					if (!$overwrite) return false;
					$folder->get($name)->putContent($stream);
				} else {
					$folder->newFile($name)->putContent($stream);
				}
				return true;
			} catch (LockedException $e) {
				if ($attempt === 9) throw $e;
				usleep(500000); // 500ms × 10 = up to 5s
			}
		}
		return true; // unreachable
	}

	private function ensureFolder(\OCP\Files\Folder $base, string $path): \OCP\Files\Folder {
		$folder = $base;
		foreach (array_filter(explode('/', $path)) as $part) {
			for ($attempt = 0; $attempt < 10; $attempt++) {
				try {
					$folder = $folder->nodeExists($part)
						? $folder->get($part)
						: $folder->newFolder($part);
					break;
				} catch (LockedException $e) {
					if ($attempt === 9) throw $e;
					usleep(500000);
				} catch (\OCP\Files\NotPermittedException $e) {
					// Race: another worker created the folder between our nodeExists() and newFolder()
					if ($folder->nodeExists($part)) {
						$folder = $folder->get($part);
						break;
					}
					throw $e;
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
		$total    = max(0, $provider->getFileSize($job->getSourceUrl(), $creds));
		$job->setBytesTotal($total);
		$this->mapper->updateProgress($job->getId(), ['bytes_total' => $total, 'bytes_done' => 0]);
		$stream   = $provider->getStream($job->getSourceUrl(), $creds);
		$stream   = $this->withProgress($stream, $job->getId(), fn (int $read): array =>
			['bytes_done' => $read, 'progress' => $total > 0 ? min(99, (int)(100 * $read / $total)) : 0]);

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
		$job->setBytesDone($total);
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
		} catch (LockedException $e) {
			// Temporary contention — push back to end of queue rather than permanently fail
			$job->setStatus('queued');
			$job->setCreatedAt(time());
			$job->setErrorMessage(null);
		} catch (\Throwable $e) {
			$job->setStatus('failed');
			$job->setErrorMessage($e->getMessage());
		}

		$job->setUpdatedAt(time());
		$this->mapper->update($job);
		return $job;
	}

	public function retryFailed(string $userId): int {
		return $this->mapper->resetFailedForUser($userId);
	}

	public function retryJob(string $userId, int $id): void {
		$this->mapper->resetJobForUser($id, $userId);
	}

	public function listRemote(string $userId, string $provider, string $url): array {
		$p    = $this->getProvider($provider);
		$creds = $this->credentialService->retrieve($userId, $provider, $url);
		return $p->listDirectory($url, $creds);
	}
}
