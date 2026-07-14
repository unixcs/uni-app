# Validation

## Root-cause proof

- Real order and relations exist; backend detail projection is complete.
- Broken focused test reproduced `TypeError: Cannot read properties of undefined (reading 'unix')` without any `$moment` mock.
- Trigger is non-zero `virtual_payment_summary.last_notify_time` after virtual-payment notification.

## Automated checks

```bash
cd /opt/yoshop/yoshop2.0-store
./node_modules/.bin/eslint \
  src/utils/enum.js src/views/order/Detail.vue src/views/order/Index.vue \
  src/views/order/refund/Index.vue src/views/order/refund/Detail.vue \
  src/views/order/tools/modules/ExportList.vue \
  tests/unit/order/Detail.spec.js tests/unit/order/refund/Detail.spec.js \
  tests/unit/utils/enum.spec.js
./node_modules/.bin/vue-cli-service test:unit \
  tests/unit/order/Detail.spec.js tests/unit/order/refund/Detail.spec.js \
  tests/unit/utils/enum.spec.js --runInBand
NODE_OPTIONS=--openssl-legacy-provider ./node_modules/.bin/vue-cli-service build

cd /opt/yoshop/yoshop2.0
php ../.trellis/tasks/07-12-store-order-detail-blank/research/store-order-detail-regression.php \
  --order-no=334517902292559509
```

Results:

- ESLint: pass.
- Jest: 24/24 pass across 3 suites (12 order-detail/render + 2 refund-detail guards + 10 safe-enum).
- Merchant production build: pass; only pre-existing Browserslist and CSS-order warnings.
- Backend real-order projection: all 9 assertions pass.
- `git diff --check`: pass.
- Trellis task validation: pass.

## Deployment checks

- Built `dist/index.html` and deployed `public/store/index.html` are byte-identical.
- Final deployed bundle: `js/order.3eb05913.js`.
- New bundle contains `订单详情加载失败` and contains no `$moment` reference.
- nginx active after reload.
- `http://127.0.0.1/store/`: HTTP 200, references new bundle.
- `https://wx.oiob.cn/store/`: HTTP 200, references new bundle.
- `https://wx.oiob.cn/store/js/order.3eb05913.js`: HTTP 200; guard present, broken expression absent.

## Review conclusion

No remaining defect was found on the reported path. Exact sibling `$moment` misuse does not exist elsewhere in source. Silent invalid API payload handling was hardened. All order-module unsafe enum display dereferences found by the audit were migrated to the tested `getEnumName` fallback helper, including the prototype-key edge case. Order detail and refund detail now both expose explicit API-error states and normalize optional relations rather than risking another blank render.
