# Service Order Contracts

> Executable contracts for service-order checkout, admin search, detail/export rendering, and historical hide flows that span miniapp, store, backend order models, and maintenance commands.

---

## Scenario: Service-order checkout contract on a shared checkout page

### 1. Scope / Trigger
- Trigger: service-order contact semantics changed from legacy service fields to a new cross-layer contract.
- Trigger: the same checkout page is shared by normal orders and service orders, so service-only validation must be gated instead of applied globally.
- Applies to:
  - `yoshop2.0/app/api/service/order/Checkout.php`
  - `yoshop2.0/app/common/model/Order.php`
  - `yoshop2.0/app/api/model/Order.php`
  - `yoshop2.0-uniapp/pages/checkout/index.vue`
  - `yoshop2.0-uniapp/pages/order/detail.vue`
  - `yoshop2.0-store/src/views/order/Index.vue`
  - `yoshop2.0-store/src/views/order/Detail.vue`
  - `yoshop2.0-store/src/views/order/tools/Export.vue`

### 2. Signatures
- Shared checkout request payload keeps the existing order fields and adds **service-only optional fields**:
  - `gamePlatform: 'pc' | 'mobile' | ''`
  - `gameAccountId: string`
  - `contactMobile: string`
  - `adultConfirm: 0 | 1 | boolean-like`
  - `remark: string` (still maps to `buyer_remark`, not service-contact JSON)
- Checkout response/order payload includes:
  - `order.isServicePackage: boolean` for frontend gating on the shared checkout page
- Persisted service-contact storage under order source data:
  - `order.order_source_data.service_contact.game_platform`
  - `order.order_source_data.service_contact.game_account_id`
  - `order.order_source_data.service_contact.contact_mobile`
  - `order.order_source_data.service_contact.adult_confirm`
- Order model appended/readable fields:
  - `game_platform`
  - `game_account_id`
  - `contact_mobile`
  - `adult_confirm`
- API-facing `service_contact` object returns only the new fields above.

### 3. Contracts
#### Service-only validation ownership
- Backend owns service-order validation.
- Frontend may pre-validate for UX, but must only do so when `isServiceCheckout=true`.
- `Checkout::validateOrderFormByService()` must run only when the current order is actually a service package.

#### Shared-page gating contract
- Miniapp checkout must prefer backend-owned `order.isServicePackage` when present.
- Frontend may fall back to `scene=service` only when backend gating data is absent.
- Normal orders must be able to preview and submit without service-order fields.

#### Service-contact semantic contract
- New service-order business semantics are:
  - `game_platform` -> platform selection (`pc` / `mobile`)
  - `game_account_id` -> game account / game ID
  - `contact_mobile` -> emergency contact phone
  - `adult_confirm` -> adult-order confirmation flag
- `buyer_remark` stays on the main order record; do not move it into `service_contact`.
- Legacy fields such as `contact_name` / `time_preference` may still be recognized only for backward-compatible detection of old service orders; they are not the new write contract.

#### Rendering/export contract
- Miniapp order detail, store order list/detail, and export all render the new semantics above.
- Missing `adult_confirm` means “not applicable / unknown”, so UI/export presentation must be blank/placeholder rather than asserting `未确认`.

### 4. Validation & Error Matrix
| Condition | Owner | Result |
|---|---|---|
| current order is not a service package | Frontend + Backend | skip service-only validation and skip service-only submit fields |
| `gamePlatform` not in `pc/mobile` for a service order | Backend | reject: `请选择端游或手游` |
| `gameAccountId` empty for a service order | Backend | reject: `请填写游戏ID` |
| `contactMobile` is not a valid mainland-China mobile number | Backend | reject: `请填写正确的联系方式` |
| `adultConfirm` not checked for a service order | Backend | reject: `请确认成年人下单` |
| shared checkout page is rendering a normal order | Frontend | `onVerifyFrom()` returns success without checking service fields |
| `adultConfirm=0` is present on a non-service checkout request | Backend | must not force the request into service mode |
| order/detail/export reads a non-service order without `adult_confirm` | Frontend/Export | show `--` / blank, not `未确认` |

