<?php

declare(strict_types=1);

namespace OCA\Importer\Provider;

use phpseclib\Net\SFTP;

/**
 * A read-only PHP stream over one remote file of an SFTP session, fetching it in large
 * chunks. PHP reads streams 8 kB at a time; phpseclib's own stream wrapper turns each of
 * those into a round trip, far too slow for big files. Here each round trip asks for up to
 * $chunk bytes (phpseclib pipelines the packets within it), and reads are served from the
 * buffer. The session is closed with the stream.
 */
class SftpReadStream {
	private const PROTOCOL = 'importersftp';

	/** @var resource|null set by PHP */
	public $context;

	private SFTP $sftp;
	private string $path;
	private int $size;
	private int $chunk;
	private int $pos = 0;
	private string $buffer = '';
	private int $off = 0;          // read position in $buffer (no re-slicing per 8 kB read)

	/** @return resource */
	public static function open(SFTP $sftp, string $path, int $size, int $chunk) {
		if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
			stream_wrapper_register(self::PROTOCOL, self::class);
		}
		$ctx = stream_context_create([self::PROTOCOL => ['sftp' => $sftp, 'path' => $path, 'size' => $size, 'chunk' => $chunk]]);
		$h = fopen(self::PROTOCOL . '://file', 'rb', false, $ctx);
		if ($h === false) {
			throw new \RuntimeException('SFTP: cannot open ' . $path);
		}
		return $h;
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool {
		$o = stream_context_get_options($this->context)[self::PROTOCOL] ?? [];
		if (!($o['sftp'] ?? null) instanceof SFTP) {
			return false;
		}
		$this->sftp = $o['sftp'];
		$this->path = (string)$o['path'];
		$this->size = (int)$o['size'];
		$this->chunk = max(65536, (int)$o['chunk']);
		return true;
	}

	public function stream_read(int $count): string|false {
		if ($this->off >= strlen($this->buffer) && $this->pos < $this->size) {
			$want = min($this->chunk, $this->size - $this->pos);
			$data = $this->sftp->get($this->path, false, $this->pos, $want);
			if (!is_string($data) || $data === '') {
				return false;   // read error: the import reports a short file
			}
			$this->buffer = $data;
			$this->off = 0;
			$this->pos += strlen($data);
		}
		$out = substr($this->buffer, $this->off, $count);
		$this->off += strlen($out);
		return $out;
	}

	public function stream_eof(): bool {
		return $this->off >= strlen($this->buffer) && $this->pos >= $this->size;
	}

	public function stream_stat(): array {
		return ['size' => $this->size];
	}

	public function stream_close(): void {
		$this->sftp->disconnect();
	}
}
