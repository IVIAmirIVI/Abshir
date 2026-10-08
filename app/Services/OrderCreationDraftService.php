<?php

namespace App\Services;

use App\Enums\OrderItemType;
use App\Enums\OrderPricingClass;
use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerPhone;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Validates untrusted browser drafts without creating temporary Orders or financial rows. */
class OrderCreationDraftService
{
    public function contacts(User $actor, array $data): CustomerAddress
    {
        Gate::forUser($actor)->authorize('orders.create');
        $branch = Branch::query()->where('is_active', true)->findOrFail($data['branchId']);
        abort_unless($actor->canAccessBranch($branch), 403);
        Customer::query()->accessibleTo($actor)->where('is_active', true)->findOrFail($data['customerId']);
        if (! CustomerPhone::query()->where('customer_id', $data['customerId'])->whereKey($data['customerPhoneId'])->exists()) {
            throw ValidationException::withMessages(['customerPhoneId' => __('domain.the_selected_phone_does_not_belong_to_this_customer')]);
        }
        $address = CustomerAddress::query()->with('area')->where('customer_id', $data['customerId'])
            ->where('is_active', true)->whereHas('area', fn ($q) => $q->where('branch_id', $branch->id)->where('is_active', true))
            ->find($data['customerAddressId']);
        if (! $address) {
            throw ValidationException::withMessages(['customerAddressId' => __('domain.the_selected_address_is_outside_the_order_branch')]);
        }

        return $address;
    }

    public function groups(User $actor, string $branchId, array $groups): array
    {
        Gate::forUser($actor)->authorize('orders.update');
        $branch = Branch::query()->where('is_active', true)->findOrFail($branchId);
        abort_unless($actor->canAccessBranch($branch), 403);
        Validator::make(['groups' => $groups], ['groups' => ['required', 'array', 'min:1', 'max:20']])->validate();
        foreach ($groups as $key => $group) {
            $this->validateGroup($group, 'groups.'.$key);
        }
        $items = collect($groups)->pluck('items')->flatten(1);
        $units = Unit::query()->where('is_active', true)->whereIn('id', $items->pluck('unit_id'))->get()->keyBy('id');
        $productIds = Product::query()->where('is_active', true)->whereIn('id', $items->pluck('product_id')->filter())->pluck('id');
        $shops = Shop::query()->accessibleTo($actor)->where('is_active', true)->with('type', 'area')
            ->whereHas('area', fn ($q) => $q->where('branch_id', $branchId)->where('is_active', true))
            ->whereIn('id', collect($groups)->pluck('shop_id')->filter())->get()->keyBy('id');
        $origins = Area::query()->accessibleTo($actor)->where('branch_id', $branchId)->where('is_active', true)
            ->whereIn('id', collect($groups)->pluck('origin_area_id')->filter())->get()->keyBy('id');
        $result = [];
        $seen = [];
        foreach ($groups as $key => $group) {
            $prefix = 'groups.'.$key;
            $shop = null;
            if ($group['shop_type'] === OrderShopType::Registered->value) {
                $shop = $shops->get($group['shop_id']);
                if (! $shop) {
                    throw ValidationException::withMessages([$prefix => __('domain.the_shop_is_outside_the_order_branch')]);
                }
                if (isset($seen[$shop->id])) {
                    throw ValidationException::withMessages([$prefix => __('domain.the_registered_shop_is_already_on_this_order_add_products_to_its_existing_section')]);
                }
                $seen[$shop->id] = true;
                $classification = $shop->business_classification;
                if (! $classification || ($shop->business_classification && filled($group['business_classification'] ?? null) && $group['business_classification'] !== $classification->value)) {
                    throw ValidationException::withMessages([$prefix => __('orders.wizard.missing_master_classification')]);
                }
                $origin = $shop->area;
                $name = $shop->name;
            } else {
                $origin = $origins->get($group['origin_area_id']);
                if (! $origin) {
                    throw ValidationException::withMessages([$prefix => __('domain.select_the_one_time_shop_area')]);
                }
                $classification = ShopBusinessClassification::from($group['business_classification']);
                $name = $group['shop_name'];
            }
            foreach ($group['items'] as $index => $item) {
                $unit = $units->get($item['unit_id']);
                if (! $unit) {
                    throw ValidationException::withMessages([$prefix.'.items.'.$index.'.unit_id' => __('domain.an_inactive_unit_cannot_be_used_for_a_new_item')]);
                }
                if (! $unit->allows_decimal && ! preg_match('/^\d+(?:\.0{1,4})?$/', $item['quantity'])) {
                    throw ValidationException::withMessages([$prefix.'.items.'.$index.'.quantity' => __('domain.this_unit_requires_a_whole_number_quantity')]);
                }
                if ($item['item_type'] === OrderItemType::Catalog->value) {
                    if (! $productIds->contains($item['product_id'])) {
                        throw ValidationException::withMessages([$prefix.'.items.'.$index.'.product_id' => __('domain.an_inactive_product_cannot_be_used_for_a_new_item')]);
                    }
                }
            }
            $result[] = ['shop' => $shop, 'name' => $name, 'origin' => $origin, 'classification' => $classification, 'data' => $group];
        }

        return $result;
    }

