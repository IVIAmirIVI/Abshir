<?php

return [
    'title' => 'Special products', 'product' => 'Special product', 'policy' => 'Delivery fee allocation',
    'fee' => 'Base delivery fee per product unit', 'mode' => 'Allocation mode',
    'modes' => ['DEFAULT' => 'Standard allocation', 'PERCENTAGE' => 'Special percentage', 'FIXED' => 'Fixed amounts'],
    'captain_percentage' => 'Special Captain percentage (%)', 'captain_fixed' => 'Captain share', 'company_fixed' => 'Company share',
    'help' => 'Special percentage and fixed amounts replace standard and additional percentages on the base fee. Only the extra fee uses each Captain’s effective standard share. Product prices are unchanged.',
    'invalid_policy' => 'Complete the special delivery policy using an active currency.', 'fixed_sum' => 'Captain and company fixed shares must exactly equal the base delivery fee.',
    'precision' => 'The amount does not match the currency precision.',
    'currency_unavailable' => 'This special delivery policy is unavailable in the Branch currency. Administration must configure it.',
    'whole_portions' => 'Use a positive whole quantity in the special policy’s configured product unit. Other units and fractional quantities are not currently supported.',
    'multi_shop_blocked' => 'Special delivery across multiple Shops requires an approved policy for combining the special fee with MAX route pricing. This combination is currently unavailable.',
    'assign_portions' => 'Assign all special product units to participating Captains without duplication. The current flow requires equal base delivery fees per participant.',
    'portions' => 'Delivery portion assignments', 'units' => 'Units delivered by this Captain',
    'portion_help' => 'Each special product unit is an attributable delivery portion. Captain count alone does not multiply the fee. A sole Captain delivers the whole quantity.',
    'decrease_blocked' => 'Reducing a portion below its special base fee is unsupported until the reduction allocation policy is approved.',
    'base' => 'Special product base fee', 'extra' => 'Additional delivery fee', 'standard' => 'Standard percentage on ordinary portions and extra fees',
    'history' => 'Frozen final allocation', 'save_portions' => 'Save portions and reprice at the base fee',
    'stale' => 'Special products or delivery portions changed. Reassign portions and reprice delivery before issuing the invoice.',
    'attributes' => ['currency_id' => 'Currency', 'delivery_fee' => 'Base fee', 'allocation_mode' => 'Allocation mode', 'captain_percentage' => 'Captain percentage', 'captain_fixed' => 'Captain share', 'company_fixed' => 'Company share'],
];
