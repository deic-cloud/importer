<?php

declare(strict_types=1);

namespace OCA\Importer\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {
	public function __construct(
		private IL10N         $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return 'importer';
	}

	public function getName(): string {
		return $this->l10n->t('Data import');
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('core', 'actions/download.svg');
	}
}
