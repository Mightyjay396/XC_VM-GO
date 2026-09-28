<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\StreamSecret;
use XcVm\Core\Util\Encryption;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The viewer-token secret's full rotation (plan, section 10, step 4; ADR 0004,
 * Phase 9): `cluster:rotate-stream-secret`.
 *
 * A settings save that changes `live_streaming_pass` already keeps every link
 * in players' hands readable for StreamSecret::PREVIOUS_WINDOW. What it does
 * not do is re-encrypt what is *stored* under the secret, and that is what a
 * rotation after a leak needs:
 *
 * - `hmac_keys.key`, each an Encryption::encrypt() of the HMAC secret;
 * - the image cache's file names (ImageUtils::downloadImage), each an
 *   Encryption::encrypt() of the source URL, and the `s:<sid>:/images/<name>`
 *   references the catalogue holds to them (REFERENCES).
 *
 * The job is resumable: its state lives in `cluster_meta` (META) from the
 * moment it starts to the moment it ends, with the old and the new value, the
 * phase it is in and a cursor, and each step is idempotent, so a run cut short
 * (a crash, a lost connection, ^C) is finished by running the command again.
 *
 * 1. `switch`: the new value goes into `settings`, and the old one into
 *    StreamSecret's previous-value file, so tokens minted under it stay
 *    readable for the window; nodes that take commands get `config.changed`
 *    for their `secrets` section at once (the others read it within a minute).
 * 2. `hmac`: every row whose key opens under the old value is re-encrypted
 *    under the new one, in id order. AuthService::validateHMAC() tries the
 *    previous value while a row is still the old one's.
 * 3. `images`: each of MAIN's cache files whose name opens under the old value
 *    is renamed to the new value's name, its references first — a run cut
 *    between the two finds the file under its old name and the references
 *    already moved, and finishes the move.
 * 4. done: the state goes, `stream_secret_rotated_at` is stamped, and the
 *    rotation is audited.
 *
 * Only MAIN's own image files are renamed: a load balancer's (`s:<lb sid>:`)
 * live on that node, and keep the names they have; the self-heal in
 * `tools images` cannot reverse those any more, which costs a re-download.
 */
final class StreamSecretRotation {
	use DatabaseAware;

	/** The job's state in cluster_meta while it runs. */
	public const META = 'stream_secret_rotation';

	/** When the last rotation ended (unix seconds), in cluster_meta. */
	public const DONE_META = 'stream_secret_rotated_at';

	/** Rows or files per step between two saves of the cursor. */
	public const BATCH = 200;

	/**
	 * The catalogue columns that hold image references: whole values or JSON
	 * (a movie's properties, a series' seasons), rewritten by file name.
	 */
	public const REFERENCES = [
		'streams' => ['stream_icon', 'movie_properties'],
		'streams_series' => ['cover', 'cover_big', 'backdrop_path', 'seasons'],
	];

	/** @var callable(string): void */
	private $rOut;

	private string $rImages;

	/**
	 * @param callable(string): void|null $rOut      progress lines (the CLI echoes them)
	 * @param string|null                 $rImages   MAIN's image cache (IMAGES_PATH)
	 */
	public function __construct(?callable $rOut = null, ?string $rImages = null) {
		$this->rOut = $rOut ?? static function (string $rLine): void {
		};
		$this->rImages = $rImages ?? (defined('IMAGES_PATH') ? (string) IMAGES_PATH : '');
	}

	/**
	 * The nodes that stop a rotation: every enrolled node whose DATAPLANE
	 * flow is off. Their relays and file pulls still carry the secret in URLs,
	 * so a new value would be on the wire again at once (plan, section 12,
	 * step 7: the rotation runs once DATAPLANE is on everywhere).
	 *
	 * @return list<int> server ids
	 */
	public static function blockers(): array {
		$rOut = [];
		foreach (NodeRegistry::enrolled() as $rServerID => $rNode) {
			if (((int) $rNode['flows'] & NodeRegistry::FLOW_DATAPLANE) === 0) {
				$rOut[] = (int) $rServerID;
			}
		}
		return $rOut;
	}

	/**
	 * The job in progress, or null when none is.
	 *
	 * @return array{id: string, phase: string, old: string, new: string, started_at: int, cursor: int|string, hmac: int, images: int}|null
	 */
	public static function inProgress(): ?array {
		$rRaw = ClusterMeta::get(self::META);
		$rJob = $rRaw === null ? null : json_decode($rRaw, true);
		if (!is_array($rJob) || !is_string($rJob['old'] ?? null) || !is_string($rJob['new'] ?? null) || !in_array($rJob['phase'] ?? null, ['switch', 'hmac', 'images'], true)) {
			return null;
		}
		return [
			'id' => (string) ($rJob['id'] ?? ''),
			'phase' => (string) $rJob['phase'],
			'old' => $rJob['old'],
			'new' => $rJob['new'],
			'started_at' => (int) ($rJob['started_at'] ?? 0),
			'cursor' => is_int($rJob['cursor'] ?? null) || is_string($rJob['cursor'] ?? null) ? $rJob['cursor'] : 0,
			'hmac' => (int) ($rJob['hmac'] ?? 0),
			'images' => (int) ($rJob['images'] ?? 0),
		];
	}

