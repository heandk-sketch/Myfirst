# Savings Advisor

Imports bank account CSV exports, analyses your spending and suggests where you can save money. Built on top of **Plugin Controller**; the page appears under **Plugin Controller → Savings Advisor** and is only available to administrators (`manage_options`).

## Setup

1. Activate **Plugin Controller** and **Savings Advisor**.
2. Open **Plugin Controller → Savings Advisor**.
3. Choose one or more CSV files and press **Importér og analysér**.

## File format

Semicolon separated, no header: `dd-mm-yyyy;text;amount;currency`, e.g. `01-10-2026;Dankort-køb CBB Mobil;-139,00;DKK`. Danish number format is used (`-1.563,00`). UTF-8 (with or without BOM) and Windows-1252 are accepted. Files are limited to 5 MB.

Importing the same file again never creates duplicates, so you can re-download overlapping periods.

## What you get

- Income, spending, monthly average and income minus spending for the latest 12 months.
- Spending per category, month by month, and the 10 biggest single purchases.
- Recurring payments (subscriptions, insurance, housing, ...).
- Saving suggestions with an estimated yearly saving: subscriptions, insurance, category tips, rising categories, price increases, many small purchases and fees.

The analysis is rule based and runs on your own server; no data is sent to any third party. Savings are rough estimates (a percentage per category), not guarantees.

## Transfers between your own accounts

Rows that match a transfer pattern are ignored so they are not counted twice. By default `Budget` (the transfer between the budget and expense accounts) is ignored. Add more with the `sva_transfer_patterns` filter:

```php
add_filter(
	'sva_transfer_patterns',
	static function ( array $patterns ): array {
		$patterns[] = '/^til opsparing$/iu';
		return $patterns;
	}
);
```

## Categories

Categories are matched by keywords in `SVA_Analyzer::categories()`. Transactions that match nothing end up in **Øvrigt**; extend the patterns there to improve the breakdown.

## Privacy

Transactions are stored in the `{prefix}sva_transactions` table. Use **Slet alle importerede data** on the page to remove them.
