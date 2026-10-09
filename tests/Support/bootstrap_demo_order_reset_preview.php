<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;

// Explicit opt-in only. This bootstrap does not load tests/bootstrap.php,
// phpunit.xml, TestDatabaseSafety, RefreshDatabase or any migration runner.
require dirname(__DIR__, 2).'/vendor/autoload.php';
if (getenv('ABSHIR_RESET_PREVIEW_TESTS') !== '1') {
    throw new RuntimeException('Set ABSHIR_RESET_PREVIEW_TESTS=1 to run the preview-only maintenance tests.');
}
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
if (! $application->environment('local', 'development')) {
    throw new RuntimeException('Preview maintenance tests require the ordinary development environment.');
}
HandleExceptions::flushState();
