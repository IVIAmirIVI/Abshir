<?php

return [
    // Activation requires owner review and the V3 migration. Only preselected single-Captain creation uses V3; shared/unassigned creation retains V2.
    'enable_v3_single_captain' => env('ORDER_ACCOUNTING_V3_SINGLE_CAPTAIN', false),
];
