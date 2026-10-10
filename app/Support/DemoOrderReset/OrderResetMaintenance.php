<?php

namespace App\Support\DemoOrderReset;

use PDO;
use RuntimeException;

/** Owner-approved development maintenance, never a normal financial mutation path. */
final class OrderResetMaintenance
{
    private const TABLES = [
        'orders', 'order_shops', 'order_items', 'order_item_changes', 'order_captains', 'order_captain_selections',
        'order_status_history', 'order_delivery_pricings', 'order_delivery_allocations',
        'order_delivery_pricing_routes', 'delivery_fee_override_requests',
        'order_shop_purchase_confirmations', 'order_shop_purchase_allocations', 'order_shop_receipts',
        'order_financial_source_snapshots', 'order_financial_source_snapshot_lines',
        'invoices', 'invoice_items', 'invoice_collections', 'invoice_financial_corrections',
        'invoice_correction_financial_snapshots', 'invoice_correction_reversals',
        'invoice_correction_receivable_dispositions', 'invoice_correction_receivable_disposition_allocations',
        'customer_refunds', 'captain_ratings', 'order_bill_payment_requests', 'order_bill_payments',
        'order_bill_payment_evidence', 'order_bill_payment_principal_handovers',
        'order_cancellation_intents', 'order_cancellation_intent_shops', 'order_cancellations',
        'order_shop_returns', 'order_shop_return_evidence', 'captain_receivable_payouts',
        'captain_receivable_payout_allocations', 'captain_receivable_recoveries',
        'captain_receivable_recovery_allocations', 'financial_transactions', 'financial_entries',
    ];

    /** Inventory only; exposure does not extend the deletion allowlist. */
    public static function orderTables(): array
    {
        return self::TABLES;
    }

    private PDO $pdo;

    private string $database;

    private string $manifest;

    private array $tables;

    private array $foreignKeys;

    private array $before;

    private array $guards;

    private array $balancesBefore;

    private array $expectedBalances;

