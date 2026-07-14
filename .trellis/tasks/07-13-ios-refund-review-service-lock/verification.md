# Verification Evidence — iOS Refund Review / Service Lock

Date: 2026-07-14

## Status legend

- `PASS-A`: automated executable evidence passed.
- `PASS-I`: implementation/schema/route inspection proves the invariant; no external call was made.
- `PENDING-EXT`: requires Apple/WeChat real callback, Mini Program DevTools upload, or release authorization.
- `RELEASE-GATE`: deliberately detects that an artifact has not been published yet.

## Commands executed

```bash
# Backend
php_files=(
  app/api/model/Order.php
  app/api/model/OrderRefund.php
  app/api/model/PaymentTrade.php
  app/api/service/Notify.php
  app/common/command/VirtualPaymentIosRefundRiskBackfill.php
  app/common/enum/order/iosRefund/RiskSource.php
  app/common/enum/order/iosRefund/RiskStatus.php
  app/common/model/OrderRefund.php
  app/common/model/PaymentIosRefundInquiry.php
  app/common/model/PaymentTrade.php
  app/common/service/order/IosRefundRisk.php
  app/common/service/order/Refund.php
  app/store/model/Order.php
  app/store/model/OrderRefund.php
  app/timer/service/Order.php
  scripts/tests/ios-payment-channel-contract.php
  scripts/tests/ios-refund-review-lock-contract.php
  scripts/tests/ios-refund-concurrency-contract.php
  scripts/tests/ios-refund-state-matrix-contract.php
  scripts/tests/non-ios-service-refund-regression-contract.php
)
for file in "${php_files[@]}"; do php -l "$file"; done
php scripts/tests/ios-payment-channel-contract.php
php scripts/tests/non-ios-service-refund-regression-contract.php
php scripts/tests/ios-refund-review-lock-contract.php
IOS_REFUND_MATRIX_TEST=1 php scripts/tests/ios-refund-state-matrix-contract.php
IOS_REFUND_CONCURRENCY_TEST=1 php scripts/tests/ios-refund-concurrency-contract.php
php think virtual-payment:ios-refund-risk-backfill

# Merchant console
npm run test:unit -- --runInBand tests/unit/order
npm run lint:nofix -- --no-ignore \
  src/views/order/Detail.vue src/views/order/Index.vue \
  src/views/order/refund/Detail.vue src/views/order/refund/Index.vue \
  tests/unit/order/Detail.spec.js tests/unit/order/Index.spec.js \
  tests/unit/order/refund/Detail.spec.js
NODE_OPTIONS=--openssl-legacy-provider ./node_modules/.bin/vue-cli-service build

# Miniapp
npm run build:h5
npm run build:mp-weixin

# Trellis / release preparation
git diff --check
python3 ./.trellis/scripts/task.py validate \
  .trellis/tasks/07-13-ios-refund-review-service-lock
nginx -t
```

Observed results:

- PHP lint: 20/20 passed (explicit file list above).
- Non-iOS service refund regression: apply/reject/reapply, developer-refund projection, and Apple-risk isolation passed.
- Mock Apple state matrix: 48/48 service×audit×risk combinations, 1347 assertions passed; no failure case required a code fix.
- Backend contracts: payment-channel, refund/review/lock, and real two-connection concurrency all passed.
- Merchant Jest: 4 suites, 30 tests passed.
- Merchant focused lint: no errors.
- Merchant production build: passed; existing CSS-order warnings only.
- Miniapp H5 build: passed; existing Sass deprecation warnings only.
- Miniapp mp-weixin compile: passed with HBuilderX 5.15; output is a development artifact, not an uploaded experience build.
- Backfill second dry-run: `changed=0`, `errors=0`; the single binding conflict was independently audited as an unbound unpaid trade for a cancelled order with no qualifying refund evidence, so no risk mutation is required.
- Trellis validate / `git diff --check` / `nginx -t`: passed.

## Notify outer-entry contract

The iOS review contract now drives the real `app\api\service\Notify::virtualPayment()` path. It verifies:

- framework request-body parsing through `Request::getInput()`;
- valid and invalid plaintext `sha1(sort(token, timestamp, nonce))` source verification;
- valid safe-mode `Encrypt + msg_signature` authentication/decryption;
- encrypted JSON and XML `IosRefundQueryResponse` wrappers that decrypt back to `result_code/result_info/evidence`;
- `xpay_subscribe_ios_refund_query_notify` dispatch and inquiry persistence;
- real plaintext `xpay_refund_notify` dispatch, order/trade/risk/refund convergence, and duplicate idempotency; and
- authentication failure returning the generic error response without dispatch.

Result: PASS. Only a real upstream Apple/WeChat callback remains `PENDING-EXT`; local safe-mode credentials and the actual Notify entry are now executable-tested.