### 5. Good / Base / Bad Cases
- Good:
  - service checkout submits `gamePlatform=pc`, `gameAccountId`, valid `contactMobile`, `adultConfirm=1`, and remark; order detail/store/export all show the same semantics.
- Base:
  - normal goods checkout omits all service fields and still previews/submits successfully on the same page component.
- Bad:
  - infer “this is a service order” from whether a helper returns a non-empty filtered array.
  - run service validation on every checkout because the page happens to contain service-order UI code.
  - render missing `adult_confirm` as a definite business statement (`未确认`) for normal orders.

### 6. Tests Required
- Backend/API regression checks
  - service checkout with valid fields -> assert order save success and persisted `service_contact` fields
  - service checkout with invalid platform/mobile/adult flag -> assert exact validation error
  - non-service checkout without service fields -> assert validation passes
  - non-service checkout with falsey `adultConfirm` in payload -> assert it does not become a service order accidentally
- Frontend checks
  - shared checkout page uses `order.isServicePackage` to gate form validation
  - `onVerifyFrom()` returns early for non-service checkout
  - request preview and submit payload include service fields only for service checkout
- Display/export checks
  - miniapp detail renders platform / game ID / contact / adult confirmation / remark correctly
  - store list/detail/export render blank `adult_confirm` for non-service orders
- Assertion points
  - `order.order_source_data.service_contact.*`
  - `order.isServicePackage`
  - display text for `adult_confirm`
  - export cell value for `adult_confirm`

### 7. Wrong vs Correct
#### Wrong
- Treat “service fields exist in the page/component” as proof that every checkout is a service-order checkout.
- Infer service-mode activation from filtered payload truthiness, which can be broken by falsey values such as `adultConfirm=0`.
- Re-label missing service-only fields on normal orders as explicit negative business facts.

#### Correct
- Let backend determine whether the order is a service package, and let frontend gate on `order.isServicePackage`.
- Validate and persist service fields only for service packages.
- Keep `buyer_remark` on the order record, and keep `adult_confirm` blank when the order never participated in the service-order contract.

---

## Scenario: Merchant all-order search and historical service-order hide command

### 1. Scope / Trigger
- Trigger: merchant “all orders” search now combines existing base keyword search with service-order field search and an independent platform filter.
- Trigger: historical service orders are hidden by a maintenance command with **soft delete only**, so the operational boundary must be explicit.
- Applies to:
  - `yoshop2.0/app/store/model/Order.php`
  - `yoshop2.0/app/store/service/order/Export.php`
  - `yoshop2.0/app/common/command/ServiceOrderHistoryCleanup.php`
  - `yoshop2.0-store/src/views/order/Index.vue`
  - `yoshop2.0-store/src/views/order/tools/Export.vue`

### 2. Signatures
- Merchant list query params extend the existing order list query with:
  - `searchValue: string`
  - `serviceSearchFields: Array<'game_account_id' | 'contact_mobile' | 'buyer_remark'>`
  - `gamePlatform: '' | 'pc' | 'mobile'`
- Existing base keyword search types that must remain supported:
  - `10` order no
  - `20` member nickname
  - `30` member ID
  - `60` third-party payment order no
- Compatibility search types kept for old entry points:
  - `40` service game account ID
  - `50` service contact mobile
- Historical hide command:
  - `php think service-order:history-cleanup --before-time=<timestamp|strtotime-string> [--mode=dry-run|soft-delete]`
- Historical hide mutation boundary:
  - selects rows where `delivery_type = NOTHING`, `is_delete = 0`, `create_time < before_time`
  - mutates only `is_delete = 1` plus `update_time`

### 3. Contracts
#### Merchant search contract
- The merchant page exposes one shared `searchValue`.
- Base `searchType` and checked `serviceSearchFields` are combined with **OR** under the same keyword.
- `gamePlatform` is a separate exact-match filter layered on top of the keyword expression.
- Unsupported `serviceSearchFields` must be normalized away instead of generating ad-hoc SQL.

#### Compatibility contract
- Existing keyword search by order no / out trade no / member nickname / member ID must remain available.
- Old service entry points using `searchType=40/50` remain compatible even after checkbox-based search is added.

