<?php

/** Compile Blade in an isolated temporary directory and compare changed translations. No migrations or writes to business data. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$temporaryRoot = realpath(sys_get_temp_dir());
$compiled = $temporaryRoot.DIRECTORY_SEPARATOR.'abshir-mc-blade-'.bin2hex(random_bytes(8));
mkdir($compiled, 0700);
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'abshir_testing',
    'DB_URL' => '', 'DB_SCHEMA' => 'public', 'VIEW_COMPILED_PATH' => $compiled,
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    TestDatabaseSafety::assertSafe($app);
    foreach (['shared_orders', 'domain'] as $group) {
        $ar = array_keys(Arr::dot(require base_path('lang/ar/'.$group.'.php')));
        $en = array_keys(Arr::dot(require base_path('lang/en/'.$group.'.php')));
        sort($ar);
        sort($en);
        if ($ar !== $en) {
            throw new RuntimeException('Translation key parity failed for '.$group);
        }
        echo 'Translation parity '.$group.': PASS ('.count($ar).' keys)'.PHP_EOL;
    }
    if (Artisan::call('view:cache') !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    echo 'Blade compilation: PASS ('.count(File::files($compiled)).' compiled files)'.PHP_EOL;
    echo 'Verified current_database(): abshir_testing; no migration, seeding or business writes.'.PHP_EOL;
} finally {
    // This freshly generated temp directory is the only cleanup target.
    $resolved = realpath($compiled);
    if ($resolved && str_starts_with($resolved, $temporaryRoot.DIRECTORY_SEPARATOR.'abshir-mc-blade-')) {
        File::deleteDirectory($resolved);
    }
}