## 48-state Mock Apple matrix + 45-case Murphy closure

`ios-refund-state-matrix-contract.php` uses a rollback-only local fixture and synthetic Apple inquiry/refund-success payloads. It enumerates:

- service stage: `NOT_STARTED`, `IN_PROGRESS`, `COMPLETED`, `UNKNOWN`;
- merchant audit: no record, `WAIT`, `REVIEWED`, `REJECTED`;
- risk: `NONE`, `LOCKED`, `REFUNDED`.

Each vector exercises the real local-apply API policy, backend service-action flags and post-inquiry service rejection, atomic `provide_goods` claim, two identical inquiry callbacks, append-only history, refund-row idempotency, repeat-apply rejection, trusted refund-success convergence, and duplicate-notify idempotency. The independent two-connection contract remains the authoritative concurrent lock test.

Result: `PASS ios-refund-state-matrix cases=48 assertions=1347`.

## 45-case Murphy matrix closure

| ID | Status | Evidence |
|---|---|---|
| R01 | PASS-A | Real `ApiOrderRefund::apply()` test creates REVIEWED service refund and atomically changes risk to LOCKED. |
| R02 | PASS-A | Two-connection start-first/apply-second test proves waiting apply re-reads IN_PROGRESS, creates WAIT and freezes. |
| R03 | PASS-A | Completed-service real apply is rejected and creates zero service refunds. |
| R04 | PASS-I | Refund insert, risk update and trade snapshot save are in one model transaction; every failed save throws, so rollback is mandatory. No production fault injection hook was added. |
| R05 | PASS-A | Repeat real API apply on LOCKED order is rejected; refund count remains one. |
| R06 | PASS-A | LOCKED repeat is executable-tested; REFUNDED uses the same non-NONE guard and monotonic enum check. |
| R07 | PASS-A | Projection contract asserts `can_cancel=false`; miniapp source/build contains no iOS cancel action. |
| R08 | PASS-I | Route/controller search found no refund-cancel endpoint; no new cancel route exists. |
| R09 | PASS-A | `non-ios-service-refund-regression-contract.php` proves a valid non-iOS virtual trade fails closed when misrouted into Apple inquiry, creates no service refund/risk and only a zero-order audit; its normal apply/reject/reapply flow still remains outside Apple risk. |
| R10 | PASS-I | Non-iOS projection remains `developer_refund`; the legacy approval branch is unchanged and intentionally not invoked against a live refund provider in this local contract. |
| R11 | PASS-A | NOT_STARTED + REVIEWED inquiry returns `result_code=0`, keeps one refund and LOCKED. |
| R12 | PASS-I | `handleInquiry()` creates WAIT when IN_PROGRESS has no refund before deciding `1`; covered structurally plus WAIT executable path. |
| R13 | PASS-A | IN_PROGRESS + WAIT returns `1`. |
| R14 | PASS-A | Same payload after REVIEWED recomputes and returns `0`. |
| R15 | PASS-A | IN_PROGRESS + REJECTED returns `1`. |
| R16 | PASS-A | Completed-service inquiry returns `1` and leaves completed service state unchanged. |
| R17 | PASS-A | Missing trade returns fail-closed `1`; no order mutation. |
| R18 | PASS-A | Final trade/order/store/user/platform/out-trade/pay/trade-state binding is checked before risk mutation; regression proves unpaid trade fails closed and zero-order audit does not pollute the business timeline. |
| R19 | PASS-A / PENDING-EXT | Real `virtualPayment()` contracts cover plaintext valid/invalid signatures, safe-mode decrypt, encrypted JSON/XML decision responses, inquiry persistence, refund-success dispatch and duplicate idempotency. Only a real upstream callback remains external. |
| R20 | PASS-I | Inquiry callback catches business exceptions and emits the dedicated safe rejection shape without the internal exception text. |
| R21 | PASS-A | Identical payload with unchanged audit produces consistent decisions, three inquiry rows and one refund. |
| R22 | PASS-A | WAIT first call returns `1`; REVIEWED then makes identical payload return `0`. |
| R23 | PASS-A | Three identical authenticated inquiries create three retained attempts, one refund and one risk transition. |
| R24 | PASS-I | Risk, refund, trade snapshot and inquiry insert share one DB transaction; risk/insert/snapshot failed writes are checked and throw before response `0`. Fault injection remains a non-production test limitation. |
| R25 | PASS-A | Both lock orderings tested: apply-first blocks start; start-first is valid and waiting apply reads IN_PROGRESS/creates WAIT. This corrected the prior over-strong “cannot both succeed” wording. |
| R26 | PASS-I | Apply and complete use the same order-first lock; completed apply is rejected, and LOCKED complete is executable-tested. |
| R27 | PASS-I | Inquiry/start use the same order-first serialization as directly tested apply/start and inquiry/complete; decision reads post-lock service stage. |
| R28 | PASS-A | Real two-connection inquiry-first/complete-second test: complete blocks, re-reads LOCKED, returns false and preserves receipt state. |
| R29 | PASS-A | Real two-connection merchant approval/inquiry test: inquiry blocks, then reads REVIEWED and returns `0`. |
| R30 | PASS-A | LOCKED `startService()` returns false and leaves delivery unchanged. |
| R31 | PASS-A | LOCKED `completeService()` returns false and leaves receipt unchanged. |
| R32 | PASS-A | Store/timer use an order-first atomic risk check + existing dispatch claim; two-connection inquiry-first test proves a waiting dispatch cannot claim after LOCKED. A second pre-send risk read remains as defense in depth. |
| R33 | PASS-I | Existing timer compensation loop skips LOCKED/REFUNDED and records `ios_refund_risk_locked(_before_send)`. No new worker was added. |
| R34 | PASS-A | Merchant REJECTED history followed by trusted success converges order/trade/risk/refund while preserving rejection and service-start history. |
| R35 | PASS-I | Dedicated success path creates a completed tracking row when service-refund collection is empty; missing goods fails the transaction safely. |
| R36 | PASS-A | Duplicate trusted success notification returns completed and keeps exactly one service refund. |
| R37 | PASS-A | Existing unique refund becomes COMPLETED while merchant audit history remains REJECTED. |
| R38 | PASS-I | Final trade binding check returns `final_trade_binding_conflict` before order/risk/refund mutation; existing loser trade-only path remains outside this branch. |
| R39 | PASS-A | Local backfill already produced LOCKED for order 10519 from real local refund evidence. |
| R40 | PASS-A | Local backfill migrated authenticated inquiry history; repeated dry-run reports no additional migration. |
| R41 | PASS-A | Local backfill converged order 10513 to REFUNDED from trusted success evidence. |
| R42 | PASS-I | Backfill evidence selector does not use `ios_refund_required` alone; only local service refund, authenticated inquiry snapshot, or trusted success qualifies. |
| R43 | PASS-A | Post-apply dry-run: `changed=0`, `errors=0`. |
| R44 | PASS-A | Merchant guards plus miniapp explicit catch/finally/error/retry and fail-closed refund-form gate are present. The refund-detail error contract proves API failure clears stale data, renders persistent error/retry, and hides normal detail; 30 Jest tests, lint, merchant build, H5 build and mp-weixin compile passed. |
| R45 | PASS-A | The prior unsynced-asset gate is closed: live `public/store` now matches `dist` by checksum, HTTP serves `order.5035168f.js` / `order.f5230d9d.css`, all three refund markers are present, and PHP-FPM/Nginx were restarted successfully. |

