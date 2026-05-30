<?php

declare(strict_types=1);

namespace OCA\Importer\Notification;

use OCA\Importer\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
	public function __construct(
		private IFactory      $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Importer';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new \InvalidArgumentException('Wrong app');
		}

		$l   = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$p   = $notification->getSubjectParameters();
		$url = $p['url'] ?? '';

		match ($notification->getSubject()) {
			'download_done'   => $notification->setParsedSubject($l->t('Download complete: %s', [$url])),
			'download_failed' => $notification->setParsedSubject($l->t('Download failed: %s — %s', [$url, $p['error'] ?? ''])),
			default           => throw new \InvalidArgumentException('Unknown subject'),
		};

		$notification->setLink($this->urlGenerator->linkToRouteAbsolute('importer.page.index'));
		return $notification;
	}
}
