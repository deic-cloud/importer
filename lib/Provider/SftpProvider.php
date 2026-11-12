<?php

declare(strict_types=1);

namespace OCA\Importer\Provider;

use OCP\IAppConfig;
use OCP\Security\IRemoteHostValidator;
use phpseclib\Crypt\RSA;
use phpseclib\Net\SFTP;

/**
 * SFTP servers (e.g. HPC and lab systems, ERDA), with a username and a password, a private
 * key (RSA, optionally with a passphrase) or the user's own certificate key (files_sharding).
 *
 * Addresses: sftp://host[:port]/path. The path is absolute; "/" (or nothing) means the
 * login's home folder, which is what most users expect from sftp/scp. Uses phpseclib 2,
 * which Nextcloud bundles.
 *
 * Host keys: the server's key is remembered on first contact (per host and port, for the
 * whole instance) and a later change is refused, as ssh does with known_hosts. An admin
 * can forget a key: occ config:app:delete importer sftp_hostkey_<host>:<port>
 */
class SftpProvider implements IImportProvider {
	private const READ_CHUNK = 4 * 1024 * 1024;   // per request; phpseclib pipelines within it

	public function __construct(private IAppConfig $appConfig, private IRemoteHostValidator $hostValidator) {}

	public function getId(): string          { return 'sftp'; }
	public function getDisplayName(): string { return 'SFTP'; }
	public function getAuthType(): string    { return self::AUTH_BASIC; }

	public function listDirectory(string $url, array $creds): array {
		['host' => $host, 'port' => $port, 'path' => $path] = $this->parse($url);
		$sftp = $this->connect($host, $port, $creds);
		try {
			$dir = $this->absolute($sftp, $path);
			$list = $sftp->rawlist($dir);
			if (!is_array($list)) {
				throw new \RuntimeException('SFTP: cannot list ' . $dir);
			}
		} finally {
			$sftp->disconnect();
		}
		$base = 'sftp://' . $host . ($port !== 22 ? ':' . $port : '') . $this->encodePath(rtrim($dir, '/'));
		$results = [];
		foreach ($list as $name => $attrs) {
			$name = (string)$name;
			if ($name === '.' || $name === '..') {
				continue;
			}
			$isDir = (int)($attrs['type'] ?? 0) === 2;   // NET_SFTP_TYPE_DIRECTORY
			$results[] = [
				'name'   => $name,
				'url'    => $base . '/' . rawurlencode($name) . ($isDir ? '/' : ''),
				'is_dir' => $isDir,
				'size'   => $isDir ? 0 : (int)($attrs['size'] ?? 0),
			];
		}
		usort($results, static fn ($a, $b) => [$b['is_dir'], $a['name']] <=> [$a['is_dir'], $b['name']]);
		return $results;
	}

	/** A stream read as the data arrives, in large chunks (SftpReadStream). */
	public function getStream(string $url, array $creds) {
		['host' => $host, 'port' => $port, 'path' => $path] = $this->parse($url);
		$sftp = $this->connect($host, $port, $creds);
		$file = $this->absolute($sftp, $path);
		$size = $sftp->size($file);
		if ($size === false) {
			$sftp->disconnect();
			throw new \RuntimeException('SFTP: cannot read ' . $file);
		}
		return SftpReadStream::open($sftp, $file, (int)$size, self::READ_CHUNK);
	}

	public function getFileSize(string $url, array $creds): int {
		try {
			['host' => $host, 'port' => $port, 'path' => $path] = $this->parse($url);
			$sftp = $this->connect($host, $port, $creds);
			$size = $sftp->size($this->absolute($sftp, $path));
			$sftp->disconnect();
			return $size === false ? -1 : (int)$size;
		} catch (\Throwable) {
			return -1;
		}
	}

	// ── helpers ─────────────────────────────────────────────────────────────

	private function parse(string $url): array {
		$p = parse_url($url);
		if (($p['scheme'] ?? '') !== 'sftp' || empty($p['host'])) {
			throw new \InvalidArgumentException('Not an SFTP address (sftp://host[:port]/path): ' . $url);
		}
		return [
			'host' => strtolower($p['host']),
			'port' => (int)($p['port'] ?? 22),
			'path' => rawurldecode($p['path'] ?? ''),
		];
	}

	/** "" and "/" = the login's home folder; anything else is absolute. */
	private function absolute(SFTP $sftp, string $path): string {
		if ($path === '' || $path === '/') {
			$home = $sftp->realpath('.');
			return is_string($home) && $home !== '' ? $home : '/';
		}
		return $path;
	}

	private function encodePath(string $path): string {
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}

