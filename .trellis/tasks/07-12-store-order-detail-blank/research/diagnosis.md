# Diagnosis

## Evidence chain

1. Database: order `334517902292559509` exists as `order_id=10514`, is paid, has one `order_goods` row and one successful `wechat_virtual` trade.
2. Backend projection: `app\store\model\Order::getDetail(10514)` serializes successfully with user, goods, trade, `backend_action_flags`, and `virtual_payment_summary`.
3. Trigger-specific field: the trade has `notify_times=1` and non-zero `last_notify_time=1783793694`.
4. Merchant component: `virtualPaymentLastNotifyText` calls `this.$moment.unix(...)` only when that value is non-zero.
5. Application bootstrap/search: the merchant app imports `moment` locally in other files and registers filters, but never assigns `Vue.prototype.$moment`; the only `this.$moment` use is the order detail component.

## Root cause

The first paid virtual-payment callback makes `last_notify_time` non-zero. Rendering then evaluates `virtualPaymentLastNotifyText`, calls the nonexistent Vue instance property `this.$moment`, and throws a render-time `TypeError`. Vue fails the render, so the API has valid data while the detail body appears blank.

## Similar-case boundary

- Affected: every order detail whose `virtual_payment_summary.last_notify_time` is non-zero.
- Not triggered: zero/empty timestamp, because the computed property returns `--` before dereferencing `$moment`.
- Backend missing data and missing `order_address` are not the cause; the service-order detail intentionally does not require an address and backend serialization succeeds.

## Similar-condition review

- Repository-wide source search found no other `this.$moment` use after the fix; the merchant bootstrap does not register `$moment` anywhere. Other pages import `moment` explicitly or use registered filters.
- Order detail also directly dereferenced pay-status/payment-method enum entries. Although the example has valid values, unknown legacy/corrupt values could reproduce the same render-abort symptom. The detail now resolves both through placeholder-safe helpers.
- The prior request path converted rejected/empty API responses into `record={}` and then rendered a page full of blanks. The page now distinguishes a valid detail from an invalid/error response and shows an explicit error alert instead of a false blank order.
- Order list/refund pages contain other direct enum reads, but their contracts and example values are valid and they are not on the failing detail path. They are recorded as a broader hardening opportunity rather than changed speculatively in this focused fix.
- Generated production bundle under `yoshop2.0/public/store` still contains the old `this.$moment.unix` call before deployment; source-only success is therefore not sufficient evidence for online repair.

## Verification and deployment evidence

- Regression first failed with `TypeError: Cannot read properties of undefined (reading 'unix')` when invoking the computed property without a `$moment` mock.
- After explicit `moment` import and guards: 11 focused Jest assertions pass, including an actual-shaped render for order `334517902292559509`, empty/invalid timestamps, enum fallbacks, invalid API response, and valid API response.
- Targeted ESLint passes for `Detail.vue` and its regression test.
- Merchant production build passes with only the repository's existing Browserslist/CSS-order warnings.
- Built order bundle `js/order.0ce77c05.js` contains the visible load-error guard and no `$moment` reference.
- `dist/` was deliberately synced to `yoshop2.0/public/store/`; nginx was reloaded and remains active.
- Both `http://127.0.0.1/store/` and `https://wx.oiob.cn/store/` return HTTP 200 and reference the new order bundle. The public bundle returns HTTP 200, contains the new guard, and has no `$moment` reference.

## Murphy-law sibling iteration

A broader order-module scan found four additional unguarded `Enum[value].name` reads in the order list, refund list, refund detail, and export-history list. Although they did not trigger order `334517902292559509`, an unknown legacy/backend value would throw during render and could blank the corresponding page.

Closed by introducing `src/utils/enum.js#getEnumName(enumObject, value, fallback)` and routing all order-module enum labels through it. The helper requires an own enum property, so prototype keys such as `toString` cannot masquerade as valid enum entries. The first utility test caught this prototype-chain edge case, after which the helper was hardened with `hasOwnProperty`.

Final order-module source scan has no unguarded `Enum[value].name` expression. Two focused suites now pass 22 assertions total: 12 detail/render assertions and 10 safe-enum assertions.

## Similar detail-page failure iteration

The refund-detail page had the same failure shape on API rejection or damaged historical relations: `finally` disabled loading, then the template immediately dereferenced `record.orderData.order_no` and `record.user.user_id` from the initial empty object. This could turn an API error or missing optional relation into another blank render.

It now has the same explicit load-error state, validates `order_refund_id`, normalizes optional `orderData/user/orderGoods` relations to empty objects, clears stale state on failure, and returns the Promise for deterministic tests. Two new assertions cover invalid payload and missing-relation normalization.
