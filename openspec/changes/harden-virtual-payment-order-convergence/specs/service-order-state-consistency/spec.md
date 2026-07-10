## ADDED Requirements

### Requirement: Service-order state projection SHALL prioritize refund semantics over ordinary service semantics
The system SHALL project service-order state text and status flags from a unified priority order so that refund-related states override ordinary service progress when both are present.

#### Scenario: Order has active refund in progress
- **WHEN** a service order has an active refund request that is under review or being processed
- **THEN** user-facing and backend-facing order state projection SHALL show a refund-in-progress meaning
- **AND** it SHALL NOT continue to present the same order as ordinary processing, pending contact, or in-service only

#### Scenario: Order refund has completed
- **WHEN** a service order refund reaches its completed terminal state
- **THEN** user-facing and backend-facing order state projection SHALL show refunded/refund-success semantics
- **AND** ordinary service action hints SHALL no longer be shown as if the order were still actively being served

### Requirement: Service-order action buttons SHALL remain consistent with the projected state
The system SHALL derive pay, refund, cancel, start-service, and complete-service actions from the same projected order and refund state instead of from separate ad-hoc checks.

#### Scenario: Paid order has not converged locally
- **WHEN** the local order is still unpaid because virtual-payment convergence has not completed
- **THEN** user-facing pages MAY still show pay-related recovery guidance
- **AND** they SHALL NOT simultaneously present the order as already paid elsewhere

#### Scenario: Order has active refund in progress
- **WHEN** a service order has an active refund in progress
- **THEN** user-facing refund and pay buttons SHALL be restricted according to the refund state
- **AND** backend start-service and complete-service actions SHALL be blocked while the refund conflict exists

### Requirement: Payment-adjacent external entry points SHALL accept out-trade-no compatibility mapping
The system SHALL support `out_trade_no -> order_id` resolution on payment-adjacent external entry points that may originate from WeChat or payment-side jumps.

#### Scenario: External payment entry reaches order detail with out_trade_no
- **WHEN** an external payment-related entry point provides a virtual-payment `out_trade_no` instead of an internal `order_id`
- **THEN** the backend SHALL resolve the related business order before loading the order detail
- **AND** the request SHALL succeed without requiring the caller to know the internal order id

#### Scenario: Internal ordinary order flows still use order_id
- **WHEN** internal mall pages and backend operations already hold the business order id
- **THEN** they SHALL continue to use `order_id` as the internal identity
- **AND** the compatibility mapping SHALL remain limited to payment-adjacent external entry points

### Requirement: Diagnostics and acceptance evidence SHALL reflect virtual-payment and service-refund reality
The system SHALL provide diagnostics and acceptance evidence that correctly recognize service refunds, virtual-payment trade attempts, and pending convergence states.

#### Scenario: Diagnostic script scans service orders with active refund
- **WHEN** a diagnostic or inspection script scans service orders
- **THEN** it SHALL identify active service refunds using the correct service-refund semantics
- **AND** it SHALL NOT silently reuse old physical-order refund assumptions that hide active service refunds

#### Scenario: Acceptance run records a convergence failure
- **WHEN** a payment or refund acceptance run does not converge as expected
- **THEN** the evidence bundle SHALL retain enough order, refund, trade, runtime-environment, and remote-query context to explain where the chain broke
