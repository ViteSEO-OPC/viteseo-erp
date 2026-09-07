<?php

namespace App\Modules\Accounting\Enums;

/**
 * The five fundamental account types. Assets and expenses are debit-normal;
 * liabilities, equity and income are credit-normal.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function isDebitNormal(): bool
    {
        return in_array($this, [self::Asset, self::Expense], true);
    }
}
