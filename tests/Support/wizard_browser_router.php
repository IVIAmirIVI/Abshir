<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertSafe($app);
$schema = DB::scalar('SELECT current_schema()');
if (! preg_match('/^mc_testing_[a-f0-9]{16}$/', $schema) || $schema !== getenv('DB_SCHEMA')) {
    throw new RuntimeException('Browser test requires the runner disposable schema.');
}
file_put_contents(getenv('WIZARD_BROWSER_SESSION_PATH').'/server.pid', (string) getmypid());
// A leading-zero UUID deterministically exercises nested wire:model serialization.
Str::createUuidsUsing(fn () => Uuid::fromString('0a'.substr((string) Uuid::uuid4(), 2)));
config(['session.driver' => 'file', 'session.files' => getenv('WIZARD_BROWSER_SESSION_PATH'), 'session.cookie' => 'wizard_isolated_session', 'session.secure' => false]);
config(['logging.default' => 'single', 'logging.channels.single.path' => getenv('WIZARD_BROWSER_SESSION_PATH').'/laravel.log']);
// Authentication is a fixture prerequisite, not part of the wizard HMR regression.
if (! getenv('WIZARD_USE_VITE') || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/login') {
    Vite::useHotFile(sys_get_temp_dir().'/wizard-no-hot-'.getenv('DB_SCHEMA'));
}
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__wizard-test-identity') {
    header('Content-Type: application/json');
    echo json_encode(['environment' => app()->environment(), 'database' => DB::scalar('SELECT current_database()'), 'schema' => $schema]);

    return;
}
$path = realpath(public_path(ltrim(rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/')));
if ($path && is_file($path) && str_starts_with($path, realpath(public_path()).DIRECTORY_SEPARATOR) && in_array(pathinfo($path, PATHINFO_EXTENSION), ['js', 'css', 'svg', 'png', 'woff2'], true)) {
    return false;
}
$request = Request::capture();
$startedAt = microtime(true);
$requestLog = getenv('WIZARD_BROWSER_SESSION_PATH').'/http-requests.jsonl';
file_put_contents($requestLog, json_encode(['event' => 'start', 'method' => $request->method(), 'path' => $request->path()]).PHP_EOL, FILE_APPEND);
$app->handleRequest($request);
file_put_contents($requestLog, json_encode(['event' => 'finish', 'method' => $request->method(), 'path' => $request->path(),
    'status' => http_response_code(), 'duration_ms' => round((microtime(true) - $startedAt) * 1000)]).PHP_EOL, FILE_APPEND);
