<?php

declare(strict_types=1);

namespace OCA\Importer\Provider;

use OCP\Http\Client\IClientService;

/**
 * Hugging Face Hub: public models and datasets, browsed through the Hub's API.
 *
 * Accepted addresses (the browser hands the listed URLs back as they are):
 *   https://huggingface.co/<owner>                          - the owner's models and datasets, as folders
 *   https://huggingface.co/<owner>/<model>[/tree/<rev>/<path>]
 *   https://huggingface.co/datasets/<owner>/<dataset>[/tree/<rev>/<path>]
 * Files are fetched from https://huggingface.co/[datasets/]<repo>/resolve/<rev>/<path>.
 * Gated and private repositories need a token and are not supported.
 */
class HuggingFaceProvider implements IImportProvider {
	private const HUB = 'https://huggingface.co';

	public function __construct(private IClientService $httpClientService) {}

	public function getId(): string          { return 'huggingface'; }
	public function getDisplayName(): string { return 'Hugging Face'; }
	public function getAuthType(): string    { return self::AUTH_NONE; }

	public function listDirectory(string $url, array $creds): array {
		$p = $this->parse($url);
		if ($p['repo'] === '') {
			return $this->listOwner($p['owner']);
		}
		$base = self::HUB . '/' . ($p['kind'] === 'datasets' ? 'datasets/' : '') . $p['repo'];
		$api = self::HUB . '/api/' . $p['kind'] . '/' . $p['repo'] . '/tree/' . rawurlencode($p['rev'])
			. ($p['path'] !== '' ? '/' . $this->encodePath($p['path']) : '');
		$results = [];
		foreach ($this->getPaged($api) as $e) {
			$path = (string)($e['path'] ?? '');
			$name = basename($path);
			if (($e['type'] ?? '') === 'directory') {
				$results[] = [
					'name'   => $name,
					'url'    => $base . '/tree/' . rawurlencode($p['rev']) . '/' . $this->encodePath($path) . '/',
					'is_dir' => true,
					'size'   => 0,
				];
			} elseif (($e['type'] ?? '') === 'file') {
				$results[] = [
					'name'   => $name,
					'url'    => $base . '/resolve/' . rawurlencode($p['rev']) . '/' . $this->encodePath($path),
					'is_dir' => false,
					'size'   => (int)($e['lfs']['size'] ?? $e['size'] ?? 0),
				];
			}
		}
		return $results;
	}

	public function getStream(string $url, array $creds) {
		$response = $this->httpClientService->newClient()->get($url, ['timeout' => 0, 'stream' => true]);
		$body = $response->getBody();
		if (is_resource($body)) {
			return $body;
		}
		if (is_object($body) && method_exists($body, 'detach')) {
			$res = $body->detach();
			if (is_resource($res)) {
				return $res;
			}
		}
		throw new \RuntimeException('Could not obtain stream from Hugging Face');
	}

	public function getFileSize(string $url, array $creds): int {
		try {
			$response = $this->httpClientService->newClient()->head($url, ['timeout' => 30]);
			$len = $response->getHeader('Content-Length');
			return $len !== '' ? (int)$len : -1;
		} catch (\Throwable) {
			return -1;
		}
	}

	/** An owner's (user's or organisation's) public models and datasets, each as a folder. */
	private function listOwner(string $owner): array {
		$results = [];
		foreach (['models' => '', 'datasets' => 'datasets/'] as $kind => $prefix) {
			foreach ($this->getPaged(self::HUB . '/api/' . $kind . '?author=' . rawurlencode($owner) . '&limit=1000') as $r) {
				$id = (string)($r['id'] ?? '');
				if ($id === '' || !empty($r['private']) || !empty($r['gated'])) {
					continue;
				}
				$results[] = [
					'name'   => $kind . ': ' . substr($id, strlen($owner) + 1),
					'url'    => self::HUB . '/' . $prefix . $id . '/',
					'is_dir' => true,
					'size'   => 0,
				];
			}
		}
		return $results;
	}

	/** GET a JSON list from the Hub API, following its Link: <…>; rel="next" pagination. */
	private function getPaged(string $url): array {
		$client = $this->httpClientService->newClient();
		$all = [];
		for ($page = 0; $url !== '' && $page < 1000; $page++) {
			$response = $client->get($url, ['timeout' => 60]);
			$items = json_decode((string)$response->getBody(), true);
			if (!is_array($items)) {
				throw new \RuntimeException('Unexpected answer from the Hugging Face API');
			}
			array_push($all, ...$items);
			$url = preg_match('/<([^>]+)>;\s*rel="next"/', $response->getHeader('Link'), $m) ? $m[1] : '';
		}
		return $all;
	}

	/** Split a Hub URL into kind (models/datasets), owner, repo (owner/name or ''), revision and path. */
	private function parse(string $url): array {
		$path = trim((string)parse_url($url, PHP_URL_PATH), '/');
		$seg = $path === '' ? [] : array_map('rawurldecode', explode('/', $path));
		if (strtolower((string)parse_url($url, PHP_URL_HOST)) !== 'huggingface.co' || !$seg) {
			throw new \InvalidArgumentException('Not a Hugging Face address: ' . $url);
		}
		$kind = 'models';
		if ($seg[0] === 'datasets') {
			$kind = 'datasets';
			array_shift($seg);
		}
		if (count($seg) === 1) {
			return ['kind' => $kind, 'owner' => $seg[0], 'repo' => '', 'rev' => 'main', 'path' => ''];
		}
		$rev = 'main';
		$rest = '';
		if (count($seg) >= 4 && in_array($seg[2], ['tree', 'resolve', 'blob'], true)) {
			$rev = $seg[3];
			$rest = implode('/', array_slice($seg, 4));
		}
		return ['kind' => $kind, 'owner' => $seg[0], 'repo' => $seg[0] . '/' . $seg[1], 'rev' => $rev, 'path' => $rest];
	}

	private function encodePath(string $path): string {
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}
}
