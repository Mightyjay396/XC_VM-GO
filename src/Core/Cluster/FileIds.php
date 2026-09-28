<?php

namespace XcVm\Core\Cluster;

/**
 * Ids read from file names: the files one per stream (or recording) that the
 * node keeps, named `<id><suffix>`, where an id is a decimal of 1 to 10
 * digits without a leading zero. The agent's `replica/streams/<id>.json`
 * (ReplicaStreams), the stream caches' entries (ReplicaStreamCache) and the
 * node's own store (StreamRuntime) all name their files so.
 *
 * What a name that is not an id means is the caller's, so it is a flag: a
 * strict caller (the agent's section, where such a name makes PHP read no
 * section at all) gets false, any other skips the file (the caches' index,
 * a stray file in the node's own store).
 *
 * Lives in Core: it ships to LBs, where Domain\Cluster does not.
 */
final class FileIds {
	/** An id as a file names it. */
	public const PATTERN = '/^[1-9][0-9]{0,9}\z/';

	/** Is $rName (a file name, its suffix taken off) an id? */
	public static function valid(string $rName): bool {
		return preg_match(self::PATTERN, $rName) === 1;
	}

	/**
	 * The ids these files name (each file's base name with $rSuffix taken
	 * off), ascending. A name that is not an id makes the whole answer false
	 * with $rStrict; without it, that file is skipped.
	 *
	 * @param list<string> $rFiles paths, as glob() gives them
	 * @return ($rStrict is true ? list<int>|false : list<int>)
	 */
	public static function of(array $rFiles, string $rSuffix, bool $rStrict): array|false {
		$rOut = [];
		foreach ($rFiles as $rFile) {
			$rName = basename($rFile, $rSuffix);
			if (!self::valid($rName)) {
				if ($rStrict) {
					return false;
				}
				continue;
			}
			$rOut[] = (int) $rName;
		}
		sort($rOut);
		return $rOut;
	}
}
