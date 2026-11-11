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

	/** @return ImportJob[] All queued jobs for a specific user (no limit). */
	public function findQueuedByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('queued')))
			->orderBy('created_at', 'ASC');
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
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter('file')))   // trees run in the background
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

	/** Atomically claim a queued job by id (any kind) - for the background worker. */
	public function claimById(int $id): bool {
		$upd = $this->db->getQueryBuilder();
		return $upd->update($this->getTableName())
			->set('status', $upd->createNamedParameter('running'))
			->set('updated_at', $upd->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($upd->expr()->eq('status', $upd->createNamedParameter('queued')))
			->executeStatement() === 1;
	}

	/**
	 * Put back in the queue jobs marked running that show no sign of life (no progress write)
	 * for $seconds - e.g. a download killed by a web-server restart. Returns how many.
	 */
	public function requeueStale(int $seconds): int {
		$upd = $this->db->getQueryBuilder();
		return $upd->update($this->getTableName())
			->set('status', $upd->createNamedParameter('queued'))
			->where($upd->expr()->eq('status', $upd->createNamedParameter('running')))
			->andWhere($upd->expr()->lt('updated_at', $upd->createNamedParameter(time() - $seconds, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** Write progress fields of a job directly (cheap, frequent), stamping updated_at as a sign of life. */
	public function updateProgress(int $id, array $fields): void {
		$upd = $this->db->getQueryBuilder();
		$upd->update($this->getTableName())
			->set('updated_at', $upd->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		foreach ($fields as $col => $val) {
			$upd->set($col, $upd->createNamedParameter($val, is_int($val) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
		}
		$upd->executeStatement();
	}

	public function findByIdAndUser(int $id, string $userId): ImportJob {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/** Reset all failed jobs for a user back to queued. Returns count reset. */
	public function resetFailedForUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		return (int) $qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('queued'))
			->set('error_message', $qb->createNamedParameter(''))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('failed')))
			->executeStatement();
	}

	/** Reset a single failed job back to queued. */
	public function resetJobForUser(int $id, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('queued'))
			->set('error_message', $qb->createNamedParameter(''))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('failed')))
			->executeStatement();
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
