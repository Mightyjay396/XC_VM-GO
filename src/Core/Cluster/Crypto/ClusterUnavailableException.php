<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * The cluster crypto cannot run on this install: `xcvm_core` is missing, has
 * no cluster API, or its API version is outside the range this panel speaks
 * (or sodium/OpenSSL are missing). Callers keep the node legacy, and
 * `db_grant` stays the gate.
 */
final class ClusterUnavailableException extends \RuntimeException {
}
