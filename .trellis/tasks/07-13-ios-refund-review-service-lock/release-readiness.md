# Release Readiness — iOS Refund Review / Service Lock

Date: 2026-07-14
Status: **BACKEND AND MERCHANT UI DEPLOYED; MINIAPP COMPILED, NOT UPLOADED**

## 1. Database gate

Executable schema audit passed against the current database:

- `yoshop_order.ios_refund_risk_status`: `tinyint(3) unsigned`
- `yoshop_order.ios_refund_risk_source`: `varchar(32)`
- `yoshop_order.ios_refund_risk_time`: `int(11) unsigned`
- `yoshop_payment_ios_refund_inquiry`: 24 columns
- indexes: `PRIMARY`, `migration_key`, `order_received(order_id,received_at,inquiry_id)`, `trade_id`, `fingerprint`

Backfill dry-run:

```json
{"mode":"dry-run","inspected":3,"eligible":2,"changed":0,"unchanged":2,"ignored_ios_flag_only":0,"migrated_inquiries":0,"conflicts":1,"errors":0,"last_trade_id":10340}
```

The one conflict (`trade_id=10333`) was resolved by a read-only database audit: it is an `UNPAID` trade for cancelled order `10512`; the order has `trade_id=0`, no other trade, no service refund, no inquiry event, no trusted refund-success event, and no `ios_refund_required` evidence. The order risk is `NONE`. It is therefore an unbound unpaid candidate with no qualifying refund evidence, so backfill correctly makes no risk mutation. Because all eligible rows report `changed=0`, no backfill apply was necessary.

## 2. Backend and merchant deployment

The live services are:

- Nginx root: `/opt/yoshop/yoshop2.0/public`
- merchant static directory: `/opt/yoshop/yoshop2.0/public/store`
- backend runtime: `php8.3-fpm.service`
- web runtime: `nginx.service`

Before sync, the merchant directory was backed up to:

```text
/var/backups/yoshop/public-store-20260714-145250.tar.gz
SHA256 17953729ca901e694bbf3288f1e6c98797eb12cca27cb7792c88d2d493a038c2
```

The verified merchant build was synchronized with `rsync -a --delete`. A subsequent checksum dry-run reported:

```text
remaining_changes=0
```

PHP-FPM and Nginx were restarted at `2026-07-14 14:52:51 CST`:

```text
php8.3-fpm.service  active/running  MainPID=146299
nginx.service       active/running  MainPID=146318
/run/php/php8.3-fpm.sock            PASS
nginx -t                            PASS
```

Actual Nginx HTTP verification with `Host: wx.oiob.cn` passed:

```text
/store/                         200
/store/js/order.5035168f.js     200
/index.php                      200
```

The live index now references:

```text
css/order.f5230d9d.css
js/app.c8539f8c.js
js/order.5035168f.js
```

The fetched live order bundle contains all required markers:

- `App Store退款`
- `服务已冻结`
- `ios_refund_inquiry_timeline`

The post-restart service journals contain no fatal/critical startup entries, Nginx's error log is empty, and the deployed `index.html` / order-bundle SHA256 values exactly match the verified merchant `dist/`.

## 3. Miniapp Windows sync and compile

The WSL source was synchronized to:

```text
D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp
```

SHA256 equality between WSL and Windows was verified for:

- `pages/order/detail.vue`
- `pages/refund/apply.vue`
- `pages/refund/detail.vue`
- `components/refund/IosAppleRefundGuide.vue`
- `config.js`

HBuilderX 5.15 compilation completed successfully from `2026-07-14 15:35:57` to `15:36:09` (`ready in 10761ms`). Output:

```text
D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp\unpackage\dist\dev\mp-weixin
```

The compiled output was refreshed at `15:36:08` and contains:

- `退款申请已提交`
- `App Store`
- `退款详情加载失败，请稍后重试`
- `提交退款申请`
- API endpoint `https://wx.oiob.cn/index.php?s=/api/`

Compiled refund pages/components contain neither `取消退款` nor `撤销退款申请`. This confirms the iOS service-refund non-cancellation UI contract in the actual mp-weixin artifact.

The only compiler warning concerns HarmonyOS UA detection in `core/payment/wechat.js:145`; it is pre-existing, non-blocking, and outside this iOS refund task.

No WeChat experience build was uploaded, because the current authorization covered Windows synchronization and compilation, not upload/publish.

## 4. Callback contract gate

- Plaintext valid/invalid signature entry: PASS
- Safe-mode request authentication/decryption: PASS
- Safe-mode JSON/XML encrypted iOS decision response and decrypt round-trip: PASS
- Plaintext `xpay_refund_notify` outer dispatch, convergence and duplicate idempotency: PASS
- Public signed URL-verification probe: PASS (`https://wx.oiob.cn/notice/virtualPayment.php`, HTTP 200, 1097.53 ms, exact random `echostr` match)
- The broader sandbox-readiness command was intentionally not marked ready because this store is configured for production rather than sandbox; that unrelated environment assertion does not change the endpoint-probe result.
- Real upstream Apple/WeChat delivery: external and not claimed

## 5. Remaining acceptance / release gates

External or separately authorized actions:

1. Upload a WeChat experience build after explicit authorization.
2. Run physical-device miniapp acceptance.
3. Validate one real signed Apple/WeChat inquiry and one trusted refund-success notification in the target environment.

Internal operational acceptance still to perform:

1. Run authenticated merchant visual acceptance at 1280/1366 widths.
2. Continue PHP-FPM/Nginx/application-log and refund-risk-record monitoring during the release observation window.

## 6. Rollback points

- Merchant UI: restore `/var/backups/yoshop/public-store-20260714-145250.tar.gz`, then recheck `/store/` and its referenced hashes.
- Backend runtime: do **not** restore an application version that predates the `LOCKED/REFUNDED` service guards. No hash-addressed prior safe package exists in this mixed worktree, so backend rollback is forward-fix only: preserve the current risk guards, apply the smallest corrective patch, run the focused contracts, then restart PHP-FPM. Nginx configuration itself was not changed by this task.
- Database: use a verified database backup only when rollback is operationally required and compatible with writes made after deployment.
- Never roll `LOCKED` or `REFUNDED` orders back to `NONE`; the business risk state is intentionally irreversible.
