<?php

use XcVm\Core\Module\SourceDriverInterface;

/** A scriptable source driver for the source-driver tests. */
class TestSourceDriver implements SourceDriverInterface {
	public array $argv = [];
	public bool $up = true;

	public function __construct(private array $schemes, private string $binary = 'xcvm-test') {
	}

	public function schemes(): array {
		return $this->schemes;
	}

	public function binary(): string {
		return $this->binary;
	}

	public function buildArgv(array $ctx): array {
		return $this->argv ?: ['/opt/' . $this->binary, '-i', $ctx['url'], '-o', $ctx['hls']['dir'] . $ctx['hls']['playlist']];
	}

	public function available(int $streamId, string $url): bool {
		return $this->up;
	}
}
