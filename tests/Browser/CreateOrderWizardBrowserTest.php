<?php

namespace Tests\Browser;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopType;
use App\Models\Unit;
use App\Models\User;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\ProductService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CreateOrderWizardBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_browser_navigation_preserves_the_live_wizard_draft(): void
    {
        $this->runBrowser();
    }

    public function test_real_chrome_creates_a_single_cylinder_order_on_desktop_and_mobile(): void
    {
        $this->runBrowser(createCylinders: true);
    }

    public function test_creation_redirect_survives_vite_reload_and_continues_the_persisted_order(): void
    {
        $this->runBrowser(createCylinders: true, redirectProbe: true);
    }

    public function test_normal_and_special_creation_redirects_continue_the_same_order_and_preserve_history(): void
    {
        $this->runBrowser(createCylinders: true, redirectCoverage: true);
    }

    private function runBrowser(bool $createCylinders = false, bool $redirectProbe = false, bool $redirectCoverage = false): void
    {
        $redirectCoverage = $redirectCoverage || $redirectProbe;
        $node = getenv('WIZARD_NODE_PATH');
        if (! $node || ! getenv('WIZARD_PLAYWRIGHT_PATH') || ! getenv('WIZARD_CHROME_PATH')) {
            $this->markTestSkipped('Optional browser regression requires existing Node, Playwright and Chrome paths.');
        }
        $c = $this->context($createCylinders);
        $c['customer']->phones()->create(['phone' => '0944111333', 'normalized_phone' => '0944111333']);
        $c['customer']->addresses()->create(['area_id' => $c['area']->id, 'address_line_1' => 'عنوان بديل']);
        $secondProduct = Product::create(['name' => 'خبز', 'sku' => 'BREAD', 'default_unit_id' => $c['unit']->id]);
        $operator = $c['actor'];
        if (getenv('WIZARD_BROWSER_ROLE') === 'ORDER_EMPLOYEE') {
            $operator = User::factory()->create();
            $operator->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
            $operator->branches()->attach($c['branch']);
        }
        app(DeliveryPricingSettings::class)->update($c['actor'], '50', '100');
        $captains = [];
        for ($i = 0; $i < 2; $i++) {
            $captain = Captain::create(['user_id' => User::factory()->create()->id, 'home_branch_id' => $c['branch']->id, 'operational_status' => 'AVAILABLE']);
            $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
            $captains[] = (string) $captain->id;
        }
        $special = app(ProductService::class)->save($c['actor'], new Product, [
            'name' => $createCylinders ? 'أسطوانة غاز UAT' : 'منتج خاص', 'default_unit_id' => $c['unit']->id, 'is_special' => true,
            'special_policy' => ['currency_id' => $c['currency']->id, 'delivery_fee' => '250', 'allocation_mode' => 'FIXED', 'captain_fixed' => '150', 'company_fixed' => '100'],
        ]);
        $secondShop = Shop::create(['name' => 'محل إضافي', 'business_classification' => 'SHOP', 'area_id' => $c['area']->id]);
        Branch::factory()->create(['default_currency_id' => $c['currency']->id]);
        $this->rule($c['actor'], $c['area'], $c['area'], $c['currency'], 'MULTI_SHOP', '999');
        $schema = DB::scalar('SELECT current_schema()');
        $this->assertMatchesRegularExpression('/^mc_testing_[a-f0-9]{16}$/', $schema);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = parse_url('tcp://'.stream_socket_get_name($socket, false), PHP_URL_PORT);
        fclose($socket);
        $sessions = sys_get_temp_dir().'/wizard-browser-'.$schema;
        mkdir($sessions);
        $env = ['APP_ENV' => 'testing', 'APP_LOCALE' => 'ar', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'abshir_testing', 'DB_URL' => '', 'DB_SCHEMA' => $schema,
            'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'WIZARD_BROWSER_SESSION_PATH' => $sessions, 'WIZARD_BROWSER_URL' => 'http://127.0.0.1:'.$port,
            'WIZARD_BROWSER_EMAIL' => $operator->email, 'WIZARD_CUSTOMER_ID' => (string) $c['customer']->id,
            'WIZARD_PHONE_ID' => (string) $c['phone']->id, 'WIZARD_ADDRESS_ID' => (string) $c['address']->id,
            'WIZARD_SECOND_PRODUCT_ID' => (string) $secondProduct->id,
            'WIZARD_BRANCH_ID' => (string) $c['branch']->id,
            'WIZARD_SHOP_ID' => (string) $c['shop']->id, 'WIZARD_PRODUCT_ID' => (string) $c['product']->id];
        $env += ['WIZARD_SPECIAL_ID' => (string) $special->id, 'WIZARD_SECOND_SHOP_ID' => (string) $secondShop->id,
            'WIZARD_CAPTAIN_IDS' => json_encode($captains, JSON_THROW_ON_ERROR)];
        if ($createCylinders) {
            $env['ORDER_ACCOUNTING_V3_SINGLE_CAPTAIN'] = 'true';
        }
        if ($redirectProbe) {
            $env['WIZARD_REDIRECT_PROBE'] = '1';
            $env['WIZARD_USE_VITE'] = '1';
        }
        if ($redirectCoverage) {
            $env['WIZARD_REDIRECT_COVERAGE'] = '1';
        }
        DB::commit();
        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', public_path(), base_path('tests/Support/wizard_browser_router.php')], base_path(), $env);
        try {
            $server->start();
            $server->waitUntil(fn ($type, $output) => str_contains($output, 'Development Server'));
            $browser = new Process([$node, base_path($createCylinders ? 'tests/Support/special_cylinder_browser.cjs' : 'tests/Support/wizard_browser.cjs')], base_path(), $env);
            $browser->setTimeout(600);
            $browser->start();
            while ($browser->isRunning()) {
                // Keep the HTTP server's bounded Windows output pipes drained while Chrome runs.
                file_put_contents($sessions.'/server.log', $server->getIncrementalOutput().$server->getIncrementalErrorOutput(), FILE_APPEND);
                $browser->checkTimeout();
                usleep(10000);
            }
            echo $browser->getOutput();
            $this->assertSame(0, $browser->getExitCode(), $browser->getOutput().$browser->getErrorOutput());
            $this->assertDatabaseCount('orders', $createCylinders ? ($redirectCoverage ? 4 : 2) : 0);
            if ($createCylinders) {
                foreach (Order::all() as $order) {
                    $this->assertSame(3, $order->accounting_contract_version);
                    $this->assertSame($redirectCoverage ? 'CONFIRMED' : 'NEW', $order->operational_status->value);
                    $this->assertSame(1, $order->captainAssignments()->whereNull('removed_at')->count());
                    $item = $order->shops()->sole()->items()->sole();
                    $this->assertSame('1.0000', $item->current_quantity);
                    $this->assertNull($item->getRawOriginal('special_delivery_assignments'));
                    $row = $order->currentDeliveryPricing->allocations()->sole();
                    $specialItem = $item->special_delivery_policy_id !== null;
                    $this->assertSame($specialItem ? '250.0000' : '7.0000', $row->customer_delivery_amount_allocated);
                    $this->assertSame($specialItem ? '150.0000' : '4.0000', $row->captain_entitlement);
                    $this->assertSame($specialItem ? '100.0000' : '3.0000', $row->company_collectible);
                }
            }
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertDatabaseCount('financial_entries', 0);
        } finally {
            if (PHP_OS_FAMILY === 'Windows' && is_file($sessions.'/server.pid')) {
                $pid = (int) file_get_contents($sessions.'/server.pid');
                (new Process(['powershell', '-NoProfile', '-Command', 'Stop-Process -Id '.$pid]))->run();
            }
            $server->stop(0);
            DB::beginTransaction();
        }
    }

    private function context(bool $syp = false): array
    {
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => $syp ? 'SYP' : 'WIZ', 'name' => 'عملة', 'decimal_places' => $syp ? 0 : 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $area = Area::factory()->for($branch)->create(['name' => 'سقبا']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $customer = Customer::create(['name' => 'أمير']);
        $phone = $customer->phones()->create(['phone' => '0944111222', 'normalized_phone' => '0944111222', 'is_primary' => true]);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'عنوان أمير', 'is_primary' => true]);
        $type = ShopType::create(['name' => 'بقالة', 'business_classification' => 'SHOP']);
        $shop = Shop::create(['business_classification' => 'SHOP', 'name' => 'محل الاختبار', 'area_id' => $area->id, 'shop_type_id' => $type->id]);
        $unit = Unit::create(['name' => 'قطعة', 'code' => 'WIZ', 'allows_decimal' => false]);
        $product = Product::create(['name' => 'حليب', 'sku' => 'MILK', 'default_unit_id' => $unit->id]);
        $this->rule($actor, $area, $area, $currency, 'SINGLE_SHOP', '7');

        return compact('currency', 'branch', 'area', 'actor', 'customer', 'phone', 'address', 'type', 'shop', 'unit', 'product');
    }

    private function rule($actor, $origin, $destination, $currency, string $class, string $fee): void
    {
        app(DeliveryPricingRuleService::class)->create($actor, [
            'from_area_id' => $origin->id, 'to_area_id' => $destination->id, 'currency_id' => $currency->id,
            'order_pricing_class' => $class, 'delivery_unit_fee' => $fee, 'effective_from' => now()->subHour(),
        ]);
    }
}