	/**
	 * Start a rotation (or pick up the one in progress) and run it to its end.
	 * $rNew is the new value (a fresh one when null); ignored when resuming.
	 * $rCrypto, when given, sends `config.changed` to the nodes that take
	 * commands. Returns the finished job's counts.
	 *
	 * @return array{resumed: bool, hmac: int, images: int, notified: int}
	 */
	public function run(?string $rNew = null, ?ClusterCrypto $rCrypto = null, ?int $rNow = null): array {
		$rNow ??= time();
		$rJob = self::inProgress();
		$rResumed = $rJob !== null;
		if ($rJob === null) {
			$rOld = (string) (self::settingsRow()['live_streaming_pass'] ?? '');
			if ($rOld === '') {
				throw new \RuntimeException('no live_streaming_pass to rotate');
			}
			$rNew ??= Encryption::randomString(40);
			if ($rNew === '' || hash_equals($rOld, $rNew)) {
				throw new \InvalidArgumentException('the new secret must differ from the current one');
			}
			$rJob = ['id' => bin2hex(random_bytes(8)), 'phase' => 'switch', 'old' => $rOld, 'new' => $rNew, 'started_at' => $rNow, 'cursor' => 0, 'hmac' => 0, 'images' => 0];
			self::save($rJob);
			ClusterAudit::log('cluster.stream_secret_rotation', null, ['id' => $rJob['id'], 'step' => 'started'], 'cli');
		} else {
			($this->rOut)('Resuming rotation ' . $rJob['id'] . ' at its ' . $rJob['phase'] . ' step');
		}
		$rNotified = 0;
		if ($rJob['phase'] === 'switch') {
			$rNotified = $this->switchSecret($rJob, $rCrypto, $rNow);
			$rJob['phase'] = 'hmac';
			$rJob['cursor'] = 0;
			self::save($rJob);
		}
		if ($rJob['phase'] === 'hmac') {
			$this->hmac($rJob);
			$rJob['phase'] = 'images';
			$rJob['cursor'] = '';
			self::save($rJob);
		}
		$this->images($rJob);
		self::db()->query('DELETE FROM `cluster_meta` WHERE `name` = ?;', self::META);
		ClusterMeta::set(self::DONE_META, (string) $rNow);
		ClusterAudit::log('cluster.stream_secret_rotated', null, ['id' => $rJob['id'], 'hmac' => $rJob['hmac'], 'images' => $rJob['images'], 'resumed' => $rResumed], 'cli');
		return ['resumed' => $rResumed, 'hmac' => $rJob['hmac'], 'images' => $rJob['images'], 'notified' => $rNotified];
	}

	/**
	 * The new value into settings, the old into StreamSecret's window, and the
	 * nodes told. Idempotent: a settings row already on the new value is left
	 * as it is, and the old value is kept again (its window restarts, which a
	 * resumed job wants: its hmac and images steps still read under it).
	 *
	 * @param array{old: string, new: string} $rJob
	 */
	private function switchSecret(array $rJob, ?ClusterCrypto $rCrypto, int $rNow): int {
		self::db()->query('UPDATE `settings` SET `live_streaming_pass` = ?;', $rJob['new']);
		StreamSecret::replaced($rJob['old'], $rJob['new'], $rNow);
		if (defined('CACHE_TMP_PATH')) {
			SettingsManager::clearCache();
		}
		($this->rOut)('The new secret is current; the old one stays readable for ' . StreamSecret::PREVIOUS_WINDOW . ' s');
		$rNotified = 0;
		if ($rCrypto !== null) {
			foreach (NodeRegistry::enrolled() as $rServerID => $rNode) {
				if (!CommandBus::accepts($rNode) || (int) $rNode['mode'] < 1) {
					continue;
				}
				try {
					CommandBus::enqueue($rCrypto, (int) $rServerID, 'config.changed', ['sections' => ['secrets']], 'config.changed');
					$rNotified++;
				} catch (\Throwable $rE) {
					($this->rOut)('Server ' . $rServerID . ' was not told (' . $rE->getMessage() . '); it reads the secret within a minute');
				}
			}
		}
		return $rNotified;
	}

