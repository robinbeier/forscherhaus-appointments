<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Non-persistent session handler for the narrowly classified read-only probe.
 */
class Session_null_driver implements SessionHandlerInterface
{
    public function open($save_path, $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($session_id): string
    {
        return '';
    }

    public function write($session_id, $session_data): bool
    {
        return true;
    }

    public function destroy($session_id): bool
    {
        return true;
    }

    public function gc($maxlifetime): int|false
    {
        return 0;
    }
}
