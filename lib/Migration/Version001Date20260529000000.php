<?php

declare(strict_types=1);

namespace OCA\Importer\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001Date20260529000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('importer_jobs')) {
			$table = $schema->createTable('importer_jobs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('provider', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('source_url', Types::TEXT, ['notnull' => true]);
			$table->addColumn('destination', Types::TEXT, ['notnull' => true]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'queued']);
			$table->addColumn('progress', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('error_message', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'importer_jobs_uid');
			$table->addIndex(['status'], 'importer_jobs_status');
		}

		return $schema;
	}
}
