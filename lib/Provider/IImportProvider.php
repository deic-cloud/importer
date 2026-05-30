<?php

declare(strict_types=1);

namespace OCA\Importer\Provider;

interface IImportProvider {
	public const AUTH_NONE  = 'none';
	public const AUTH_BASIC = 'basic';   // username + password
	public const AUTH_KEY   = 'key';     // access key + secret (S3)

	public function getId(): string;
	public function getDisplayName(): string;
	public function getAuthType(): string;

	/**
	 * List the contents of a remote directory.
	 *
	 * @param string   $url   Directory URL (for WebDAV/FTP) or bucket+prefix (S3)
	 * @param array    $creds Credentials array; shape depends on getAuthType()
	 * @return array{name:string, url:string, is_dir:bool, size:int}[]
	 * @throws \RuntimeException on connection/auth failure
	 */
	public function listDirectory(string $url, array $creds): array;

	/**
	 * Return an open readable stream for the given file URL.
	 *
	 * The caller is responsible for fclose()-ing the resource.
	 *
	 * @return resource
	 * @throws \RuntimeException on connection/auth failure
	 */
	public function getStream(string $url, array $creds);

	/**
	 * Return the file size in bytes, or -1 if not determinable without downloading.
	 */
	public function getFileSize(string $url, array $creds): int;
}
