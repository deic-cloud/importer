<?php

declare(strict_types=1);

namespace OCA\Importer\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method string getProvider()
 * @method void   setProvider(string $provider)
 * @method string getSourceUrl()
 * @method void   setSourceUrl(string $url)
 * @method string getDestination()
 * @method void   setDestination(string $dest)
 * @method string getStatus()
 * @method void   setStatus(string $status)
 * @method int    getProgress()
 * @method void   setProgress(int $progress)
 * @method string|null getErrorMessage()
 * @method void   setErrorMessage(?string $msg)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $ts)
 * @method int    getUpdatedAt()
 * @method void   setUpdatedAt(int $ts)
 */
class ImportJob extends Entity {
	protected string $userId = '';
	protected string $provider = '';
	protected string $sourceUrl = '';
	protected string $destination = '';
	protected string $status = 'queued';
	protected int $progress = 0;
	protected ?string $errorMessage = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function jsonSerialize(): array {
		return [
			'id'           => $this->id,
			'provider'     => $this->provider,
			'source_url'   => $this->sourceUrl,
			'destination'  => $this->destination,
			'status'       => $this->status,
			'progress'     => $this->progress,
			'error_message'=> $this->errorMessage,
			'created_at'   => $this->createdAt,
			'updated_at'   => $this->updatedAt,
		];
	}
}
