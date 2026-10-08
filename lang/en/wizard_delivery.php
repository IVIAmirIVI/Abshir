<?php

return [
    'enable' => 'Request a delivery fee exception',
    'permissions' => 'Applying an exception during creation also requires Captain assignment and pricing permissions.',
    'captain_help' => 'To apply the exception legally, the selected Captain will be assigned as the collection Captain when the Order is created. This does not advance the Order status automatically.',
    'requested' => 'Requested exception fee',
    'reason' => 'Exception reason',
    'captain' => 'Captain to assign on creation',
    'no_captains' => 'No Captain is currently eligible for this Branch. You can keep automatic pricing.',
    'final' => 'Effective fee on creation',
    'immediate' => 'The exception, reason and approval audit will be saved on creation.',
    'pending' => 'Approval is required. The approval request will be saved; the automatic fee remains effective until approval.',
    'automatic' => 'Restore automatic fee',
    'context_changed' => 'Delivery inputs or pricing settings changed. Review and confirm the exception again, or restore the automatic fee.',
    'reconfirm' => 'Confirm exception for current inputs',
    'review_changed' => 'Pricing or the exception changed since Review. Return to Delivery and review the fee before creating the Order.',
    'selected_customer' => 'Selected customer',
];
