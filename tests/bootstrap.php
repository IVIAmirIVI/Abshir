<?php

require dirname(__DIR__).'/vendor/autoload.php';

$application = require dirname(__DIR__).'/bootstrap/app.php';
$application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Tests\Support\TestDatabaseSafety::assertSafe($application);

// Do not leave bootstrap handlers in PHPUnit's baseline handler stack.
Illuminate\Foundation\Bootstrap\HandleExceptions::flushState();
