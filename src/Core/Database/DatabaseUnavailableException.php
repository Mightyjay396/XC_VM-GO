<?php

namespace XcVm\Core\Database;

/**
 * A graceful LazyDatabaseHandler could not open its connection: the database
 * is down, and the caller answers for it instead of the process exiting.
 */
final class DatabaseUnavailableException extends \RuntimeException {
}
