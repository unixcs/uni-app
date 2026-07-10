# Delta for Service Orders

## ADDED Requirements

### Requirement: Virtual-payment service orders SHALL reuse the existing service-order lifecycle

The system SHALL reuse the current service-order lifecycle for game services purchased through WeChat virtual payment.

#### Scenario: Paid but not yet accepted by merchant

- GIVEN a virtual-payment service order has been paid
- AND the merchant has not started service
- WHEN the user views the order
- THEN the order state SHALL be shown as `待联系`

#### Scenario: Merchant starts service

- GIVEN a paid virtual-payment service order is in `待联系`
- WHEN the merchant confirms or starts service in the backend
- THEN the order state SHALL become `服务中`

#### Scenario: Merchant completes service

- GIVEN a virtual-payment service order is in `服务中`
- WHEN the merchant completes the service in the backend
- THEN the order state SHALL become `已完成`

### Requirement: Refund rules for virtual-payment service orders SHALL match existing service-order rules

The system SHALL reuse the current service-order refund policy for virtual-payment service orders.

#### Scenario: Auto refund before service starts

- GIVEN a virtual-payment service order has been paid
- AND service has not started
- WHEN the user applies for a refund
- THEN the system SHALL execute the refund automatically
- AND the order SHALL move to a refunded or refund-completed state

#### Scenario: Merchant-reviewed refund during service

- GIVEN a virtual-payment service order is in `服务中`
- WHEN the user applies for a refund
- THEN the system SHALL create a refund request
- AND the refund SHALL require merchant review in the backend

#### Scenario: Refund forbidden after service completion

- GIVEN a virtual-payment service order is already `已完成`
- WHEN the user attempts to apply for a refund
- THEN the system SHALL reject the request
- AND the user SHALL receive a clear message that the current service stage does not allow refunds

### Requirement: Virtual-payment refunds SHALL use WeChat virtual payment refund APIs

The system SHALL execute money return through WeChat virtual payment refund interfaces while preserving the existing mall refund decision rules.

#### Scenario: Auto refund triggers WeChat refund task

- GIVEN a virtual-payment service order qualifies for auto refund
- WHEN the refund is executed
- THEN the backend SHALL call the WeChat virtual payment refund API
- AND it SHALL track refund completion through query or notification

#### Scenario: Merchant-approved refund triggers WeChat refund task

- GIVEN a virtual-payment service order refund request is approved by the merchant
- WHEN the refund task starts
- THEN the backend SHALL call the WeChat virtual payment refund API
- AND the mall refund record SHALL remain consistent with the final platform refund state
