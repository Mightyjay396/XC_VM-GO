<?php

namespace XcVm\Core\Module;

use XcVm\Core\Auth\Authorization;

/**
 * ImportSourceRegistry — module import kinds on the Import & Review page
 * (live streams), next to the built-in M3U file.
 *
 * A module registers a kind from its boot() (bootAll() resets the registry).
 * The page renders the kind's own inputs on the select step; on submit the
 * kind lists its channels, and they go through the ordinary review → import
 * steps, so every channel becomes an ordinary stream.
 *
 * add($key, $label, $render, $list, $permission):
 *   - $key        [a-z0-9_-], unique; posted as `import_kind`.
 *   - $label      Shown in the source picker (already translated).
 *   - $render     fn(): string — the kind's inputs on the select step. Name them
 *                 `import_source[<key>][<field>]`.
 *   - $list       fn(array $fields): array — the channels for those fields, each
 *                 ['url' => …, 'title' => …, optional 'logo', 'tvg_id', 'category'].
 *                 Throwing shows "no sources" and logs the message.
 *   - $permission 'adv' sub-permission needed to see and use the kind; null =
 *                 anyone who may import streams.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ImportSourceRegistry {
	/** Rows one review page can carry (the M3U import's own cap). */
	public const MAX_ROWS = 500;

	/** @var array<string, array{label: string, render: callable, list: callable, permission: ?string}> */
	private static array $kinds = [];

	/** @throws \InvalidArgumentException On a key that can't be a form value / HTML id. */
	public static function add(string $key, string $label, callable $render, callable $list, ?string $permission = null): void {
		if (!preg_match('/^[a-z0-9_-]+$/', $key)) {
			throw new \InvalidArgumentException("ImportSourceRegistry: invalid import kind '{$key}'");
		}
		self::$kinds[$key] = ['label' => $label, 'render' => $render, 'list' => $list, 'permission' => $permission];
	}

	/**
	 * The kinds the current admin may use.
	 *
	 * @return array<string, array{label: string, render: callable, list: callable, permission: ?string}>
	 */
	public static function kinds(): array {
		return array_filter(self::$kinds, static fn(array $rKind): bool => $rKind['permission'] === null || Authorization::check('adv', $rKind['permission']));
	}

	/**
	 * The review rows of a kind, in the M3U import's shape: url, title, logo,
	 * tvg_id, category, exists. Rows without a URL are dropped; a source already
	 * in the panel is dropped unless $rDuplicates, and flagged `exists` otherwise.
	 *
	 * @param array    $rInput      The posted form (the kind reads import_source[<key>]).
	 * @param string[] $rExisting   Source URLs already in the panel (http-normalised).
	 * @return array{rows: list<array>, truncated: bool}
	 */
	public static function rows(string $rKey, array $rInput, array $rExisting, bool $rDuplicates): array {
		$rRows = [];
		foreach (self::listed($rKey, (array) ($rInput['import_source'][$rKey] ?? [])) as $rChannel) {
			$rRow = self::row((array) $rChannel, $rExisting);
			if ($rRow !== null && ($rDuplicates || !$rRow['exists'])) {
				$rRows[] = $rRow;
			}
		}
		return ['rows' => array_slice($rRows, 0, self::MAX_ROWS), 'truncated' => count($rRows) > self::MAX_ROWS];
	}

	/** Forget every kind (bootAll() re-registers them). */
	public static function reset(): void {
		self::$kinds = [];
	}

	private static function listed(string $rKey, array $rFields): array {
		$rKind = self::kinds()[$rKey] ?? null;
		if ($rKind === null) {
			return [];
		}
		try {
			return (array) ($rKind['list'])($rFields);
		} catch (\Throwable $e) {
			error_log("ImportSourceRegistry: import kind '{$rKey}' failed: " . $e->getMessage());
			return [];
		}
	}

	/** @param string[] $rExisting */
	private static function row(array $rChannel, array $rExisting): ?array {
		$rURL = trim((string) ($rChannel['url'] ?? ''));
		if ($rURL === '') {
			return null;
		}
		return [
			'url'      => $rURL,
			'title'    => (string) ($rChannel['title'] ?? '') ?: $rURL,
			'logo'     => (string) ($rChannel['logo'] ?? ''),
			'tvg_id'   => (string) ($rChannel['tvg_id'] ?? ''),
			'category' => (string) ($rChannel['category'] ?? ''),
			'exists'   => in_array(str_replace('https://', 'http://', $rURL), $rExisting, true),
		];
	}
}
