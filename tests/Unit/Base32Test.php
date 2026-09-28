<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\Base32;
use XcVm\Domain\Cluster\EnrolCodeService;
use XcVm\Domain\Cluster\EnrolmentService;

/**
 * The one base32 the enrolment code and the SAS are written in: RFC 4648
 * without padding. The vectors pinned below are what the two separate
 * encoders it replaced produced, so a code or a SAS reads the same as
 * before.
 */
final class Base32Test extends TestCase {
	public function testRfc4648VectorsWithoutPadding(): void {
		$rVectors = ['' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'];
		foreach ($rVectors as $rBytes => $rText) {
			$this->assertSame($rText, Base32::encode((string) $rBytes), (string) $rBytes);
			$this->assertSame((string) $rBytes, Base32::decode($rText), $rText);
		}
	}

	public function testDecodeRoundTripsAndRefusesOtherCharacters(): void {
		for ($i = 0; $i < 64; $i++) {
			$rBytes = $i === 0 ? '' : random_bytes($i);
			$this->assertSame($rBytes, Base32::decode(Base32::encode($rBytes)));
		}
		$this->assertNull(Base32::decode('MZXW6yq'), 'lower case is the caller\'s to fold');
		$this->assertNull(Base32::decode('MZXW1'));
		$this->assertNull(Base32::decode('MZXW6='), 'no padding');
	}

	public function testTheEnrolmentCodeIsWrittenAsBefore(): void {
		$rCode = EnrolCodeService::encode(42, 'https://main.example:8443', str_repeat("\xab", 16), (string) hex2bin('00112233445566778899aabbccddeeff'));
		$this->assertSame('AEAA-AABK-DFUH-I5DQ-OM5C-6L3N-MFUW-4LTF-PBQW-24DM-MU5D-QNBU-GOV2-XK5L-VOV2-XK5L-VOV2-XK5L-VOVQ-AEJC-GNCF-KZTX-RCM2-VO6M-3XXP-6', $rCode);
		$this->assertSame(
			['server_id' => 42, 'main_url' => 'https://main.example:8443', 'fp' => str_repeat("\xab", 16), 'secret' => (string) hex2bin('00112233445566778899aabbccddeeff')],
			EnrolCodeService::decode(strtolower(str_replace('-', ' ', $rCode)))
		);
		$this->assertNull(EnrolCodeService::decode(''));
		$this->assertNull(EnrolCodeService::decode('AEAA-1ABK'));
	}

	public function testTheSasIsWrittenAsBefore(): void {
		$this->assertSame('O55N-FFOP-AFF3-P4YX-Q4QP-MOVQ', EnrolmentService::sas('0f8e2b1c-3d4a-4b5c-8d6e-7f8091a2b3c4', str_repeat("\x01", 32), str_repeat("\x02", 32)));
	}
}
