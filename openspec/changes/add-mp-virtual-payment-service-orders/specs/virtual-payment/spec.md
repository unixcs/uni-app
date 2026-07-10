# Delta for Virtual Payment

## ADDED Requirements

### Requirement: Service goods SHALL support WeChat Mini Program virtual payment

The system SHALL allow eligible service goods to be purchased through WeChat Mini Program virtual payment 2.0.

#### Scenario: Launch virtual payment for a mapped service good

- GIVEN a service good is enabled for virtual payment
- AND the good is mapped to a valid WeChat virtual payment `productId`
- WHEN the user taps buy in the mini program and submits the order
- THEN the frontend SHALL call `wx.requestVirtualPayment`
- AND the request SHALL use server-generated `signData`, `paySig`, and `signature`

#### Scenario: Reject unmapped goods

- GIVEN a service good has virtual payment enabled
- AND the good has no valid virtual payment mapping
- WHEN the user tries to pay through the virtual payment path
- THEN the system SHALL reject the request
- AND the user SHALL receive a clear configuration error message

### Requirement: Virtual payment goods SHALL remain service orders in the mall domain

The system SHALL treat a virtual-payment purchase as a mall service order, not as a recharge order or an independent virtual-goods order type.

#### Scenario: Create a service order before virtual payment launch

- GIVEN a user is buying a service good
- WHEN the system prepares the virtual payment request
- THEN the mall SHALL create a normal pending-pay service order first
- AND the order SHALL continue to use the existing service-order state machine

#### Scenario: Preserve service-order identity after payment success

- GIVEN a virtual payment order is paid successfully
- WHEN the order is loaded from the API or backend
- THEN it SHALL be recognized as a service order
- AND it SHALL expose the existing service-order actions and refund rules

### Requirement: The platform-facing item SHALL be a fixed good-level payment mapping

The system SHALL maintain a distinct fixed mapping between a mall service good and a single WeChat virtual payment item identifier.

#### Scenario: Service good maps to one productId

- GIVEN an operator configures a service good for virtual payment
- WHEN the configuration is saved
- THEN the system SHALL store the mall good identity separately from the WeChat `productId`
- AND the WeChat `productId` SHALL only be used for the payment-side request
- AND the mapping SHALL be fixed at the goods level for the first release

#### Scenario: Quantity is fixed to one

- GIVEN a service good is enabled for virtual payment
- WHEN the payment request is generated
- THEN the system SHALL use `buyQuantity=1`

### Requirement: Virtual payment service goods SHALL use a fixed-price settlement model

The system SHALL enforce a fixed payable amount that stays aligned with the mapped WeChat virtual payment item.

#### Scenario: Price snapshot is enforced

- GIVEN a service good is configured with a virtual payment mapping
- WHEN the configuration is saved or the payment request is generated
- THEN the system SHALL verify the mall selling price matches the configured platform price snapshot
- AND the system SHALL send `goodsPrice`

#### Scenario: Mismatch or repricing input is rejected

- GIVEN a service good is enabled for virtual payment
- WHEN the order is settled
- THEN the system SHALL reject the payment if any repricing input exists
- AND the system SHALL reject the payment if the final payable amount does not equal the configured platform price snapshot

### Requirement: Virtual payment SHALL appear as WeChat pay in cashier UX but use an internal payment route

The system SHALL keep the cashier UI consistent for users while separating the implementation path from ordinary WeChat pay.

#### Scenario: Cashier shows WeChat pay label

- GIVEN a payable virtual-payment service order in the mini program
- WHEN the user opens the cashier
- THEN the cashier SHALL display the payment option with the existing WeChat pay name and icon

#### Scenario: Backend routes WeChat pay by order capability

- GIVEN the cashier submits payment for a virtual-payment service order
- WHEN the pay request is dispatched
- THEN the backend SHALL route it to a dedicated virtual-payment implementation
- AND it SHALL keep the external cashier payment option consistent with ordinary WeChat pay

### Requirement: Virtual payment integration SHALL be reliable and idempotent

The system SHALL not rely solely on frontend success callbacks and SHALL process repeated payment notifications safely.

#### Scenario: Frontend success callback is lost

- GIVEN the user completed payment in WeChat
- AND the frontend success callback is missing or interrupted
- WHEN the backend receives the official payment result or a successful query result
- THEN the order SHALL still be marked paid
- AND the user-visible order state SHALL eventually converge correctly

#### Scenario: Duplicate notification arrives

- GIVEN the same `outTradeNo` notification is pushed more than once
- WHEN the backend handles the repeated notifications
- THEN the service-order payment result SHALL only be applied once
- AND no duplicate business fulfillment or duplicate refund SHALL occur

### Requirement: Virtual payment refunds SHALL use WeChat virtual payment refund APIs

The system SHALL return money through WeChat virtual payment refund interfaces while preserving the existing mall refund decision rules.

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
