<?php

namespace App\Support\DemoOrderReset;

use PDO;
use RuntimeException;

/** SELECT-only assessment. This class has no maintenance or deletion capability. */
final class ResetInventory
{
    public const FULL_BLOCKER = 'Full reset BLOCKED: deleting independent financial sources/history requires a new immutable-history exception. Phase C requires stopping here. No deletion, backup, or guard modification was attempted. Review a clean go-live database with selectively preserved configuration instead.';

    private const FULL_CANDIDATES = [
        'customers', 'customer_phones', 'customer_addresses',
        'shops', 'shop_products', 'shop_partnerships',
        'products', 'product_images', 'product_price_history', 'product_group_members',
        'captain_deposit_receipts', 'captain_deposit_manual_adjustments',
    ];

    public function inspect(PDO $pdo, bool $full): array
    {
        $ownsTransaction = ! $pdo->inTransaction();
        if (! $ownsTransaction && $pdo->query('SHOW transaction_read_only')->fetchColumn() !== 'on') {
            throw new RuntimeException('Dry-run cannot join a writable transaction.');
        }
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($ownsTransaction) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $identity = $pdo->query("SELECT current_database() AS database, current_schema() AS schema,
                current_setting('transaction_read_only') AS read_only")->fetch(PDO::FETCH_ASSOC);
            if ($identity['database'] === 'abshir_testing') {
                if (! app()->environment('testing') || ! preg_match('/^mc_testing_[a-f0-9]{16}$/D', $identity['schema'])) {
                    throw new RuntimeException('Inspection tests require the isolated PostgreSQL runner schema.');
                }
            } else {
                OrderResetMaintenance::assertIdentity($pdo, $full ? 'abshir' : $identity['database']);
                if (! app()->environment('local', 'development') || $identity['schema'] !== 'public') {
                    throw new RuntimeException('Inventory requires local/development public schema.');
                }
            }
            $schema = $this->quote($identity['schema']);
            $statement = $pdo->prepare('SELECT tablename FROM pg_tables WHERE schemaname = ? ORDER BY tablename');
            $statement->execute([$identity['schema']]);
            $tables = $statement->fetchAll(PDO::FETCH_COLUMN);
            $candidates = [];
            $preserved = [];
            $scope = $full ? array_merge(OrderResetMaintenance::orderTables(), self::FULL_CANDIDATES) : OrderResetMaintenance::orderTables();
            foreach ($tables as $table) {
                $count = (int) $pdo->query('SELECT count(*) FROM '.$schema.'.'.$this->quote($table))->fetchColumn();
                if (in_array($table, $scope, true)) {
                    $candidates[$table] = $count;
                } else {
                    $preserved[$table] = $count;
                }
            }
            $balances = $pdo->query('SELECT a.id AS account_id,a.account_kind,a.currency_id,t.branch_id,
                COALESCE(SUM(CASE WHEN e.direction=\'INCREASE\' THEN e.amount ELSE -e.amount END),0)::text AS balance
                FROM '.$schema.'.financial_accounts a LEFT JOIN '.$schema.'.financial_entries e ON e.financial_account_id=a.id
                LEFT JOIN '.$schema.'.financial_transactions t ON t.id=e.financial_transaction_id
                GROUP BY a.id,t.branch_id ORDER BY a.id,t.branch_id NULLS FIRST')->fetchAll(PDO::FETCH_ASSOC);
            $events = $pdo->query('SELECT source_type,event_type,count(*) AS count FROM '.$schema.'.financial_transactions
                GROUP BY source_type,event_type ORDER BY source_type,event_type')->fetchAll(PDO::FETCH_ASSOC);
            $dependencies = $this->incomingDependencies($pdo, $identity['schema'], $scope);

            return [
                'identity' => $identity,
                'environment' => app()->environment(),
                'mode' => $full ? 'FULL_ASSESSMENT_ONLY' : 'ORDER_INVENTORY_ONLY',
                'executed' => false,
                'candidate_table_counts' => $candidates,
                'count_semantics' => 'Inventory totals, NOT an approved deletion manifest. Order-only inventory includes ledger totals; independent ledger rows remain protected.',
                'preserved_table_counts' => $preserved,
                'financial_events' => $events,
                'balances_before' => $balances,
                'incoming_preserved_dependencies' => $dependencies,
                'blockers' => $full ? [self::FULL_BLOCKER] : [],
                'attachments' => 'Retained; no filesystem operations.',
                'configuration' => 'Users, Captains, Branches, Areas, roles, permissions, currencies, units, categories, group definitions, settings, fee rules and migrations are preserved.',
            ];
        } finally {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
        }
    }

    private function incomingDependencies(PDO $pdo, string $schema, array $scope): array
    {
        $query = $pdo->prepare("SELECT tc.relname AS child,tp.relname AS parent,c.conname,c.convalidated,
            (SELECT json_agg(a.attname ORDER BY k.n) FROM unnest(c.conkey) WITH ORDINALITY k(num,n)
                JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.num) AS child_columns,
            (SELECT json_agg(a.attname ORDER BY k.n) FROM unnest(c.confkey) WITH ORDINALITY k(num,n)
                JOIN pg_attribute a ON a.attrelid=c.confrelid AND a.attnum=k.num) AS parent_columns
            FROM pg_constraint c JOIN pg_class tc ON tc.oid=c.conrelid JOIN pg_namespace nc ON nc.oid=tc.relnamespace
            JOIN pg_class tp ON tp.oid=c.confrelid JOIN pg_namespace np ON np.oid=tp.relnamespace
            WHERE c.contype='f' AND nc.nspname=? AND np.nspname=? ORDER BY c.conname");
        $query->execute([$schema, $schema]);
        $result = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $fk) {
            if (! in_array($fk['parent'], $scope, true) || in_array($fk['child'], $scope, true)) {
                continue;
            }
            $columns = json_decode($fk['child_columns'], true, flags: JSON_THROW_ON_ERROR);
            $parents = json_decode($fk['parent_columns'], true, flags: JSON_THROW_ON_ERROR);
            $joins = [];
            foreach ($columns as $i => $column) {
                $joins[] = 'c.'.$this->quote($column).'=p.'.$this->quote($parents[$i]);
            }
            $count = (int) $pdo->query('SELECT count(*) FROM '.$this->quote($schema).'.'.$this->quote($fk['child']).
                ' c JOIN '.$this->quote($schema).'.'.$this->quote($fk['parent']).' p ON '.implode(' AND ', $joins))->fetchColumn();
            if ($count > 0 || ! $fk['convalidated']) {
                $result[] = ['child' => $fk['child'], 'parent' => $fk['parent'], 'referencing_rows' => $count, 'constraint' => $fk['conname']];
            }
        }

        return $result;
    }

    private function quote(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
