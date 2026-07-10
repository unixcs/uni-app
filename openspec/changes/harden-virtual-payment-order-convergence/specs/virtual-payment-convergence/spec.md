## ADDED Requirements

### Requirement: Virtual-payment pay success SHALL converge through a unified backend truth path
The system SHALL treat `yoshop_order` as the business truth for pay success and SHALL only mark an order paid when backend convergence confirms the virtual payment has reached a paid terminal state.

#### Scenario: Query confirms paid terminal state
- **WHEN** the backend receives a virtual-payment query or notify result with a paid terminal status for a trade attempt
- **THEN** it SHALL invoke the unified order pay-success path exactly once
- **AND** it SHALL persist the winning trade attempt onto the order
- **AND** it SHALL move the service order into the paid business state

#### Scenario: Frontend success arrives before backend sees final paid state
- **WHEN** the frontend receives virtual-payment success but the immediate backend query still returns a non-terminal status such as created or paying
- **THEN** the system SHALL keep the order in an unpaid pending-convergence state
- **AND** it SHALL preserve the latest trade attempt evidence for later query, notify, or compensation
- **AND** it SHALL NOT falsely mark the order paid or finally failed from that first non-terminal query alone

### Requirement: Multiple virtual-payment attempts for one order SHALL converge to a single winning business result
The system SHALL allow repeated virtual-payment attempts for the same order without letting duplicate attempts create duplicate pay success or inconsistent local order state.

#### Scenario: Later attempt becomes the winning paid trade
- **WHEN** an order has multiple virtual-payment trade attempts
- **AND** one later attempt is the first attempt confirmed as paid
- **THEN** only that confirmed attempt SHALL drive local order pay success
- **AND** earlier unpaid attempts SHALL remain non-winning evidence only

#### Scenario: Duplicate notify or repeated paid query arrives for the same winning trade
- **WHEN** the backend receives duplicate virtual-payment pay confirmation for an already-converged order
- **THEN** it SHALL keep the order in the same paid business state
- **AND** it SHALL NOT duplicate fulfillment, duplicate refund, or duplicate order pay updates

### Requirement: Virtual-payment refunds SHALL converge through terminal refund evidence
The system SHALL distinguish between "refund task accepted" and "refund completed" and SHALL only finalize local refund completion after terminal refund evidence is observed.

#### Scenario: Refund task accepted but not yet completed
- **WHEN** the virtual-payment refund API accepts a refund request
- **THEN** the local refund record SHALL enter a refund-in-progress state
- **AND** the system SHALL NOT mark the refund completed until refund notify or refund query reaches a completed terminal state

#### Scenario: Refund terminal state is observed
- **WHEN** refund notify or refund query confirms the virtual-payment refund has reached a completed terminal state
- **THEN** the backend SHALL finalize the local refund record
- **AND** it SHALL update the order and trade records to the refunded business result
- **AND** it SHALL make subsequent refund convergence idempotent

### Requirement: Virtual-payment convergence SHALL have compensation coverage
The system SHALL provide retry or compensation paths for virtual-payment pay and refund convergence gaps that remain after the first foreground attempt.

#### Scenario: Immediate foreground pay query does not converge
- **WHEN** the first foreground pay query does not confirm a terminal result
- **THEN** the system SHALL leave enough stored trade evidence for later background query, notify, or operator audit
- **AND** the order SHALL remain recoverable by compensation instead of requiring manual data guessing

#### Scenario: Reviewed refund remains unclosed locally
- **WHEN** a reviewed virtual-payment refund stays in progress locally after the first refund request
- **THEN** a compensation path SHALL be able to re-query the remote refund state
- **AND** it SHALL finalize the refund automatically once terminal completion is observed
