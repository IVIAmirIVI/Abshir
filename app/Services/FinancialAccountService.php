<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialOwnerType;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialOwner;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class FinancialAccountService
{
    public function ownerForCaptain(Captain $captain): FinancialOwner
    {
        return $this->getOrCreateOwner(FinancialOwnerType::Captain, 'captain_id', $captain);
    }

    public function ownerForShop(Shop $shop): FinancialOwner
    {
        return $this->getOrCreateOwner(FinancialOwnerType::Shop, 'shop_id', $shop);
    }

    public function ownerForBranch(Branch $branch): FinancialOwner
    {
        return $this->getOrCreateOwner(FinancialOwnerType::Branch, 'branch_id', $branch);
    }

    public function companyOwner(): FinancialOwner
    {
        return DB::transaction(function (): FinancialOwner {
            DB::table('financial_owners')->insertOrIgnore([
                'owner_type' => FinancialOwnerType::Company->value,
                'created_at' => now(),
            ]);

            return FinancialOwner::query()
                ->where('owner_type', FinancialOwnerType::Company->value)
                ->lockForUpdate()
                ->sole();
        });
    }

    public function getOrCreate(FinancialOwner $owner, Currency $currency, FinancialAccountKind $kind): FinancialAccount
    {
        return DB::transaction(function () use ($owner, $currency, $kind): FinancialAccount {
            $owner = FinancialOwner::query()->lockForUpdate()->findOrFail($owner->id);
            $currency = Currency::query()->findOrFail($currency->id);
            $this->validateCurrency($currency);
            if ($owner->owner_type !== FinancialOwnerType::Captain) {
                throw new FinancialLedgerException("{$kind->value} requires a CAPTAIN financial owner.");
            }

            DB::table('financial_accounts')->insertOrIgnore([
                'financial_owner_id' => $owner->id,
                'currency_id' => $currency->id,
                'account_kind' => $kind->value,
                'created_at' => now(),
            ]);

            return FinancialAccount::query()
                ->where('financial_owner_id', $owner->id)
                ->where('currency_id', $currency->id)
                ->where('account_kind', $kind->value)
                ->lockForUpdate()
                ->sole();
        }, 3);
    }

    private function getOrCreateOwner(FinancialOwnerType $type, string $foreignKey, Model $entity): FinancialOwner
    {
        return DB::transaction(function () use ($type, $foreignKey, $entity): FinancialOwner {
            DB::table('financial_owners')->insertOrIgnore([
                'owner_type' => $type->value,
                $foreignKey => $entity->getKey(),
                'created_at' => now(),
            ]);

            return FinancialOwner::query()
                ->where($foreignKey, $entity->getKey())
                ->lockForUpdate()
                ->sole();
        }, 3);
    }

    private function validateCurrency(Currency $currency): void
    {
        if (! $currency->exists || $currency->decimal_places < 0 || $currency->decimal_places > 4) {
            throw new FinancialLedgerException('Currency decimal precision must be between 0 and 4.');
        }
    }
}
