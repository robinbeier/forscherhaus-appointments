<?php

declare(strict_types=1);

foreach (glob(dirname(__DIR__) . '/release-gate/lib/*.php') ?: [] as $file) {
    require_once $file;
}
