<?php

use XcVm\Core\Parsing\File;
use XcVm\Core\Parsing\StringWalker;
use XcVm\Core\Parsing\UniqueNode;
use XcVm\Core\Parsing\XmlStringStreamer;
use PHPUnit\Framework\TestCase;

/**
 * XmlStringStreamer — the vendored xml-string-streamer used to parse large EPG /
 * playlist XML one node at a time. These drive the real StringWalker and
 * UniqueNode parsers end to end over an in-memory stream, plus the File stream
 * source and the parsers' error paths.
 */
final class XmlStringStreamerTest extends TestCase {

	/** Build a seekable in-memory stream handle from a string. */
	private function streamFrom(string $xml)
	{
		$handle = fopen('php://memory', 'r+');
		fwrite($handle, $xml);
		rewind($handle);
		return $handle;
	}

	/** Drain every node the streamer yields. */
	private function collect(XmlStringStreamer $streamer): array {
		$nodes = [];
		while (($node = $streamer->getNode()) !== false) {
			$nodes[] = $node;
		}
		return $nodes;
	}

	public function testStringWalkerYieldsEachRootChildNode(): void {
		$xml = '<?xml version="1.0"?><tv>'
			. '<programme id="1">Alpha</programme>'
			. '<programme id="2">Beta</programme>'
			. '</tv>';

		$nodes = $this->collect(XmlStringStreamer::createStringWalkerParser($this->streamFrom($xml)));

		$this->assertCount(2, $nodes, 'one node per depth-2 child');
		$this->assertStringContainsString('id="1"', $nodes[0]);
		$this->assertStringContainsString('Alpha', $nodes[0]);
		$this->assertStringContainsString('id="2"', $nodes[1]);
		$this->assertStringContainsString('Beta', $nodes[1]);
	}

	public function testUniqueNodeParserExtractsNamedElements(): void {
		$xml = '<tv><channel id="c1"><name>One</name></channel>'
			. '<channel id="c2"><name>Two</name></channel></tv>';

		$streamer = XmlStringStreamer::createUniqueNodeParser(
			$this->streamFrom($xml),
			['uniqueNode' => 'channel']
		);
		$nodes = $this->collect($streamer);

		$this->assertCount(2, $nodes);
		$this->assertStringContainsString('id="c1"', $nodes[0]);
		$this->assertStringContainsString('<name>One</name>', $nodes[0]);
		$this->assertStringContainsString('id="c2"', $nodes[1]);
	}

	public function testEmptyDocumentYieldsNoNodes(): void {
		$nodes = $this->collect(XmlStringStreamer::createStringWalkerParser($this->streamFrom('<tv></tv>')));
		$this->assertSame([], $nodes);
	}

	public function testUniqueNodeRequiresTheUniqueNodeOption(): void {
		$this->expectException(\Exception::class);
		new UniqueNode([]);
	}

	public function testFileThrowsWhenPathDoesNotExist(): void {
		$this->expectException(\Exception::class);
		new File('/no/such/file/xc_vm_test_missing.xml');
	}

	public function testFileAcceptsAStreamResource(): void {
		$file = new File($this->streamFrom('<r><a/></r>'));
		$this->assertIsString($file->getChunk(), 'reads a chunk from the handle');
	}
}
