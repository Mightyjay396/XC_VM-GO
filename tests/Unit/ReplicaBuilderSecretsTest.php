<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ReplicaBuilder;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * The replica's `settings` section never carries a secret (cluster plan,
 * Phase 7): only the keys the LB build reads (lb_settings_keys.php, kept
 * current by make gates), less the withheld ones.
 */
final class ReplicaBuilderSecretsTest extends TestCase {
	/** Settings whose names look secret but are not: flags and public keys. */
	private const NOT_SECRET = ['disable_mag_token', 'secure_stream_tokens', 'recaptcha_v2_site_key', 'stb_change_pass', 'lb_token_rotation_min', 'maxmind_account_id', 'restreamer_bypass_proxy'];

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	public function testNoSecretLookingKeyIsAllowlisted(): void {
		$rKeys = ReplicaBuilder::settingsKeys();
		$this->assertGreaterThan(100, count($rKeys));
		$rSuspect = array_values(array_filter($rKeys, static fn(string $rKey): bool => (bool) preg_match('/pass|secret|token|licen|_key$|password|salt|private/', $rKey) && !in_array($rKey, self::NOT_SECRET, true)));
		$this->assertSame([], $rSuspect, 'a settings key that looks secret: withhold it (tools/ci/lb_settings_keys.php SECRETS) or vet it here');
		$rList = require dirname(__DIR__, 2) . '/src/Core/Cluster/lb_settings_keys.php';
		foreach (['api_pass', 'license', 'live_streaming_pass', 'redis_password', 'tmdb_api_key', 'recaptcha_v2_secret_key'] as $rSecret) {
			$this->assertNotContains($rSecret, $rKeys);
		}
		$this->assertSame([], array_values(array_intersect($rKeys, $rList['withheld'])));
	}

	public function testTheSectionHoldsOnlyAllowlistedKeys(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `settings` (`id` int, `server_name` text, `api_pass` text, `live_streaming_pass` text, `redis_password` text, `seg_time` int, `not_read_by_lbs` text)');
		$rDb->exec("INSERT INTO `settings` VALUES (1, 'XC', 'p1', 'p2', 'p3', 6, 'x')");
		DatabaseFactory::set($rDb);
		$this->assertSame(['id' => '1', 'seg_time' => '6', 'server_name' => 'XC'], ReplicaBuilder::settingsData());
	}
}