## Adversarial review closure

本轮仅启动一轮限定范围的双代理对抗审查，没有重开 Plan Review。审查最初指出的证据缺口已按单点处理：

- **并发抢锁**：48 状态矩阵负责状态穷举；`ios-refund-concurrency-contract.php` 负责独立双连接的 apply/start、start/apply、inquiry/complete、audit/inquiry、inquiry/dispatch 五类真实锁顺序。两者职责分离，不把串行矩阵冒充并发证明。
- **服务成功路径**：`non-ios-service-refund-regression-contract.php` 已实际执行 risk `NONE` 的 `startService()` / `completeService()`；矩阵再验证 Apple inquiry 后两条服务操作均后端拒绝。
- **审核实时联动**：既有 review-lock 合同和双连接 audit/inquiry 合同覆盖 WAIT → REVIEWED 后相同 payload 返回 `0`；矩阵覆盖 48 个静态审核向量。
- **Android/非 Apple 兼容**：新增非 Apple apply/reject/reapply 合同，确认不建立 Apple 风险、不写 Apple inquiry，保留 `developer_refund` 投影。
- **任务边界**：`task.json.relatedFiles` 已登记 33 个任务/共享依赖文件；范围扫描只对该 allowlist 作结论，混合工作树中的其他任务文件不纳入本任务发布清单。

仍保留为真实门禁而非伪造通过：真实 Apple/微信回调、微信体验版上传/真机验收、商家认证视觉验收。商家线上目录切换、服务重启、HTTP marker 与公开回调 `echostr` 探针已经完成。测试 oracle 与生产 decision 分支同构的 P2 仅是测试设计债务，不影响当前已验证的运行时结果。

## Adversarial code-review closure

The initial single code-review sub-agent reported `P0=3, P1=5, P2=1`; every P0/P1 was verified, fixed and regression-tested. A final two-agent adversarial pass then found safe-mode response encryption, refund-detail failure rendering, and missing Notify refund-success entry coverage; the local reviewer also found non-iOS inquiry misrouting. All four were fixed with focused regressions before the final full run. Post-fix open counts are `P0=0, P1=0`; the remaining P2 is list projection N+1 performance debt only. Full closure: `code-review.md`.

