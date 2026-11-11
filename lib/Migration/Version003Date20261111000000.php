<?php

declare(strict_types=1);

namespace OCA\Importer\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Jobs that import a whole remote folder or repository (kind 'tree'), and byte progress:
 * kind, file and byte counters, and the file being downloaded.
 */
class Version003Date20261111000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table  = $schema->getTable('importer_jobs');
		if (!$table->hasColumn('kind')) {
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'file']);
		}
		foreach (['files_total', 'files_done', 'files_failed'] as $col) {
			if (!$table->hasColumn($col)) {
				$table->addColumn($col, Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
		}
		foreach (['bytes_total', 'bytes_done'] as $col) {
			if (!$table->hasColumn($col)) {
				$table->addColumn($col, Types::BIGINT, ['notnull' => true, 'default' => 0]);
			}
		}
		if (!$table->hasColumn('current_file')) {
			$table->addColumn('current_file', Types::STRING, ['notnull' => false, 'length' => 1024]);
		}
		return $schema;
	}
}
