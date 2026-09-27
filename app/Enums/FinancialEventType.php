<?php

namespace App\Enums;

enum FinancialEventType: string
{
    case OrderProductReimbursement = 'ORDER_PRODUCT_REIMBURSEMENT';
    case OrderDeliveryEntitlement = 'ORDER_DELIVERY_ENTITLEMENT';
    case OrderCompanyDeliveryShare = 'ORDER_COMPANY_DELIVERY_SHARE';
    case InvoiceCashCustodyReceived = 'INVOICE_CASH_CUSTODY_RECEIVED';
    case OrderCashCompanyDeliveryShareHeld = 'ORDER_CASH_COMPANY_DELIVERY_SHARE_HELD';
    case OrderCashDeliveryShortfallDue = 'ORDER_CASH_DELIVERY_SHORTFALL_DUE';
    case OrderShamCashProductReimbursementDue = 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE';
    case OrderShamCashDeliveryEntitlementDue = 'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE';
    case BillPaymentCashCollected = 'BILL_PAYMENT_CASH_COLLECTED';
    case BillPaymentPrincipalHandedOver = 'BILL_PAYMENT_PRINCIPAL_HANDED_OVER';
    case BillPaymentServiceFeeRetained = 'BILL_PAYMENT_SERVICE_FEE_RETAINED';

    public static function orderDerived(): array
    {
        return [
            self::OrderProductReimbursement,
            self::OrderDeliveryEntitlement,
            self::OrderCompanyDeliveryShare,
        ];
    }

    public static function correctedOrderDerived(): array
    {
        return [
            self::OrderCashCompanyDeliveryShareHeld,
            self::OrderCashDeliveryShortfallDue,
            self::OrderShamCashProductReimbursementDue,
            self::OrderShamCashDeliveryEntitlementDue,
        ];
    }
}
