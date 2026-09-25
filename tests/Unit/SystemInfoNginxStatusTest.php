<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\SystemInfo;

/**
 * SystemInfo::nginxRequestCount — the counter the watchdog samples for
 * servers.requests_per_second. stub_status reports accepts, handled and
 * requests on its third line; reading the wrong field would feed a connection
 * count into the request rate.
 */
final class SystemInfoNginxStatusTest extends TestCase {

	public function testTheRequestCountIsTheThirdCounter(): void {
		$rStatus = "Active connections: 3 \nserver accepts handled requests\n 10 10 57 \nReading: 0 Writing: 1 Waiting: 2 \n";

		$this->assertSame(57.0, SystemInfo::nginxRequestCount($rStatus));
	}

	public function testAnUnreachableOrMalformedStatusHasNoCount(): void {
		// The watchdog passes '' when the status page cannot be fetched.
		$this->assertNull(SystemInfo::nginxRequestCount(''));
		$this->assertNull(SystemInfo::nginxRequestCount("Active connections: 3 \nserver accepts handled requests\n"));
		$this->assertNull(SystemInfo::nginxRequestCount("Active connections: 3 \nserver accepts handled requests\n 10 10 \n"));
		$this->assertNull(SystemInfo::nginxRequestCount("<html>\n<head><title>404 Not Found</title></head>\n<body>x y z</body>\n"));
	}
}