## Scope regression result

The task metadata `relatedFiles` list is the auditable allowlist for this task. It intentionally excludes files owned only by the concurrent iOS payment-channel and unrelated Trellis tasks; shared files are listed only where this task consumes their final-trade/channel contract. Added-line and new-file scan over the allowlist found no points, settlement, cumulative-spend, coupon, stock, alert table, metrics, heartbeat, systemd, convergence worker, lease, or refund-cancel route implementation. Matches were limited to explicit “do not process/reverse” comments and `can_cancel=false` projections. Existing `provide_goods` claim/compensation machinery was reused; this task did not add a new worker or lease system.

## Deployment and artifact verification

Release execution is recorded in `release-readiness.md`. The merchant static directory was backed up, synchronized from the verified `dist/`, and a second `rsync` dry-run converged with `remaining_changes=0`. PHP-FPM and Nginx restarted successfully at `2026-07-14 14:52:51 CST`; both are active, the PHP-FPM socket exists, and `nginx -t` passes. Actual HTTP requests return 200 for `/store/`, the new order bundle, and `/index.php`; the live index references `order.5035168f.js`, and the fetched bundle contains all three Apple-refund markers.

The miniapp source mirror on Windows matches the WSL source by SHA256 for the changed refund/order files and `config.js`. HBuilderX 5.15 compiled the Windows project successfully at `15:36:09`. The actual mp-weixin output contains the App Store guide, submit and load-error markers, uses the intended API endpoint, and contains no refund-cancel marker in refund pages/components.

A signed public callback URL-verification probe passed against `https://wx.oiob.cn/notice/virtualPayment.php`: HTTP 200 in 1097.53 ms with an exact random `echostr` match. The command's broader sandbox-only environment assertion was not treated as a release result because the store is deliberately configured for production. A read-only audit also resolved trade `10333`: it is unpaid and unbound to cancelled order `10512`, with no service refund, inquiry, trusted success event, or iOS-refund-required evidence.

## External / authorized evidence still required

1. Real Apple/WeChat signed inquiry and refund-success callbacks in the target environment.
2. WeChat experience-build upload and physical-device acceptance; no upload is claimed.

## Internal operational acceptance still required

1. 1280/1366 browser visual acceptance with authenticated production-like data.
2. Release-window log and refund-risk-record monitoring.

## Final full regression after dual-agent adversarial fixes

Date: 2026-07-14

Closed defects:

1. non-iOS virtual trade misrouted into Apple inquiry could establish Apple risk;
2. safe-mode iOS inquiry returned an unencrypted non-empty business response;
3. refund-success tests skipped the real Notify authentication/dispatch boundary;
4. miniapp refund-detail API failure rendered an all-placeholder pseudo-detail.

Focused re-tests passed first:

- non-iOS inquiry fails closed, creates no service refund/risk, and writes only a zero-order audit;
- safe-mode XML and JSON responses contain `Encrypt/MsgSignature/TimeStamp/Nonce` and decrypt to the expected decision/evidence;
- real plaintext `xpay_refund_notify` entry converges order/trade/risk/refund and duplicate delivery stays idempotent;
- miniapp refund-detail error contract clears stale detail, persists error/retry, hides normal detail, and recovers on success.

Final full gate:

- PHP lint: 20/20 task PHP files PASS;
- payment-channel contract: PASS;
- non-iOS service-refund regression: PASS;
- iOS review/lock plus Notify outer-entry contracts: PASS;
- Mock Apple service×audit×risk matrix: 48/48 cases, 1347 assertions PASS;
- real two-connection concurrency contract: PASS;
- backfill dry-run: `changed=0`, `errors=0`; the single unpaid/unbound conflict was read-only audited and has no qualifying refund evidence;
- merchant Jest: 4 suites / 30 tests PASS;
- merchant focused lint: PASS;
- merchant production build: PASS with pre-existing CSS ordering warnings;
- miniapp refund-detail component contract: PASS;
- miniapp H5 build: PASS with pre-existing Sass deprecation warnings;
- HBuilderX 5.15 mp-weixin compile: PASS;
- Trellis validate, `git diff --check`, and `nginx -t`: PASS.

The merchant production `dist` and live `public/store` now both resolve to `js/order.5035168f.js` / `css/order.f5230d9d.css`. PHP-FPM and Nginx were restarted, and actual HTTP bundle-marker verification passed. The Windows miniapp mirror and HBuilderX mp-weixin compilation are also verified. No WeChat upload, physical-device acceptance, authenticated visual acceptance, or real upstream callback is claimed.
