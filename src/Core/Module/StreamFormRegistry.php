<?php

namespace XcVm\Core\Module;

use XcVm\Core\Auth\Authorization;

/**
 * StreamFormRegistry — module tabs on the admin Add/Edit Stream form.
 *
 * A module registers a tab from its boot() (static, like the other module
 * registries; bootAll() resets it). Its inputs must be named
 * `module[<id>][<field>]`: core hands exactly that sub-array back to the
 * module — on save through validate() and StreamSavedEvent — and never lets a
 * module field reach a `streams` column.
 *
 * add($id, $label, $render, $permission, $validate):
 *   - $id         [a-z0-9_-], unique; the key under `module[...]` and the tab id.
 *   - $label      Tab title (already translated; the module owns its strings).
 *   - $render     fn(?array $stream, string $mode): string — the pane's HTML.
 *                 $stream is the stream row when editing, null when adding;
 *                 $mode is 'add' or 'edit'. Not shown on the import form.
 *   - $permission 'adv' sub-permission needed to see the tab and to have its
 *                 fields accepted; null = anyone who may edit streams.
 *   - $validate   fn(array $fields, ?array $stream): ?string — an error message
 *                 (shown as is) to refuse the save, or null.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamFormRegistry {
	/** @var array<string, array{label: string, render: callable, permission: ?string, validate: ?callable}> */
	private static array $tabs = [];

	/** @throws \InvalidArgumentException On an id that can't be an HTML id / form key. */
	public static function add(string $id, string $label, callable $render, ?string $permission = null, ?callable $validate = null): void {
		if (!preg_match('/^[a-z0-9_-]+$/', $id)) {
			throw new \InvalidArgumentException("StreamFormRegistry: invalid tab id '{$id}'");
		}
		self::$tabs[$id] = ['label' => $label, 'render' => $render, 'permission' => $permission, 'validate' => $validate];
	}

	/**
	 * The tabs the current admin may see.
	 *
	 * @return array<string, array{label: string, render: callable, permission: ?string, validate: ?callable}>
	 */
	public static function tabs(): array {
		return array_filter(self::$tabs, static fn(array $rTab): bool => $rTab['permission'] === null || Authorization::check('adv', $rTab['permission']));
	}

	/**
	 * The posted `module[<id>]` fields of the tabs the current admin may see —
	 * nothing else a request sends under `module` is trusted.
	 *
	 * @return array<string, array>
	 */
	public static function posted(array $rData): array {
		return array_intersect_key(array_filter((array) ($rData['module'] ?? []), 'is_array'), self::tabs());
	}

	/**
	 * The first module refusal of a save, or null.
	 *
	 * @param array<string, array> $rPosted From posted().
	 * @param array|null           $rStream The stream row when editing.
	 */
	public static function validate(array $rPosted, ?array $rStream): ?string {
		foreach (self::tabs() as $rID => $rTab) {
			$rError = $rTab['validate'] === null ? null : ($rTab['validate'])($rPosted[$rID] ?? [], $rStream);
			if ($rError !== null) {
				return $rError;
			}
		}
		return null;
	}

	/** Forget every tab (bootAll() re-registers them). */
	public static function reset(): void {
		self::$tabs = [];
	}
}