    public static function connect(array $connection, string $environment, bool $preview): PDO
    {
        if (! in_array($environment, ['local', 'development'], true)
            || ($connection['driver'] ?? '') !== 'pgsql' || ! empty($connection['url'])
            || ($connection['database'] ?? '') !== 'abshir'
            || ($connection['search_path'] ?? '') !== 'public'
            || ! in_array($connection['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
            || ! ctype_digit((string) ($connection['port'] ?? ''))
            || ($connection['username'] ?? '') !== 'abshir_app') {
            throw new RuntimeException('Reset requires local/development, local PostgreSQL abshir/public as abshir_app; URL/test/production connections are refused.');
        }
        $database = $preview ? 'abshir_cleanup_preview' : 'abshir';
        $pdo = new PDO('pgsql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$database,
            $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertIdentity($pdo, $database);

        return $pdo;
    }

    public static function assertIdentity(PDO $pdo, string $database): void
    {
        if (! in_array($database, ['abshir', 'abshir_cleanup_preview'], true)) {
            throw new RuntimeException('Unsupported reset target.');
        }
        $identity = $pdo->query('SELECT current_database() AS database, current_user AS role,
            pg_get_userbyid(datdba) AS owner, host(inet_server_addr()) AS address
            FROM pg_database WHERE datname=current_database()')->fetch(PDO::FETCH_ASSOC);
        if ($identity['database'] !== $database || $identity['role'] !== 'abshir_app'
            || $identity['owner'] !== 'abshir_app' || ! in_array($identity['address'], ['127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Actual PostgreSQL database/owner/server identity does not match the approved development target.');
        }
    }

    /** Start and lock before exporting the backup snapshot. Caller owns commit/rollback. */
    public function prepare(PDO $pdo, string $database): array
    {
        $this->begin($pdo, $database);

        return $this->plan();
    }

    public function begin(PDO $pdo, string $database): void
    {
        if (! app()->environment('local', 'development') || ! app()->runningInConsole()) {
            throw new RuntimeException('Order reset maintenance is development CLI only.');
        }
        self::assertIdentity($pdo, $database);
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Reset requires its own transaction.');
        }
        $this->pdo = $pdo;
        $this->database = $database;
        $pdo->beginTransaction();
        // A repeatable snapshot established during catalog discovery could miss a writer
        // that commits before its table is locked. READ COMMITTED sees that writer after
        // all locks are acquired; those locks then freeze the public backup/reset data.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->exec("SET LOCAL search_path=public,pg_catalog; SET LOCAL lock_timeout='".(int) config('demo_order_reset.lock_timeout_seconds', 15)."s'; SET LOCAL statement_timeout='120s'");
        if (! $pdo->query('SELECT pg_try_advisory_xact_lock(17891324, 9)')->fetchColumn()) {
            throw new RuntimeException('Another Order reset is running.');
        }
        $this->tables = $this->rows("SELECT n.nspname AS schema,c.relname AS name,c.oid::text AS oid
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE c.relkind='r' AND n.nspname NOT IN ('pg_catalog','information_schema')
            AND n.nspname NOT LIKE 'pg_%' ORDER BY n.nspname,c.relname");
        $public = array_filter($this->tables, fn ($t) => $t['schema'] === 'public');
        // Block INSERT/UPDATE/DELETE in public while allowing pg_dump's ACCESS SHARE locks.
        // Source tables precede ledger tables, matching production source -> ledger ordering.
        usort($public, fn ($a, $b) => [str_starts_with($a['name'], 'financial_') ? 1 : 0, $a['name']]
            <=> [str_starts_with($b['name'], 'financial_') ? 1 : 0, $b['name']]);
        foreach ($public as $table) {
            $pdo->exec('LOCK TABLE '.$this->table($table).' IN SHARE ROW EXCLUSIVE MODE');
        }
        $this->guards = $this->guardCatalog();
        $this->foreignKeys = $this->rows("SELECT nc.nspname AS child_schema,tc.relname AS child,
            np.nspname AS parent_schema,tp.relname AS parent,c.convalidated,
            (SELECT json_agg(a.attname ORDER BY k.n) FROM unnest(c.conkey) WITH ORDINALITY k(num,n)
                JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.num) AS child_columns,
            (SELECT json_agg(a.attname ORDER BY k.n) FROM unnest(c.confkey) WITH ORDINALITY k(num,n)
                JOIN pg_attribute a ON a.attrelid=c.confrelid AND a.attnum=k.num) AS parent_columns
            FROM pg_constraint c JOIN pg_class tc ON tc.oid=c.conrelid JOIN pg_namespace nc ON nc.oid=tc.relnamespace
            JOIN pg_class tp ON tp.oid=c.confrelid JOIN pg_namespace np ON np.oid=tp.relnamespace
            WHERE c.contype='f' AND (nc.nspname='public' OR np.nspname='public') ORDER BY c.oid");
        foreach ($this->foreignKeys as &$fk) {
            $fk['child_columns'] = json_decode($fk['child_columns'], true, flags: JSON_THROW_ON_ERROR);
            $fk['parent_columns'] = json_decode($fk['parent_columns'], true, flags: JSON_THROW_ON_ERROR);
            if ($fk['child_schema'] !== 'public' || $fk['parent_schema'] !== 'public' || ! $fk['convalidated']) {
                throw new RuntimeException('Cross-schema or unvalidated public foreign key requires manual review.');
            }
        }
        unset($fk);
        $this->validateForeignKeys();
    }

    public function plan(): array
    {
        if (! $this->pdo->inTransaction() || isset($this->manifest)) {
            throw new RuntimeException('Reset plan requires a fresh locked maintenance transaction.');
        }
        $pdo = $this->pdo;
        $this->manifest = 'abshir_reset_'.bin2hex(random_bytes(12));
        $pdo->exec('CREATE TEMP TABLE '.$this->quote($this->manifest).' (table_name text NOT NULL, row_id bigint NOT NULL,
            row_data jsonb NOT NULL, PRIMARY KEY(table_name,row_id)) ON COMMIT DROP');
        $this->addRows('orders', 'true');
        $this->expandDependencies();
        $this->classifyLedger();
        $this->expandDependencies();
        $this->classifyPayouts();
        $this->expandDependencies();
        $this->classifyLedger();
        $this->expandDependencies();
        $this->validateExclusiveLinks();
        // Freeze fingerprints of the exact rows that must survive, including independent ledger rows.
        $this->before = $this->fingerprints();
        $this->balancesBefore = $this->balances();
        $this->expectedBalances = $this->balances(true);

        return $this->counts();
    }

    public function execute(): array
    {
        self::assertIdentity($this->pdo, $this->database);
        if (! $this->pdo->inTransaction()) {
            throw new RuntimeException('No active reset transaction.');
        }
        $counts = $this->counts();
        $definitions = $this->installDeleteMaintenance();
        $deleted = array_fill_keys(array_keys($counts), 0);
        do {
            $progress = 0;
            foreach ($counts as $table => $count) {
                if ($deleted[$table] === $count) {
                    continue;
                }
                $conditions = ['EXISTS (SELECT 1 FROM pg_temp.'.$this->quote($this->manifest).
                    ' m WHERE m.table_name='.$this->literal($table).' AND m.row_id=d.id AND m.row_data=to_jsonb(d))'];
                // Every actual FK stays enabled. Delete children first, including self references.
                foreach ($this->foreignKeys as $fk) {
                    if ($fk['parent'] === $table) {
                        $conditions[] = 'NOT EXISTS (SELECT 1 FROM public.'.$this->quote($fk['child']).
                            ' c WHERE '.$this->join($fk, 'c', 'd').')';
                    }
                }
                $n = $this->pdo->exec('DELETE FROM public.'.$this->quote($table).' d WHERE '.implode(' AND ', $conditions));
                $deleted[$table] += $n;
                $progress += $n;
            }
        } while ($progress > 0);
        if ($deleted !== $counts) {
            throw new RuntimeException('A cyclic/shared dependency prevented complete cleanup; the entire reset must roll back.');
        }
        // Fire queued constraint DELETE guards while the exact approved-row context still exists.
        $this->pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
        foreach ($definitions as $definition) {
            $this->pdo->exec($definition);
        }
        if ($this->guardCatalog() !== $this->guards) {
            throw new RuntimeException('Trigger/function protections were not restored exactly.');
        }
        $this->validateForeignKeys();
        $after = $this->fingerprints(true);
        $preserved = $this->fingerprints(false, true);
        // Compare every remaining row against the exact pre-reset set, not just master counts.
        if ($after !== $preserved || $this->pdo->query('SELECT count(*) FROM public.orders')->fetchColumn() != 0) {
            throw new RuntimeException('Preserved data fingerprints or zero-Order verification failed.');
        }
        $balancesAfter = $this->balances();
        if ($balancesAfter !== $this->expectedBalances) {
            throw new RuntimeException('Financial balance reconciliation failed.');
        }

        return ['database' => $this->database, 'deleted' => $deleted, 'orders_after' => 0,
            'balances_before' => $this->balancesBefore, 'balances_after' => $balancesAfter,
            'preserved_table_count' => count($after), 'preserved_rows_verified' => true,
            'guards_restored' => true, 'foreign_keys_verified' => count($this->foreignKeys),
            'attachments_deleted' => 0];
    }

    private function addRows(string $table, string $condition): int
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new RuntimeException('Unexpected Order dependency '.$table.' requires explicit maintenance review.');
        }
        $primary = $this->pdo->query("SELECT array_agg(a.attname ORDER BY k.n)::text FROM pg_constraint c
            CROSS JOIN LATERAL unnest(c.conkey) WITH ORDINALITY k(num,n)
            JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.num
            WHERE c.contype='p' AND c.conrelid=".$this->literal('public.'.$table).'::regclass')->fetchColumn();
        if ($primary !== '{id}') {
            throw new RuntimeException('Unsupported primary key on '.$table.'.');
        }

        return $this->pdo->exec('INSERT INTO pg_temp.'.$this->quote($this->manifest).
            ' SELECT '.$this->literal($table).',d.id,to_jsonb(d) FROM public.'.$this->quote($table).
            ' d WHERE '.$condition.' ON CONFLICT DO NOTHING');
    }

    private function expandDependencies(): void
    {
        do {
            $n = 0;
            foreach ($this->foreignKeys as $fk) {
                $condition = 'EXISTS (SELECT 1 FROM public.'.$this->quote($fk['parent']).' p JOIN pg_temp.'.
                    $this->quote($this->manifest).' m ON m.table_name='.$this->literal($fk['parent']).
                    ' AND m.row_id=p.id WHERE '.$this->join($fk, 'd', 'p').')';
                // Do not assume every master has an id; only parents already in the manifest matter.
                if (! $this->pdo->query('SELECT EXISTS(SELECT 1 FROM pg_temp.'.$this->quote($this->manifest).
                    ' WHERE table_name='.$this->literal($fk['parent']).')')->fetchColumn()) {
                    continue;
                }
                if ($this->pdo->query('SELECT EXISTS(SELECT 1 FROM public.'.$this->quote($fk['child']).' d WHERE '.$condition.')')->fetchColumn()) {
                    $n += $this->addRows($fk['child'], $condition);
                }
            }
        } while ($n > 0);
    }

    private function classifyLedger(): void
    {
        // Canonical sources, never free-text notes or aggregate balance heuristics.
        $sources = ['CUSTOMER_INVOICE' => 'invoices', 'INVOICE_COLLECTION' => 'invoice_collections',
            'ORDER_BILL_PAYMENT' => 'order_bill_payments',
            'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER' => 'order_bill_payment_principal_handovers',
            'INVOICE_FINANCIAL_CORRECTION' => 'invoice_financial_corrections',
            'INVOICE_CORRECTION_RECEIVABLE_DISPOSITION' => 'invoice_correction_receivable_dispositions',
            'CAPTAIN_RECEIVABLE_PAYOUT' => 'captain_receivable_payouts'];
        $independent = ['CAPTAIN_DEPOSIT_RECEIPT' => 'captain_deposit_receipts',
            'CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT' => 'captain_deposit_manual_adjustments'];
        foreach ($this->rows('SELECT id,transaction_kind,source_type,source_id,reversal_of_transaction_id
            FROM public.financial_transactions ORDER BY id') as $t) {
            $validate = $this->pdo->prepare('SELECT validate_order_financial_event_source(?)');
            $validate->execute([$t['id']]);
            if ($t['transaction_kind'] === 'REVERSAL') {
                if ($t['reversal_of_transaction_id'] === null) {
                    throw new RuntimeException('Unattributed financial reversal requires manual review.');
                }

                continue;
            }
            $table = $sources[$t['source_type']] ?? $independent[$t['source_type']] ?? null;
            if ($table === null || ! preg_match('/^[1-9][0-9]{0,18}$/D', $t['source_id'])) {
                throw new RuntimeException('Unsupported financial source on transaction '.$t['id'].'; no guessed attribution is permitted.');
            }
            $source = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM public.'.$this->quote($table).' WHERE id::text=?)');
            $source->execute([$t['source_id']]);
            if (! $source->fetchColumn()) {
                throw new RuntimeException('Missing canonical source on transaction '.$t['id'].'.');
            }
            if (isset($sources[$t['source_type']])) {
                $this->addRows('financial_transactions', 'd.id='.(int) $t['id'].' AND EXISTS(SELECT 1 FROM pg_temp.'.
                    $this->quote($this->manifest).' m WHERE m.table_name='.$this->literal($table).
                    ' AND m.row_id::text='.$this->literal($t['source_id']).')');
            }
        }
        // Transitive reversal chains are exclusively linked by the authoritative FK.
        $this->expandDependencies();
        foreach ($this->rows("SELECT t.id FROM public.financial_transactions t
            WHERE t.transaction_kind='REVERSAL' AND NOT EXISTS
            (SELECT 1 FROM public.financial_transactions original WHERE original.id=t.reversal_of_transaction_id)") as $t) {
            throw new RuntimeException('Dangling financial reversal '.$t['id'].'.');
        }
    }

    private function classifyPayouts(): void
    {
        // A physical payout may be removed only if ALL its allocations are targeted.
        foreach ($this->rows('SELECT p.id,count(a.id) AS total,count(m.row_id) AS targeted
            FROM public.captain_receivable_payouts p
            LEFT JOIN public.captain_receivable_payout_allocations a ON a.captain_receivable_payout_id=p.id
            LEFT JOIN pg_temp.'.$this->quote($this->manifest)." m ON m.table_name='captain_receivable_payout_allocations' AND m.row_id=a.id
            GROUP BY p.id ORDER BY p.id") as $p) {
            if ((int) $p['targeted'] === 0) {
                continue;
            }
            if ($p['targeted'] !== $p['total']) {
                throw new RuntimeException('Mixed independent/Order payout '.$p['id'].' requires manual review.');
            }
            $this->addRows('captain_receivable_payouts', 'd.id='.(int) $p['id']);
        }
    }

    private function validateExclusiveLinks(): void
    {
        // If one authoritative row points at both a targeted and an independent operational
        // source, deleting it would falsify the independent history. Refuse rather than sever.
        foreach ($this->foreignKeys as $fk) {
            if (! in_array($fk['parent'], self::TABLES, true)) {
                continue;
            }
            $sql = 'SELECT EXISTS(SELECT 1 FROM public.'.$this->quote($fk['child']).' c JOIN pg_temp.'.
                $this->quote($this->manifest).' cm ON cm.table_name='.$this->literal($fk['child']).
                ' AND cm.row_id=c.id JOIN public.'.$this->quote($fk['parent']).' p ON '.$this->join($fk, 'c', 'p').
                ' WHERE NOT EXISTS(SELECT 1 FROM pg_temp.'.$this->quote($this->manifest).
                ' pm WHERE pm.table_name='.$this->literal($fk['parent']).' AND pm.row_id=p.id))';
            if ($this->pdo->query($sql)->fetchColumn()) {
                throw new RuntimeException('Shared financial/operational reference '.$fk['child'].' -> '.$fk['parent'].' requires manual review.');
            }
        }
    }

    private function installDeleteMaintenance(): array
    {
        $pid = (int) $this->pdo->query('SELECT pg_backend_pid()')->fetchColumn();
        $txid = $this->pdo->query('SELECT pg_current_xact_id()::text')->fetchColumn();
        $functions = $this->rows("SELECT DISTINCT p.oid,pg_get_functiondef(p.oid) AS definition,l.lanname,
            pg_get_userbyid(p.proowner) AS owner,t.tgtype
            FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_proc p ON p.oid=t.tgfoid JOIN pg_language l ON l.oid=p.prolang
            WHERE NOT t.tgisinternal AND n.nspname='public' AND (t.tgtype & 8)<>0
            AND EXISTS(SELECT 1 FROM pg_temp.".$this->quote($this->manifest).' m WHERE m.table_name=c.relname)');
        $definitions = [];
        foreach ($functions as $f) {
            if ($f['lanname'] !== 'plpgsql' || $f['owner'] !== 'abshir_app' || ((int) $f['tgtype'] & 1) === 0) {
                throw new RuntimeException('Maintenance needs owner-controlled row-level PL/pgSQL DELETE guards. Administrator review required.');
            }
            if (isset($definitions[$f['oid']])) {
                continue;
            }
            $definition = $f['definition'];
            $gate = "\n    -- Transaction-local, exact-row development maintenance; original guard follows.\n".
                "    IF TG_OP='DELETE' AND TG_TABLE_SCHEMA='public'\n".
                '        AND current_database()='.$this->literal($this->database)." AND pg_backend_pid()={$pid}\n".
                '        AND pg_current_xact_id()::text='.$this->literal($txid)." THEN\n".
                '        IF EXISTS(SELECT 1 FROM pg_temp.'.$this->quote($this->manifest).
                " m WHERE m.table_name=TG_TABLE_NAME AND m.row_data=to_jsonb(OLD)) THEN\n".
                "            RETURN OLD;\n        END IF;\n    END IF;\n";
            $replacement = preg_replace('/(^BEGIN[ \t]*\r?$)/m', '$1'.$gate, $definition, 1, $changed);
            if ($changed !== 1) {
                throw new RuntimeException('Unrecognized DELETE guard structure; no global bypass is permitted.');
            }
            $this->pdo->exec($replacement);
            $definitions[$f['oid']] = $definition;
        }

        return $definitions;
    }

    private function guardCatalog(): array
    {
        return $this->rows("SELECT t.oid,t.tgname,t.tgenabled,pg_get_triggerdef(t.oid) AS trigger,
            pg_get_functiondef(p.oid) AS function,p.proowner,p.proacl,p.proconfig
            FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_proc p ON p.oid=t.tgfoid WHERE NOT t.tgisinternal AND n.nspname='public' ORDER BY t.oid");
    }

    private function fingerprints(bool $actualRemaining = false, bool $expectedRemaining = false): array
    {
        if ($expectedRemaining) {
            return $this->before;
        }
        $result = [];
        foreach ($this->tables as $table) {
            $filter = '';
            if (! $actualRemaining && isset($this->manifest) && $table['schema'] === 'public'
                && in_array($table['name'], self::TABLES, true)) {
                $filter = ' WHERE NOT EXISTS(SELECT 1 FROM pg_temp.'.$this->quote($this->manifest).
                    ' m WHERE m.table_name='.$this->literal($table['name']).' AND m.row_id=r.id)';
            }
            $result[$table['schema'].'.'.$table['name']] = $this->pdo->query("SELECT count(*) AS count,
                encode(sha256(convert_to(COALESCE(string_agg(h,'' ORDER BY h),''),'UTF8')),'hex') AS sha256
                FROM (SELECT encode(sha256(convert_to(to_jsonb(r)::text,'UTF8')),'hex') AS h FROM ".
                $this->table($table).' r'.$filter.') hashes')->fetch(PDO::FETCH_ASSOC);
        }

        return $result;
    }

    private function balances(bool $excludingTargets = false): array
    {
        $filter = $excludingTargets ? ' AND NOT EXISTS(SELECT 1 FROM pg_temp.'.$this->quote($this->manifest).
            " m WHERE m.table_name='financial_entries' AND m.row_id=e.id)" : '';

        return $this->rows("SELECT a.id,a.account_kind,a.currency_id,t.branch_id,
            COALESCE(SUM(CASE WHEN e.direction='INCREASE' THEN e.amount ELSE -e.amount END),0)::text AS balance
            FROM public.financial_accounts a LEFT JOIN public.financial_entries e ON e.financial_account_id=a.id".$filter.
            ' LEFT JOIN public.financial_transactions t ON t.id=e.financial_transaction_id GROUP BY a.id,t.branch_id ORDER BY a.id,t.branch_id NULLS FIRST');
    }

    private function validateForeignKeys(): void
    {
        foreach ($this->foreignKeys as $fk) {
            $nonnull = implode(' AND ', array_map(fn ($col) => 'c.'.$this->quote($col).' IS NOT NULL', $fk['child_columns']));
            if ($this->pdo->query('SELECT EXISTS(SELECT 1 FROM public.'.$this->quote($fk['child']).
                ' c WHERE '.$nonnull.' AND NOT EXISTS(SELECT 1 FROM public.'.$this->quote($fk['parent']).
                ' p WHERE '.$this->join($fk, 'c', 'p').'))')->fetchColumn()) {
                throw new RuntimeException('Dangling foreign key '.$fk['child'].' -> '.$fk['parent'].'.');
            }
        }
    }

    private function counts(): array
    {
        $counts = [];
        foreach ($this->rows('SELECT table_name,count(*) AS count FROM pg_temp.'.$this->quote($this->manifest).' GROUP BY table_name ORDER BY table_name') as $row) {
            $counts[$row['table_name']] = (int) $row['count'];
        }

        return $counts;
    }

    private function rows(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function join(array $fk, string $child, string $parent): string
    {
        $parts = [];
        foreach ($fk['child_columns'] as $i => $col) {
            $parts[] = $child.'.'.$this->quote($col).'='.$parent.'.'.$this->quote($fk['parent_columns'][$i]);
        }

        return implode(' AND ', $parts);
    }

    private function table(array $table): string
    {
        return $this->quote($table['schema']).'.'.$this->quote($table['name']);
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function literal(string $value): string
    {
        return $this->pdo->quote($value);
    }
}
