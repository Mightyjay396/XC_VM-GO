<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\Stage\AttributionVerificationStage;
use XcVm\Core\Bootstrap\StageProfiles;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Enum\BootContext;
use XcVm\Core\Error\ErrorResponder;
use XcVm\Core\Integrity\AttributionGuard;
use XcVm\Core\Util\AdminHelpers;

/**
 * Covers the AGPL §7(b) attribution guard: the single-source notice keeps its
 * markers, hasMarkers() detects tampering, the boot stage self-skips on CLI, and
 * the stage is wired to UI scopes only (not streaming).
 */
final class AttributionGuardTest extends TestCase {
	private const REQUIRED = [
		'https://github.com/Vateron-Media/XC_VM',
		'Vateron Media',
		'https://www.gnu.org/licenses/agpl-3.0.html',
	];

	public function testAttributionSourceCarriesEveryMarker(): void {
		$rHtml = AdminHelpers::getAttribution();

		foreach (self::REQUIRED as $rMarker) {
			$this->assertStringContainsString($rMarker, $rHtml);
		}
	}

	public function testVerifyPassesForIntactAttribution(): void {
		$this->assertTrue(AttributionGuard::verify());
	}

	public function testHasMarkersTrueWhenAllPresent(): void {
		$this->assertTrue(AttributionGuard::hasMarkers(AdminHelpers::getAttribution()));
	}

	public function testHasMarkersFalseWhenAnyMarkerStripped(): void {
		// Remove each required marker in turn; every removal must fail the check.
		$rBase = AdminHelpers::getAttribution();
		foreach (self::REQUIRED as $rMarker) {
			$rTampered = str_replace($rMarker, 'Acme Panel', $rBase);
			$this->assertFalse(
				AttributionGuard::hasMarkers($rTampered),
				"Stripping '{$rMarker}' should fail the attribution check"
			);
		}
	}

	public function testHasMarkersFalseOnEmptyString(): void {
		$this->assertFalse(AttributionGuard::hasMarkers(''));
	}

	/** The stage is inert on CLI so the operator can always restore the notice. */
	public function testStageSelfSkipsOnCli(): void {
		$this->expectNotToPerformAssertions();
		$state = new BootState(BootContext::Minimal, [], ServiceContainer::getInstance());

		(new AttributionVerificationStage())->run($state);

		ServiceContainer::resetInstance();
	}

	public function testErrorCodeRegistered(): void {
		$this->assertArrayHasKey('ATTRIBUTION_REMOVED', ErrorResponder::codes());
	}

	/** Enforcement is scoped to the UI (Admin) boot profile, never to streaming. */
	public function testStageWiredToAdminScopeOnly(): void {
		$rAdmin = StageProfiles::for(BootContext::Admin, []);
		$rStream = StageProfiles::for(BootContext::Stream, []);

		$this->assertTrue($this->hasAttributionStage($rAdmin), 'Admin UI must enforce attribution');
		$this->assertFalse($this->hasAttributionStage($rStream), 'Streaming must NOT be gated by attribution');
	}

	/**
	 * @param list<object> $rStages
	 */
	private function hasAttributionStage(array $rStages): bool {
		foreach ($rStages as $rStage) {
			if ($rStage instanceof AttributionVerificationStage) {
				return true;
			}
		}

		return false;
	}
}
