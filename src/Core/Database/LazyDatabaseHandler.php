<?php

namespace XcVm\Core\Database;

/**
 * Lazy Database Handler
 *
 * A DatabaseHandler that does not connect until something needs the
 * connection: the first query, simple_query, escape or transaction. Streaming
 * endpoints use it (DatabaseFactory::connectLazy()), so a request that ends up
 * answering from cache or Redis opens no MySQL connection to MAIN at all —
 * the cluster API plan counts every such connect before a node may leave the
 * legacy link. When it does connect, it behaves exactly like the eager
 * handler, failure included.
 *
 * @package XC_VM_Core_Database
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LazyDatabaseHandler extends DatabaseHandler {
	private bool $rOpened = false;

	/** A graceful handle's connect failed: every later use fails the same way, without retrying. */
	private bool $rDown = false;

	/**
	 * Configured credentials via the xcvm_core extension, as `new DatabaseHandler()`; nothing is opened yet.
	 *
	 * @param bool $rGraceful A failed connect throws DatabaseUnavailableException, then and at every later use, instead of exiting.
	 */
	public function __construct(private bool $rGraceful = false) {
		$this->dbh = false;
	}

	/** Has the connection been opened (or tried)? */
	public function isOpen(): bool {
		return $this->rOpened;
	}

	private function open(): void {
		if ($this->rOpened) {
			if ($this->rDown) {
				throw new DatabaseUnavailableException('MySQL: cannot connect to the database');
			}
			return;
		}
		$this->rOpened = true;
		if (!$this->rGraceful) {
			$this->db_connect();
		} elseif (!$this->db_connect(false, true)) {
			$this->rDown = true;
			throw new DatabaseUnavailableException('MySQL: cannot connect to the database');
		}
	}

	public function query(string $query, mixed $buffered = false) {
		$this->open();
		return parent::query(...func_get_args());
	}

	public function simple_query(string $query) {
		$this->open();
		return parent::simple_query($query);
	}

	public function escape(?string $string) {
		$this->open();
		return parent::escape($string);
	}

	public function beginTransaction() {
		$this->open();
		return parent::beginTransaction();
	}

	/**
	 * An unopened handle is ready to use, not dead: report it alive so
	 * DatabaseFactory keeps it instead of replacing it with an eager one.
	 */
	public function ping() {
		return $this->rOpened ? parent::ping() : true;
	}
}
