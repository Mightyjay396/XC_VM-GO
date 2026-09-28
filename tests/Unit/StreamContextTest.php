<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Http\Pipeline\StreamContext;

/** StreamContext, kept for the deprecated stream-middleware contract. */
final class StreamContextTest extends TestCase {
    public function testAttributeBag(): void {
        $ctx = new StreamContext(1, 'u', 'hls', []);
        $ctx->set('key', 'value');

        $this->assertTrue($ctx->has('key'));
        $this->assertSame('value', $ctx->get('key'));
        $this->assertNull($ctx->get('missing'));
    }

    public function testAbort(): void {
        $ctx = new StreamContext(1, 'u', 'hls', []);
        $this->assertFalse($ctx->isAborted());
        $ctx->abort('blocked', 403);

        $this->assertTrue($ctx->isAborted());
        $this->assertSame('blocked', $ctx->getAbortReason());
        $this->assertSame(403, $ctx->getAbortCode());
    }
}