	private function connect(string $host, int $port, array $creds): SFTP {
		// like the HTTP sources: no local or internal addresses unless the admin allows them
		if (!$this->hostValidator->isValid($host)) {
			throw new \RuntimeException('Not allowed to download from ' . $host);
		}
		$sftp = new SFTP($host, $port, 15);
		$sftp->setTimeout(120);   // per read, not for a whole file
		$key = $sftp->getServerPublicHostKey();
		if (!is_string($key) || $key === '') {
			throw new \RuntimeException("SFTP: cannot connect to $host:$port");
		}
		// "<key type> SHA256:<fingerprint>", as ssh-keygen -lf shows it for that key type
		[$keyType, $keyBlob] = explode(' ', $key, 3) + ['', ''];
		$fingerprint = $keyType . ' SHA256:' . rtrim(base64_encode(hash('sha256', (string)base64_decode($keyBlob), true)), '=');
		$cfgKey = 'sftp_hostkey_' . $host . ':' . $port;
		$known = $this->appConfig->getValueString('importer', $cfgKey, '');
		if ($known === '') {
			$this->appConfig->setValueString('importer', $cfgKey, $fingerprint);
		} elseif (!hash_equals($known, $fingerprint)) {
			throw new \RuntimeException("SFTP: the host key of $host:$port has changed (now $fingerprint, known $known)."
				. ' If the server was reinstalled, an admin can forget the old key: occ config:app:delete importer ' . $cfgKey);
		}
		$user = (string)($creds['username'] ?? '');
		if ($user === '' || !$sftp->login($user, $this->secret($creds))) {
			throw new \RuntimeException("SFTP: login failed for $user@$host:$port - check the login under Settings → Data import"
				. (($creds['auth'] ?? '') === 'certificate' ? ', and that your certificate\'s public key is in ~/.ssh/authorized_keys on the server' : ''));
		}
		return $sftp;
	}

	/**
	 * What to log in with: the password, a private key from the login (optionally with a
	 * passphrase), or the user's own certificate key from files_sharding (auth=certificate),
	 * fetched when connecting and never stored here. phpseclib 2 reads RSA keys only.
	 */
	private function secret(array $creds): string|RSA {
		$auth = (string)($creds['auth'] ?? 'password');
		if ($auth === 'password') {
			return (string)($creds['password'] ?? '');
		}
		$passphrase = '';
		if ($auth === 'certificate') {
			$pem = self::certificateKey((string)($creds['_uid'] ?? ''));
			if ($pem === '') {
				throw new \RuntimeException('SFTP: you have no personal certificate (Settings → Access & certificates) to log in with');
			}
		} else {
			$pem = (string)($creds['private_key'] ?? '');
			$passphrase = (string)($creds['passphrase'] ?? '');
		}
		$rsa = new RSA();
		if ($passphrase !== '') {
			$rsa->setPassword($passphrase);
		}
		if (!$rsa->loadKey($pem)) {
			throw new \RuntimeException(self::isNonRsaKey($pem)
				? 'SFTP: only RSA keys can be used here (not ed25519 or ECDSA) - use an RSA key or a password'
				: 'SFTP: the private key could not be read - check the key and its passphrase');
		}
		return $rsa;
	}

	/** Why a pasted private key cannot be used ('' = it can), checked when the login is saved. */
	public static function keyProblem(string $pem, string $passphrase): string {
		$rsa = new RSA();
		if ($passphrase !== '') {
			$rsa->setPassword($passphrase);
		}
		if ($rsa->loadKey($pem)) {
			return '';
		}
		return self::isNonRsaKey($pem)
			? 'Only RSA keys can be used here (not ed25519 or ECDSA) - use an RSA key or a password'
			: 'The private key could not be read - check the key and its passphrase';
	}

	/** An ed25519 or ECDSA key? (In the OpenSSH format the key type is inside the base64 body.) */
	private static function isNonRsaKey(string $pem): bool {
		if (preg_match('/-----BEGIN (EC|ECDSA) PRIVATE KEY-----/', $pem)) {
			return true;
		}
		if (preg_match('/-----BEGIN OPENSSH PRIVATE KEY-----(.*?)-----END/s', $pem, $m)) {
			$body = (string)base64_decode(preg_replace('/\s+/', '', $m[1]));
			return str_contains($body, 'ssh-ed25519') || str_contains($body, 'ecdsa-sha2');
		}
		return false;
	}

	/** The user's certificate key (files_sharding), '' when that app or a certificate is missing. */
	public static function certificateKey(string $userId): string {
		if ($userId === '' || !class_exists(\OCA\FilesSharding\Service\CertificateService::class)) {
			return '';
		}
		try {
			if (!\OCP\Server::get(\OCP\App\IAppManager::class)->isEnabledForAnyone('files_sharding')) {
				return '';
			}
			return (string)\OCP\Server::get(\OCA\FilesSharding\Service\CertificateService::class)->getKeyPem($userId);
		} catch (\Throwable) {
			return '';
		}
	}
}
