<?php

declare(strict_types=1);

namespace OCA\Importer\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002Date20260603000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table  = $schema->getTable('importer_jobs');
		if (!$table->hasColumn('overwrite')) {
			$table->addColumn('overwrite', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		}
		return $schema;
	}
}
