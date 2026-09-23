<?php

namespace App\Enums;

enum FinancialEventType: string
{
    case OrderProductReimbursement = 'ORDER_PRODUCT_REIMBURSEMENT';
    case OrderDeliveryEntitlement = 'ORDER_DELIVERY_ENTITLEMENT';
    case OrderCompanyDeliveryShare = 'ORDER_COMPANY_DELIVERY_SHARE';
    case InvoiceCashCustodyReceived = 'INVOICE_CASH_CUSTODY_RECEIVED';

    public static function orderDerived(): array
    {
        return [
            self::OrderProductReimbursement,
            self::OrderDeliveryEntitlement,
            self::OrderCompanyDeliveryShare,
        ];
    }
}
