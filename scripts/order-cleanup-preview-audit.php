<?php

/**
 * Preview-only, read-only cleanup preflight. Never restores, migrates or deletes.
 * Exit 2 means cleanup is blocked, not permission to bypass financial history guards.
 */

require dirname(__DIR__).'/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
if (($_ENV['DB_CONNECTION'] ?? '') !== 'pgsql' || ! empty($_ENV['DB_URL'])
    || ($_ENV['DB_SCHEMA'] ?? 'public') !== 'public') {
    throw new RuntimeException('Unexpected connection or runtime schema configuration.');
}

$pdo = new PDO(
    'pgsql:host='.$_ENV['DB_HOST'].';port='.$_ENV['DB_PORT'].';dbname=abshir_cleanup_preview',
    $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec('SET default_transaction_read_only=on');
$identity = $pdo->query("SELECT current_database() AS database, pg_get_userbyid(datdba) AS owner,
    current_setting('transaction_read_only') AS read_only FROM pg_database WHERE datname=current_database()")->fetch(PDO::FETCH_ASSOC);
if ($identity !== ['database' => 'abshir_cleanup_preview', 'owner' => 'abshir_app', 'read_only' => 'on']) {
    throw new RuntimeException('Preview identity, owner or read-only boundary failed.');
}

$quote = static fn (string $name): string => '"'.str_replace('"', '""', $name).'"';
$rows = static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$snapshot = static function () use ($pdo, $rows, $quote): array {
    $tables = $rows("SELECT n.nspname AS schema,c.relname AS name FROM pg_class c
        JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.relkind IN ('r','p')
        AND n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_%'
        ORDER BY n.nspname,c.relname");
    $result = [];
    foreach ($tables as $table) {
        $name = $quote($table['schema']).'.'.$quote($table['name']);
        // Fingerprint full contents without exporting customer details, passwords or payment references.
        $result[$table['schema'].'.'.$table['name']] = $pdo->query("SELECT count(*) AS count,
            encode(sha256(convert_to(COALESCE(string_agg(row_hash,'' ORDER BY row_hash),''),'UTF8')),'hex') AS sha256
            FROM (SELECT encode(sha256(convert_to(to_jsonb(r)::text,'UTF8')),'hex') AS row_hash FROM {$name} r) h")
            ->fetch(PDO::FETCH_ASSOC);
    }

    return $result;
};

$pdo->beginTransaction();
$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$before = $snapshot();
$dependencies = $pdo->query("WITH RECURSIVE d(oid) AS (
    SELECT 'public.orders'::regclass::oid UNION
    SELECT c.conrelid FROM pg_constraint c JOIN d ON d.oid=c.confrelid WHERE c.contype='f'
    ) SELECT n.nspname AS schema,c.relname AS name FROM d JOIN pg_class c ON c.oid=d.oid
    JOIN pg_namespace n ON n.oid=c.relnamespace ORDER BY n.nspname,c.relname")->fetchAll(PDO::FETCH_ASSOC);

$foreignKeys = $rows("SELECT c.conname,c.conkey::text,c.confkey::text,c.confmatchtype,c.convalidated,
    nc.nspname AS child_schema,tc.relname AS child,np.nspname AS parent_schema,tp.relname AS parent,
    pg_get_constraintdef(c.oid) AS definition,
    (SELECT json_agg(a.attname ORDER BY k.ordinality) FROM unnest(c.conkey) WITH ORDINALITY k(attnum,ordinality)
        JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.attnum) AS child_columns,
    (SELECT json_agg(a.attname ORDER BY k.ordinality) FROM unnest(c.confkey) WITH ORDINALITY k(attnum,ordinality)
        JOIN pg_attribute a ON a.attrelid=c.confrelid AND a.attnum=k.attnum) AS parent_columns
    FROM pg_constraint c JOIN pg_class tc ON tc.oid=c.conrelid JOIN pg_namespace nc ON nc.oid=tc.relnamespace
    JOIN pg_class tp ON tp.oid=c.confrelid JOIN pg_namespace np ON np.oid=tp.relnamespace
    WHERE c.contype='f' AND nc.nspname='public' ORDER BY tc.relname,c.conname");
$danglingForeignKeys = [];
foreach ($foreignKeys as $fk) {
    $childColumns = json_decode($fk['child_columns'], true, flags: JSON_THROW_ON_ERROR);
    $parentColumns = json_decode($fk['parent_columns'], true, flags: JSON_THROW_ON_ERROR);
    $notNull = $anyNull = $join = [];
    foreach ($childColumns as $index => $column) {
        $columnSql = 'ch.'.$quote($column);
        $notNull[] = $columnSql.' IS NOT NULL';
        $anyNull[] = $columnSql.' IS NULL';
        $join[] = $columnSql.'=pa.'.$quote($parentColumns[$index]);
    }
    $childTable = $quote($fk['child_schema']).'.'.$quote($fk['child']);
    $parentTable = $quote($fk['parent_schema']).'.'.$quote($fk['parent']);
    $condition = '('.implode(' AND ', $notNull).') AND NOT EXISTS (SELECT 1 FROM '.$parentTable.' pa WHERE '.implode(' AND ', $join).')';
    if ($fk['confmatchtype'] === 'f') {
        $condition = '('.$condition.') OR (('.implode(' OR ', $notNull).') AND ('.implode(' OR ', $anyNull).'))';
    }
    $count = (int) $pdo->query('SELECT count(*) FROM '.$childTable.' ch WHERE '.$condition)->fetchColumn();
    if ($count !== 0) {
        $danglingForeignKeys[] = ['constraint' => $fk['conname'], 'count' => $count];
    }
}

$financialSources = $rows("SELECT t.id,t.source_type,t.source_id,t.event_type,t.source_version,t.status,
    CASE t.source_type WHEN 'INVOICE_COLLECTION' THEN ic.order_id WHEN 'ORDER_BILL_PAYMENT' THEN bp.order_id
        WHEN 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER' THEN bh.order_id END AS order_id,
    CASE t.source_type WHEN 'INVOICE_COLLECTION' THEN io.id WHEN 'ORDER_BILL_PAYMENT' THEN bo.id
        WHEN 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER' THEN ho.id END AS existing_order_id
    FROM public.financial_transactions t
    LEFT JOIN public.invoice_collections ic ON t.source_type='INVOICE_COLLECTION' AND t.source_id=ic.id::text
    LEFT JOIN public.orders io ON io.id=ic.order_id
    LEFT JOIN public.order_bill_payments bp ON t.source_type='ORDER_BILL_PAYMENT' AND t.source_id=bp.id::text
    LEFT JOIN public.orders bo ON bo.id=bp.order_id
    LEFT JOIN public.order_bill_payment_principal_handovers bh ON t.source_type='ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER' AND t.source_id=bh.id::text
    LEFT JOIN public.orders ho ON ho.id=bh.order_id ORDER BY t.id");
$supportedFamilies = ['INVOICE_COLLECTION', 'ORDER_BILL_PAYMENT', 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER'];
$unresolvedSources = array_values(array_filter($financialSources, static fn (array $source): bool => ! in_array($source['source_type'], $supportedFamilies, true) || $source['existing_order_id'] === null));
// Unknown families are unresolved, NEVER assumed independent or eligible for deletion.
$balanceSql = "SELECT a.id,a.account_kind,c.code,c.decimal_places,
    COALESCE(sum(CASE WHEN e.direction='INCREASE' THEN e.amount ELSE -e.amount END)
        FILTER(WHERE t.status='POSTED'),0)::text AS balance,count(e.id) AS entries
    FROM public.financial_accounts a JOIN public.currencies c ON c.id=a.currency_id
    LEFT JOIN public.financial_entries e ON e.financial_account_id=a.id
    LEFT JOIN public.financial_transactions t ON t.id=e.financial_transaction_id GROUP BY a.id,c.id ORDER BY a.id";
$balances = $rows($balanceSql);
$deleteGuards = $rows("SELECT c.relname AS table_name,t.tgname,t.tgenabled,pg_get_triggerdef(t.oid) AS definition,
    pg_get_functiondef(p.oid) AS function_definition FROM pg_trigger t
    JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace JOIN pg_proc p ON p.oid=t.tgfoid
    WHERE n.nspname='public' AND NOT t.tgisinternal AND (t.tgtype & 8)=8
    AND c.relname IN ('financial_transactions','financial_entries','invoice_collections','order_shop_purchase_confirmations',
        'order_financial_source_snapshots','order_financial_source_snapshot_lines','order_bill_payments',
        'order_bill_payment_evidence','order_bill_payment_principal_handovers','invoice_financial_corrections',
        'invoice_correction_financial_snapshots') ORDER BY c.relname,t.tgname");
$evidence = [];
$pathColumns = $rows("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='public'
    AND (column_name IN ('storage_path','evidence_path','file_path','attachment_path')
         OR (table_name='product_images' AND column_name='path')) ORDER BY table_name,column_name");
foreach ($pathColumns as $column) {
    $table = $quote($column['table_name']);
    $field = $quote($column['column_name']);
    $evidence[$column['table_name'].'.'.$column['column_name']] = $rows("SELECT id,{$field} AS path FROM public.{$table}
        WHERE {$field} IS NOT NULL ORDER BY id");
}
$corrections = $rows('SELECT id,order_id,kind,state FROM public.invoice_financial_corrections ORDER BY id');
$policies = [
    'delivery' => $rows('SELECT id,currency_id,effective_from,effective_until FROM public.delivery_pricing_rules ORDER BY id'),
    'bill_payment' => $rows('SELECT id,currency_id,effective_from,effective_until FROM public.bill_payment_fee_rules ORDER BY id'),
];
$pdo->commit();

// Separate committed snapshots prove the stopped preflight changed no restored table contents.
$pdo->beginTransaction();
$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$after = $snapshot();
$balancesAfter = $rows($balanceSql);
$pdo->commit();
$unchanged = $before === $after && $balances === $balancesAfter;
$blockers = [];
if (! $unchanged) {
    $blockers[] = 'Restored table contents changed between snapshots; investigate concurrent access.';
}
if ($danglingForeignKeys || $unresolvedSources) {
    $blockers[] = 'Dangling or unresolved financial/dependency links exist; attribution is not safe.';
}
if ($financialSources || (int) $before['public.order_shop_purchase_confirmations']['count'] > 0
    || (int) $before['public.invoice_collections']['count'] > 0 || $corrections) {
    $blockers[] = 'Order-linked financial/purchase/collection/correction history is protected by active PostgreSQL delete guards. No approved purge contract exists.';
}
$report = [
    'identity' => $identity, 'scope' => 'public Orders only; all other restored schemas preserved',
    'before' => $before, 'after' => $after, 'unchanged' => $unchanged,
    'deleted_rows' => 0, 'foreign_keys' => $foreignKeys, 'dangling_foreign_keys' => $danglingForeignKeys,
    'dependency_tables' => $dependencies, 'financial_sources' => $financialSources, 'unresolved_sources' => $unresolvedSources,
    'balances_before' => $balances, 'balances_after' => $balancesAfter, 'delete_guards' => $deleteGuards,
    'corrections' => $corrections, 'policies' => $policies, 'evidence_references' => $evidence,
    'blockers' => $blockers, 'outcome' => 'STOPPED_BEFORE_DELETE',
];
$directory = dirname(__DIR__).'/docs/order-cleanup-dry-run-2026-10-09';
if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create audit report directory.');
}
file_put_contents($directory.'/preview-audit.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode(['database' => $identity['database'], 'orders_before' => $before['public.orders']['count'],
    'orders_after' => $after['public.orders']['count'], 'deleted_rows' => 0, 'table_fingerprints_checked' => count($before),
    'all_contents_unchanged' => $unchanged, 'foreign_keys_checked' => count($foreignKeys),
    'dangling_foreign_keys' => count($danglingForeignKeys), 'unresolved_sources' => count($unresolvedSources),
    'balances' => $balances, 'blockers' => $blockers], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit($blockers ? 2 : 0);
