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
 * @method bool   getOverwrite()
 * @method void   setOverwrite(bool $overwrite)
 * @method string getKind()
 * @method void   setKind(string $kind)
 * @method int    getFilesTotal()
 * @method void   setFilesTotal(int $n)
 * @method int    getFilesDone()
 * @method void   setFilesDone(int $n)
 * @method int    getFilesFailed()
 * @method void   setFilesFailed(int $n)
 * @method int    getBytesTotal()
 * @method void   setBytesTotal(int $n)
 * @method int    getBytesDone()
 * @method void   setBytesDone(int $n)
 * @method string|null getCurrentFile()
 * @method void   setCurrentFile(?string $path)
 */
class ImportJob extends Entity {
	protected array $_fieldTypes = ['id' => 'integer', 'overwrite' => 'boolean', 'filesTotal' => 'integer',
		'filesDone' => 'integer', 'filesFailed' => 'integer', 'bytesTotal' => 'integer', 'bytesDone' => 'integer'];
	protected string $userId = '';
	protected string $provider = '';
	protected string $sourceUrl = '';
	protected string $destination = '';
	protected string $status = 'queued';
	protected int $progress = 0;
	protected ?string $errorMessage = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected bool $overwrite = false;
	// 'file': one file; 'tree': a whole remote folder/repository, synced in the background
	protected string $kind = 'file';
	protected int $filesTotal = 0;
	protected int $filesDone = 0;
	protected int $filesFailed = 0;
	protected int $bytesTotal = 0;
	protected int $bytesDone = 0;
	protected ?string $currentFile = null;

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
			'overwrite'    => $this->overwrite,
			'kind'         => $this->kind,
			'files_total'  => $this->filesTotal,
			'files_done'   => $this->filesDone,
			'files_failed' => $this->filesFailed,
			'bytes_total'  => $this->bytesTotal,
			'bytes_done'   => $this->bytesDone,
			'current_file' => $this->currentFile,
		];
	}
}