#### Historical hide contract
- The command defaults to `dry-run`.
- A valid `--before-time` is required; there is no implicit “today” cutoff.
- Real cleanup mode is **soft delete hide only**.
- Physical delete is forbidden.
- Before mutating rows in `soft-delete` mode, the command must write a backup snapshot to runtime storage.

### 4. Validation & Error Matrix
| Condition | Owner | Result |
|---|---|---|
| `serviceSearchFields` contains unsupported values | Backend | ignore unsupported values during normalization |
| `searchValue` provided with base `searchType` and checked service fields | Backend | build one OR keyword expression across all selected/base fields |
| `gamePlatform` is `pc` or `mobile` | Backend | apply independent exact filter on `service_contact.game_platform` |
| `gamePlatform` empty | Backend | do not apply platform filter |
| `--mode` not in `dry-run/soft-delete` | Command | reject: `mode 只支持 dry-run 或 soft-delete` |
| `--before-time` missing or unparsable | Command | reject: `请通过 --before-time 显式传入有效 cutoff_time` |
| command runs in `dry-run` mode | Command | print summary only; do not update rows |
| command runs in `soft-delete` mode | Command | backup first, then update only `is_delete=1` |
| row already soft-deleted or not `delivery_type=NOTHING` | Command | must not be touched |

### 5. Good / Base / Bad Cases
- Good:
  - search keyword `138` with checked fields `contact_mobile` and `buyer_remark` returns rows that match either field, while `gamePlatform=mobile` further narrows the result set.
  - run `php think service-order:history-cleanup --before-time="2026-01-01 00:00:00"` and receive a dry-run summary only.
- Base:
  - search by order no / third-party payment order no / member nickname / member ID still works without checking any service-search field.
- Bad:
  - interpret service-search checkboxes as AND conditions for the same keyword.
  - overload `deliveryType` as the platform filter.
  - physically delete historical orders or run cleanup without an explicit cutoff time.

### 6. Tests Required
- Merchant search checks
  - keyword + one checkbox -> assert matching rows returned
  - same keyword + multiple checkboxes -> assert OR behavior
  - keyword + base searchType + service checkboxes -> assert base and service conditions coexist under OR
  - `gamePlatform=pc/mobile` -> assert exact platform narrowing
  - base search types `10/20/30/60` remain functional
  - compatibility search types `40/50` remain functional
- Export checks
  - export includes `game_platform`, `game_account_id`, `contact_mobile`, `adult_confirm`
  - non-service orders export blank `adult_confirm`
- Command checks
  - invalid mode -> non-zero exit and exact error
  - missing `before-time` -> non-zero exit and exact error
  - `dry-run` with valid cutoff -> summary only
  - `soft-delete` -> backup file created before row update (use non-production verification)
- Assertion points
  - generated query params / normalized filters
  - command summary output
  - `order.is_delete` mutation boundary
  - backup file path in command output

### 7. Wrong vs Correct
#### Wrong
- Add new service-order search by replacing the old keyword search paths.
- Make checkboxes act like separate mandatory filters for the same keyword.
- Treat historical cleanup as a data-purge command.
- Pick an implicit cutoff time in code because operations can “figure it out later”.

#### Correct
- Preserve the existing base search capabilities and layer checkbox-based service search on top with OR semantics.
- Keep platform as an independent filter.
- Require an explicit cutoff time and default to `dry-run`.
- Hide historical service orders through soft delete only, with a backup written before mutation.

## Practical review checklist
- [ ] Does the shared checkout page skip service-only validation for normal orders?
- [ ] Are service-contact writes limited to `game_platform / game_account_id / contact_mobile / adult_confirm`?
- [ ] Does `buyer_remark` stay outside `service_contact`?
- [ ] Do admin search checkboxes implement OR matching for one keyword?
- [ ] Is `gamePlatform` filtered independently from keyword search?
- [ ] Does the cleanup command require `--before-time` and default to `dry-run`?
- [ ] Does cleanup mutate only `is_delete`, never physically delete rows?
