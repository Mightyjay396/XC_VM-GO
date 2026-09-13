<?php

use XcVm\Core\Http\RequestManager;
use PHPUnit\Framework\TestCase;

/**
 * RequestManager — the process-global store of parsed request params, set once
 * by LegacyInitializer and read across legacy code. Mirrors SettingsManager but
 * its has() uses isset() semantics (a present-null key reads as absent), which
 * is the whole reason it exists (isset() can't wrap a get() call).
 */
final class RequestManagerTest extends TestCase {

	protected function setUp(): void {
		RequestManager::set([]);
	}

	protected function tearDown(): void {
		RequestManager::set([]);
	}

	public function testSetGetAllRoundtrips(): void {
		RequestManager::set(['action' => 'save', 'id' => '7']);
		$this->assertSame(['action' => 'save', 'id' => '7'], RequestManager::getAll());
	}

	public function testGetReturnsValueOrDefault(): void {
		RequestManager::set(['action' => 'save']);
		$this->assertSame('save', RequestManager::get('action'));
		$this->assertNull(RequestManager::get('missing'));
		$this->assertSame('def', RequestManager::get('missing', 'def'));
	}

	public function testUpdateSetsASingleKey(): void {
		RequestManager::set(['keep' => '1']);
		RequestManager::update('added', 'x');
		$this->assertSame('x', RequestManager::get('added'));
		$this->assertSame('1', RequestManager::get('keep'));
	}

	public function testHasUsesIssetSemantics(): void {
		RequestManager::set(['set' => 'v', 'nullish' => null]);
		$this->assertTrue(RequestManager::has('set'));
		$this->assertFalse(RequestManager::has('nullish'), 'present-null reads as absent (isset)');
		$this->assertFalse(RequestManager::has('missing'));
	}
}
