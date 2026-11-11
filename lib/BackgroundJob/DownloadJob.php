<?php

declare(strict_types=1);

namespace OCA\Importer\BackgroundJob;

use OCA\Importer\Service\ImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Runs every minute (as often as cron allows). Works through queued downloads for up to
 * background_minutes per run; see run().
 */
class DownloadJob extends TimedJob {
	public function __construct(
		ITimeFactory                    $time,
		private ImportService           $importService,
		private INotificationManager    $notificationManager,
		private LoggerInterface         $logger,
		private IAppConfig              $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run(mixed $argument): void {
		// Work through the queue for up to background_minutes (default 15): single files one by
		// one, whole trees until the deadline (they continue in the next run). Runs under cron,
		// so a web-server restart does not touch it.
		$deadline = time() + 60 * max(1, $this->appConfig->getValueInt('importer', 'background_minutes', 15));
		while (time() < $deadline) {
			$job = $this->importService->processNextJob($deadline);
			if ($job === null) break;
			if ($job->getStatus() === 'queued') {
				sleep(1);                                   // a tree paused at the deadline, or lock contention
				continue;
			}

			$this->logger->info('importer: job {id} finished with status {status}', [
				'id'     => $job->getId(),
				'status' => $job->getStatus(),
			]);

			$this->sendNotification($job->getUserId(), $job->getId(), $job->getStatus(), $job->getSourceUrl(), $job->getErrorMessage());
		}
	}

	private function sendNotification(string $userId, int $jobId, string $status, string $sourceUrl, ?string $error): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp('importer')
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject('job', (string) $jobId)
				->setSubject($status === 'done' ? 'download_done' : 'download_failed', [
					'url'   => $sourceUrl,
					'error' => $error ?? '',
				]);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('importer: could not send notification: ' . $e->getMessage());
		}
	}
}