	/**
	 * Re-encrypt `hmac_keys.key` from the cursor on. A key that does not open
	 * under the old value (already the new value's, or never the old one's) is
	 * left alone.
	 *
	 * @param array<string, mixed> $rJob
	 * @param-out array<string, mixed> $rJob
	 */
	private function hmac(array &$rJob): void {
		while (true) {
			self::db()->query('SELECT `id`, `key` FROM `hmac_keys` WHERE `id` > ? ORDER BY `id` ASC LIMIT ' . self::BATCH . ';', (int) $rJob['cursor']);
			$rRows = self::db()->get_rows();
			if ($rRows === []) {
				return;
			}
			foreach ($rRows as $rRow) {
				$rPlain = self::open((string) $rRow['key'], (string) $rJob['old']);
				if ($rPlain !== null && self::open((string) $rRow['key'], (string) $rJob['new']) === null) {
					self::db()->query('UPDATE `hmac_keys` SET `key` = ? WHERE `id` = ?;', Encryption::encrypt($rPlain, (string) $rJob['new'], self::extra()), (int) $rRow['id']);
					$rJob['hmac']++;
				}
				$rJob['cursor'] = (int) $rRow['id'];
			}
			self::save($rJob);
		}
	}

	/**
	 * Rename MAIN's image cache files from the cursor (a file name) on, in name
	 * order, their references first.
	 *
	 * @param array<string, mixed> $rJob
	 * @param-out array<string, mixed> $rJob
	 */
	private function images(array &$rJob): void {
		if ($this->rImages === '' || !is_dir($this->rImages)) {
			return;
		}
		$rFiles = [];
		foreach (scandir($this->rImages) ?: [] as $rName) {
			if (preg_match('/^[A-Za-z0-9_-]+\.(jpg|jpeg|png)$/i', $rName) && !str_starts_with($rName, 'h_') && strcmp($rName, (string) $rJob['cursor']) > 0 && is_file($this->rImages . $rName)) {
				$rFiles[] = $rName;
			}
		}
		sort($rFiles, SORT_STRING);
		$rServerID = defined('SERVER_ID') ? (int) SERVER_ID : 0;
		foreach (array_chunk($rFiles, self::BATCH) as $rChunk) {
			foreach ($rChunk as $rName) {
				$rExt = pathinfo($rName, PATHINFO_EXTENSION);
				$rUrl = Encryption::decrypt(pathinfo($rName, PATHINFO_FILENAME), (string) $rJob['old'], self::extra());
				if (is_string($rUrl) && stripos($rUrl, 'http') === 0) {
					$rNew = Encryption::encrypt($rUrl, (string) $rJob['new'], self::extra()) . '.' . $rExt;
					if (strlen($rNew) > 250) {
						$rNew = 'h_' . hash('sha256', $rUrl) . '.' . $rExt; // ImageUtils' name for a long URL
					}
					$this->moveReferences($rServerID, $rName, $rNew);
					if (!is_file($this->rImages . $rNew) && !@rename($this->rImages . $rName, $this->rImages . $rNew)) {
						throw new \RuntimeException('cannot rename ' . $rName);
					}
					if (is_file($this->rImages . $rName) && is_file($this->rImages . $rNew)) {
						@unlink($this->rImages . $rName); // the new name was already there
					}
					$rJob['images']++;
				}
				$rJob['cursor'] = $rName;
			}
			self::save($rJob);
		}
	}

	/** Rewrite every reference to MAIN's $rOld image as $rNew. */
	private function moveReferences(int $rServerID, string $rOld, string $rNew): void {
		foreach (self::REFERENCES as $rTable => $rColumns) {
			foreach ($rColumns as $rColumn) {
				$rOk = self::db()->query('UPDATE `' . $rTable . '` SET `' . $rColumn . '` = REPLACE(`' . $rColumn . '`, ?, ?) WHERE INSTR(`' . $rColumn . '`, ?) > 0;', $rOld, $rNew, $rOld);
				if ($rOk === false) {
					throw new \RuntimeException('cannot rewrite ' . $rTable . '.' . $rColumn . ' for server ' . $rServerID);
				}
			}
		}
	}

	/**
	 * An HMAC key's secret under $rKey, or null. A wrong key fails AES-CBC's
	 * padding check nearly always; the secret being printable (the admin's
	 * keygen) rules out the rest, so a resumed run never takes a key already
	 * re-encrypted for one still under the old value.
	 */
	private static function open(string $rValue, string $rKey): ?string {
		$rPlain = Encryption::decrypt($rValue, $rKey, self::extra());
		return is_string($rPlain) && $rPlain !== '' && ctype_print($rPlain) ? $rPlain : null;
	}

	private static function extra(): string {
		return defined('OPENSSL_EXTRA') ? (string) OPENSSL_EXTRA : '';
	}

	/** @param array<string, mixed> $rJob */
	private static function save(array $rJob): void {
		ClusterMeta::set(self::META, (string) json_encode($rJob, JSON_UNESCAPED_SLASHES));
	}

	/** @return array<string, mixed> */
	private static function settingsRow(): array {
		self::db()->query('SELECT `live_streaming_pass` FROM `settings` LIMIT 1;');
		$rRow = self::db()->get_row();
		return is_array($rRow) ? $rRow : [];
	}
}
