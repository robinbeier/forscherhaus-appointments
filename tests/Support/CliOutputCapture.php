<?php

declare(strict_types=1);

namespace Tests\Support;

final class CliOutputCapture
{
    /** @var resource */
    public $stdout;

    /** @var resource */
    public $stderr;

    public function __construct()
    {
        $this->stdout = $this->openStream();
        $this->stderr = $this->openStream();
    }

    public function diagnostic(): string
    {
        return sprintf("stdout:\n%s\nstderr:\n%s", $this->stdoutContents(), $this->stderrContents());
    }

    public function stdoutContents(): string
    {
        return $this->read($this->stdout);
    }

    public function stderrContents(): string
    {
        return $this->read($this->stderr);
    }

    private function openStream()
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open CLI output capture stream.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private function read($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);

        return $contents === false ? '' : $contents;
    }

    public function __destruct()
    {
        fclose($this->stdout);
        fclose($this->stderr);
    }
}
