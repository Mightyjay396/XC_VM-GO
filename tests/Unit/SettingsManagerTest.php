<?php

use XcVm\Core\Config\SettingsManager;
use PHPUnit\Framework\TestCase;

/**
 * SettingsManager — the process-global settings store read across the app.
 *
 * Locks the set/get roundtrip, single-key update, and the typed getters. Two
 * families differ deliberately on a present-but-null value: get()/getArray()
 * use `?? default` (null falls back to the default), while getBool/getInt/
 * getString use array_key_exists (null is cast). getBool() also mirrors PHP
 * truthiness so it matches the legacy `if ($settings['key'])` checks it replaced.
 */
final class SettingsManagerTest extends TestCase {

	protected function setUp(): void {
		SettingsManager::set([]);
	}

	protected function tearDown(): void {
		SettingsManager::set([]);
	}

	public function testSetGetAllRoundtrips(): void {
		SettingsManager::set(['a' => '1', 'b' => 'two']);
		$this->assertSame(['a' => '1', 'b' => 'two'], SettingsManager::getAll());
	}

	public function testGetReturnsValueOrDefault(): void {
		SettingsManager::set(['present' => 'x']);
		$this->assertSame('x', SettingsManager::get('present'));
		$this->assertNull(SettingsManager::get('missing'));
		$this->assertSame('fallback', SettingsManager::get('missing', 'fallback'));
	}

	public function testUpdateSetsASingleKeyWithoutTouchingOthers(): void {
		SettingsManager::set(['keep' => '1']);
		SettingsManager::update('added', 42);

		$this->assertSame(42, SettingsManager::get('added'));
		$this->assertSame('1', SettingsManager::get('keep'));
	}

	public function testHasUsesKeyExistenceIncludingNullValues(): void {
		SettingsManager::set(['nullish' => null]);
		$this->assertTrue(SettingsManager::has('nullish'), 'present even when null');
		$this->assertFalse(SettingsManager::has('missing'));

		// get() collapses a present-null to the default (via ??), unlike has().
		$this->assertSame('def', SettingsManager::get('nullish', 'def'));
	}

	public function testGetBoolMirrorsPhpTruthiness(): void {
		SettingsManager::set([
			'zero_str'  => '0',
			'empty_str' => '',
			'one_str'   => '1',
			'word'      => 'yes',
			'int_zero'  => 0,
			'int_one'   => 1,
		]);

		$this->assertFalse(SettingsManager::getBool('zero_str'), "'0' is falsy");
		$this->assertFalse(SettingsManager::getBool('empty_str'));
		$this->assertTrue(SettingsManager::getBool('one_str'));
		$this->assertTrue(SettingsManager::getBool('word'));
		$this->assertFalse(SettingsManager::getBool('int_zero'));
		$this->assertTrue(SettingsManager::getBool('int_one'));

		$this->assertFalse(SettingsManager::getBool('missing'));
		$this->assertTrue(SettingsManager::getBool('missing', true), 'default used only when absent');
	}

	public function testGetIntCoerces(): void {
		SettingsManager::set(['num' => '42abc', 'clean' => '7']);
		$this->assertSame(42, SettingsManager::getInt('num'));
		$this->assertSame(7, SettingsManager::getInt('clean'));
		$this->assertSame(0, SettingsManager::getInt('missing'));
		$this->assertSame(5, SettingsManager::getInt('missing', 5));
	}

	public function testGetStringCoerces(): void {
		SettingsManager::set(['n' => 123]);
		$this->assertSame('123', SettingsManager::getString('n'));
		$this->assertSame('', SettingsManager::getString('missing'));
		$this->assertSame('d', SettingsManager::getString('missing', 'd'));
	}

	public function testGetArrayReturnsArraysOnlyElseDefault(): void {
		SettingsManager::set(['list' => ['a', 'b'], 'scalar' => 'x']);
		$this->assertSame(['a', 'b'], SettingsManager::getArray('list'));
		$this->assertSame([], SettingsManager::getArray('scalar'), 'non-array → default');
		$this->assertSame(['fallback'], SettingsManager::getArray('missing', ['fallback']));
	}
}
