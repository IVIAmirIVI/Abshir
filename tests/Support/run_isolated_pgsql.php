<?php

/** A disposable schema inside abshir_testing; never drops/resets an owner's database. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'abshir_testing', 'DB_URL' => '', 'DB_SCHEMA' => 'public'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertSafe($app);
$schema = 'mc_testing_'.bin2hex(random_bytes(8));
$configPath = base_path('.phpunit-mc-'.bin2hex(random_bytes(8)).'.xml');
$config = file_get_contents(base_path('phpunit.pgsql.xml'));
$config = str_replace('name="DB_SCHEMA" value="public"', 'name="DB_SCHEMA" value="'.$schema.'"', $config);
DB::statement('CREATE SCHEMA "'.$schema.'"');
try {
    file_put_contents($configPath, $config);
    echo 'Verified current_database() = abshir_testing; isolated schema = '.$schema.PHP_EOL;
    $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), '-d', 'memory_limit=512M',
        base_path('vendor/phpunit/phpunit/phpunit'), '-c', $configPath, ...array_slice($argv, 1)],
        base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'abshir_testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_SCHEMA' => $schema]);
    $process->setTimeout(3600);
    $result = $process->run(static function ($type, $bytes): void {
        echo $bytes;
    });
} finally {
    if (file_exists($configPath)) {
        unlink($configPath);
    }
    // The generated schema is the only deletion target, on the previously verified testing connection.
    TestDatabaseSafety::assertSafe($app);
    DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
}
exit($result);
