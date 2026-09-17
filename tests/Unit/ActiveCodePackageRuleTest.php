<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Line\ActiveCodeService;

/**
 * Resellers generate and re-package activation codes only with packages their
 * group sells — the same list the reseller page offers. The service took any
 * package id from the request.
 */
final class ActiveCodePackageRuleTest extends TestCase {

	private function allowed(array $rPackage, array $rUser): bool {
		$rMethod = new ReflectionMethod(ActiveCodeService::class, 'packageAvailableTo');
		$rMethod->setAccessible(true);
		return $rMethod->invoke(null, $rPackage, $rUser);
	}

	public function testOnlyLinePackagesOfTheResellersGroup(): void {
		$rReseller = ['id' => 7, 'member_group_id' => 2];
		$this->assertTrue($this->allowed(['is_line' => 1, 'groups' => '[2,3]'], $rReseller));
		$this->assertTrue($this->allowed(['is_line' => '1', 'groups' => '["2"]'], $rReseller));
		$this->assertFalse($this->allowed(['is_line' => 1, 'groups' => '[3]'], $rReseller), 'another group');
		$this->assertFalse($this->allowed(['is_line' => 0, 'groups' => '[2]'], $rReseller), 'not a line package');
		$this->assertFalse($this->allowed(['is_line' => 1, 'groups' => ''], $rReseller), 'no groups');
	}
}