    private function validateGroup(array $group, string $prefix): void
    {
        $rules = [
            'shop_type' => ['required', Rule::enum(OrderShopType::class)],
            'shop_id' => ['nullable', 'required_if:shop_type,REGISTERED', 'prohibited_if:shop_type,ONE_TIME', 'integer'],
            'shop_name' => ['nullable', 'required_if:shop_type,ONE_TIME', 'prohibited_if:shop_type,REGISTERED', 'string', 'max:255'],
            'shop_phone' => ['nullable', 'prohibited_if:shop_type,REGISTERED', 'string', 'max:50'],
            'shop_address' => ['nullable', 'prohibited_if:shop_type,REGISTERED', 'string', 'max:500'],
            'origin_area_id' => ['nullable', 'required_if:shop_type,ONE_TIME', 'prohibited_if:shop_type,REGISTERED', 'integer'],
            'business_classification' => ['nullable', 'required_if:shop_type,ONE_TIME', Rule::enum(ShopBusinessClassification::class)],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_type' => ['required', Rule::enum(OrderItemType::class)],
            'items.*.unit_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'items.*.product_id' => ['nullable', 'required_if:items.*.item_type,CATALOG_ITEM', 'prohibited_if:items.*.item_type,ONE_TIME_ITEM', 'integer'],
            'items.*.item_name' => ['nullable', 'required_if:items.*.item_type,ONE_TIME_ITEM', 'prohibited_if:items.*.item_type,CATALOG_ITEM', 'string', 'max:255'],
        ];
        $validator = Validator::make($group, $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn ($messages, $field) => [$prefix.'.'.$field => $messages])->all());
        }
    }

    public function preview(User $actor, string $branchId, CustomerAddress $address, array $groups): array
    {
        $resolved = $this->groups($actor, $branchId, $groups);
        $currency = Branch::query()->findOrFail($branchId)->defaultCurrency;
        if (! $currency || ! $currency->is_active) {
            throw ValidationException::withMessages(['delivery' => __('domain.the_order_branch_requires_a_default_currency')]);
        }
        $class = count($resolved) > 1 ? OrderPricingClass::MultiShop : OrderPricingClass::fromShopClassification($resolved[0]['classification']);
        $routes = collect($resolved)->map(fn ($row, $index) => ['origin' => $row['origin'], 'shop' => (object) ['id' => $index, 'shop_name_snapshot' => $row['name']]]);

        return app(OrderDeliveryPricingService::class)->previewRoutes($actor, $routes, $address->area, $currency, $class);
    }
}
