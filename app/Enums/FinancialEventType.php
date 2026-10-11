<?php

namespace App\Enums;

enum FinancialEventType: string
{
    case OrderV2CashDeliveryCustodyReceived = 'ORDER_V2_CASH_DELIVERY_CUSTODY_RECEIVED';
    case OrderV2ProductReimbursementDue = 'ORDER_V2_PRODUCT_REIMBURSEMENT_DUE';
    case OrderV2DeliveryEntitlementDue = 'ORDER_V2_DELIVERY_ENTITLEMENT_DUE';
    case SharedOrderProductReimbursementDue = 'SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE';
    case SharedOrderDeliveryEntitlementDue = 'SHARED_ORDER_DELIVERY_ENTITLEMENT_DUE';
    case SharedInvoiceCashCustodyReceived = 'SHARED_INVOICE_CASH_CUSTODY_RECEIVED';

    case OrderProductReimbursement = 'ORDER_PRODUCT_REIMBURSEMENT';
    case OrderDeliveryEntitlement = 'ORDER_DELIVERY_ENTITLEMENT';
    case OrderCompanyDeliveryShare = 'ORDER_COMPANY_DELIVERY_SHARE';
    case InvoiceCashCustodyReceived = 'INVOICE_CASH_CUSTODY_RECEIVED';
    case OrderCashCompanyDeliveryShareHeld = 'ORDER_CASH_COMPANY_DELIVERY_SHARE_HELD';
    case OrderCashCompanyDeliveryShareDepositDebit = 'ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT';
    case OrderCashDeliveryShortfallDue = 'ORDER_CASH_DELIVERY_SHORTFALL_DUE';
    case OrderShamCashProductReimbursementDue = 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE';
    case OrderShamCashDeliveryEntitlementDue = 'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE';
    case BillPaymentCashCollected = 'BILL_PAYMENT_CASH_COLLECTED';
    case BillPaymentPrincipalHandedOver = 'BILL_PAYMENT_PRINCIPAL_HANDED_OVER';
    case BillPaymentServiceFeeRetained = 'BILL_PAYMENT_SERVICE_FEE_RETAINED';
    case CaptainDepositReceived = 'CAPTAIN_DEPOSIT_RECEIVED';
    case CaptainDepositManualAdjustmentIncrease = 'CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT_INCREASE';
    case CaptainDepositManualAdjustmentDecrease = 'CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT_DECREASE';
    case CaptainReceivablePaid = 'CAPTAIN_RECEIVABLE_PAID';
    case InvoiceCorrectionReverseOriginal = 'INVOICE_CORRECTION_REVERSE_ORIGINAL';
    case InvoiceCorrectionCashDepositDebit = 'INVOICE_CORRECTION_CASH_DEPOSIT_DEBIT';
    case InvoiceCorrectionCashShortfallDue = 'INVOICE_CORRECTION_CASH_SHORTFALL_DUE';
    case InvoiceCorrectionShamProductDue = 'INVOICE_CORRECTION_SHAM_PRODUCT_DUE';
    case InvoiceCorrectionShamDeliveryDue = 'INVOICE_CORRECTION_SHAM_DELIVERY_DUE';
    case ReceivableUnpaidRemainderRetired = 'RECEIVABLE_UNPAID_REMAINDER_RETIRED';
    case ReceivableCorrectedResidualDue = 'RECEIVABLE_CORRECTED_RESIDUAL_DUE';

    public static function orderDerived(): array
    {
        return [
            self::OrderProductReimbursement,
            self::OrderDeliveryEntitlement,
            self::OrderCompanyDeliveryShare,
        ];
    }

    public static function accountingV2(): array
    {
        return [self::OrderV2CashDeliveryCustodyReceived, self::OrderV2ProductReimbursementDue, self::OrderV2DeliveryEntitlementDue];
    }

    public static function correctedOrderDerived(): array
    {
        return [
            self::OrderCashCompanyDeliveryShareHeld,
            self::OrderCashCompanyDeliveryShareDepositDebit,
            self::OrderCashDeliveryShortfallDue,
            self::OrderShamCashProductReimbursementDue,
            self::OrderShamCashDeliveryEntitlementDue,
        ];
    }
}
