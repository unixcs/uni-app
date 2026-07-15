# Backend Development Guidelines

> Repository-specific backend contracts for YoShop PHP services.

## Guidelines Index

| Guide | Description | Status |
|-------|-------------|--------|
| [Virtual Payment Contracts](./virtual-payment-contracts.md) | WeChat virtual payment notify/refund contracts, including iOS Apple refund inquiry handling | Active |
| [Runtime Ownership Contract](./runtime-ownership-contract.md) | WSL PHP-FPM/Timer runtime identity, cleanup and production-isolation contract | Active |

## Pre-Development Checklist

- Runtime、缓存、本地服务或清理脚本改动：先读 [Runtime Ownership Contract](./runtime-ownership-contract.md)。
- 虚拟支付回调、退款或状态改动：先读 [Virtual Payment Contracts](./virtual-payment-contracts.md)。

## Quality Check

Before marking backend virtual-payment work done:

1. Run `php -l` on every changed PHP file.
2. Verify callback response shapes against the upstream WeChat contract, not just local conventions.
3. Trace refund state from `payment_trade.payload_snapshot` through service finalization and order refund status.
4. Do not call developer-initiated refund APIs for platform flows where the upstream owner controls refunds.


Before marking local runtime/cleanup work done:

1. Run ShellCheck and `scripts/tests/test-local-runtime-contract.sh`.
2. Verify PHP-FPM and local Timer use `www-data` and runtime is writable by that identity.
3. Verify local helpers reject production-style runtime symlinks.
4. Smoke-test both loopback and the A-domain without using real credentials.
