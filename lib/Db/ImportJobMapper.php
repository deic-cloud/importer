<?php

declare(strict_types=1);

namespace OCA\Importer\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ImportJob> */
class ImportJobMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'importer_jobs', ImportJob::class);
	}

	/** @return ImportJob[] */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return ImportJob[] */
	public function findQueued(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('queued')))
			->orderBy('created_at', 'ASC')
			->setMaxResults(5);
		return $this->findEntities($qb);
	}

	/**
	 * Atomically claim the next queued job for a user.
	 * Uses optimistic locking: UPDATE WHERE status='queued' to prevent two workers
	 * from claiming the same job.
	 */
	public function claimNextQueued(string $userId): ?ImportJob {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('queued')))
			->orderBy('created_at', 'ASC')
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row    = $result->fetch();
		$result->closeCursor();
		if (!$row) return null;

		$id  = (int) $row['id'];
		$now = time();

		$upd = $this->db->getQueryBuilder();
		$affected = $upd->update($this->getTableName())
			->set('status', $upd->createNamedParameter('running'))
			->set('updated_at', $upd->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($upd->expr()->eq('status', $upd->createNamedParameter('queued')))
			->executeStatement();

		if ($affected === 0) return null; // Claimed by another worker

		return $this->findByIdAndUser($id, $userId);
	}

	public function findByIdAndUser(int $id, string $userId): ImportJob {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	public function deleteOldCompleted(string $userId, int $keepDays = 30): void {
		$cutoff = time() - ($keepDays * 86400);
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['done', 'failed'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
