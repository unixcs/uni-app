# Backend Development Guidelines

> Repository-specific backend contracts for YoShop PHP services.

## Guidelines Index

| Guide | Description | Status |
|-------|-------------|--------|
| [Virtual Payment Contracts](./virtual-payment-contracts.md) | WeChat virtual payment notify/refund contracts, including iOS Apple refund inquiry handling | Active |

## Quality Check

Before marking backend virtual-payment work done:

1. Run `php -l` on every changed PHP file.
2. Verify callback response shapes against the upstream WeChat contract, not just local conventions.
3. Trace refund state from `payment_trade.payload_snapshot` through service finalization and order refund status.
4. Do not call developer-initiated refund APIs for platform flows where the upstream owner controls refunds.
