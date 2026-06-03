<?php

declare(strict_types=1);

namespace OCA\Importer\Settings;

use OCA\Importer\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class PersonalSettings implements ISettings {
	public function getForm(): TemplateResponse {
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
