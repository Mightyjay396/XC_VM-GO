<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Localization\Translator;

/**
 * Coverage for Translator::isRtl — decides whether the current (or a given)
 * language is right-to-left, which drives the `dir` attribute and rtl.css load.
 */
final class TranslatorIsRtlTest extends TestCase {

	public function testRtlLanguagesAreDetected(): void {
		$this->assertTrue(Translator::isRtl('ar'));
		$this->assertTrue(Translator::isRtl('FA'));
	}

	public function testLtrLanguagesAreNot(): void {
		$this->assertFalse(Translator::isRtl('en'));
		$this->assertFalse(Translator::isRtl('ru'));
	}

	public function testNullFallsBackToCurrentLanguage(): void {
		// Whatever the current language is, the null form must equal the explicit one.
		$this->assertSame(Translator::isRtl(Translator::current()), Translator::isRtl());
	}
}
