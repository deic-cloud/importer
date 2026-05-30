<?php

declare(strict_types=1);

namespace OCA\Importer\AppInfo;

use OCA\Importer\BackgroundJob\DownloadJob;
use OCA\Importer\Notification\Notifier;
use OCA\Importer\Provider\FtpProvider;
use OCA\Importer\Provider\HttpProvider;
use OCA\Importer\Provider\S3Provider;
use OCA\Importer\Provider\WebDavProvider;
use OCA\Importer\Service\ImportService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'importer';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerBackgroundJob(DownloadJob::class);
		$context->registerNotifierService(Notifier::class);

		$context->registerService(ImportService::class, function (ContainerInterface $c): ImportService {
			return new ImportService(
				$c->get(\OCA\Importer\Db\ImportJobMapper::class),
				$c->get(\OCP\Files\IRootFolder::class),
				$c->get(\OCA\Importer\Service\CredentialService::class),
				$c->get(HttpProvider::class),
				$c->get(FtpProvider::class),
				$c->get(S3Provider::class),
				$c->get(WebDavProvider::class),
			);
		});
	}

	public function boot(IBootContext $context): void {
	}
}
