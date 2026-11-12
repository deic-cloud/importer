<?php

declare(strict_types=1);

namespace OCA\Importer\Settings;

use OCA\Importer\AppInfo\Application;
use OCA\Importer\Provider\SftpProvider;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class PersonalSettings implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {}

	public function getForm(): TemplateResponse {
		// SFTP can log in with the user's certificate key (files_sharding), when there is one
		$hasCert = SftpProvider::certificateKey($this->userSession->getUser()?->getUID() ?? '') !== '';
		$this->initialState->provideInitialState('certificate_key', [
			'available' => $hasCert,
			'public_key_url' => $hasCert ? $this->urlGenerator->linkToRoute('files_sharding.x509.downloadIdRsaPub') : '',
		]);
		Util::addScript(Application::APP_ID, 'importer-personal');
		Util::addStyle(Application::APP_ID, 'importer');
		return new TemplateResponse(Application::APP_ID, 'personal-settings', [], 'blank');
	}

	public function getSection(): string {
		return 'importer';
	}

	public function getPriority(): int {
		return 50;
	}
}
