<?php

declare(strict_types=1);

namespace OCA\Importer\BackgroundJob;

use OCA\Importer\Service\ImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Runs every minute. Processes up to 3 queued download jobs per invocation.
 */
class DownloadJob extends TimedJob {
	public function __construct(
		ITimeFactory                    $time,
		private ImportService           $importService,
		private INotificationManager    $notificationManager,
		private LoggerInterface         $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run(mixed $argument): void {
		$processed = 0;
		while ($processed < 3) {
			$job = $this->importService->processNextJob();
			if ($job === null) break;

			$this->logger->info('importer: job {id} finished with status {status}', [
				'id'     => $job->getId(),
				'status' => $job->getStatus(),
			]);

			$this->sendNotification($job->getUserId(), $job->getId(), $job->getStatus(), $job->getSourceUrl(), $job->getErrorMessage());
			$processed++;
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
