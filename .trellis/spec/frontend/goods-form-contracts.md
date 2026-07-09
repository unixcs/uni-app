# Goods Form Contracts

> Executable contracts for merchant-backend goods create/update flows that feed backend-owned business rules.

---

## Scenario: Virtual payment rules on merchant goods forms

### 1. Scope / Trigger
- Trigger: merchant backend goods create/edit touches **frontend form payload -> store goods model -> goods table** across layers.
- Trigger: virtual payment enablement depends on request payload plus backend model defaults (`delivery_type`, `serviceIds`) and was already proven to fail when a page submit omits context fields.
- Applies to:
  - `yoshop2.0-store/src/views/goods/Create.vue`
  - `yoshop2.0-store/src/views/goods/Update.vue`
  - `yoshop2.0-store/src/common/model/goods/Index.js`
  - `yoshop2.0/app/store/model/Goods.php`
  - `yoshop2.0/app/common/model/Goods.php`

### 2. Signatures
- Detail API -> merchant form hydration
  - `GET /store/goods/detail&goodsId=<id>`
- Create API
  - `POST /store/goods/add`
  - payload: `{ "form": GoodsFormPayload }`
- Edit API
  - `POST /store/goods/edit&goodsId=<id>`
  - payload: `{ "form": GoodsFormPayload }`
- Storage fields used by VP rule owner
  - `goods_price` (single source of truth)
  - `vp_enabled`
  - `vp_product_id`
  - `vp_product_name` (mirror field only)
  - `vp_price_snapshot`
  - `delivery_type`
  - goods-service relations (`serviceIds` / `goods_service_rel`)

### 3. Contracts
#### Request contract
- Merchant form may submit a **partial business context**.
- In current merchant pages, real submit payload does **not** include `delivery_type`.
- Frontend may submit `vp_product_id`, `vp_product_name`, `vp_price_snapshot` for UX/display, but backend must canonicalize them from `goods_price` when `vp_enabled=1`.

#### Backend ownership contract
- `goods_price` is the only price source.
- Backend is the virtual payment rule owner.
- `vp_product_name` is for merchant display / validation / mirror storage only; it must not expand payment-core dependencies.
- If request payload omits `delivery_type`, backend validation must reconstruct the service-goods context from model defaults / persisted goods state instead of trusting frontend completeness.
- If edit payload omits `serviceIds`, backend validation may fall back to persisted goods-service relations for **eligibility checking only**.

#### Frontend contract
- Frontend can auto-fill and pre-validate VP fields.
- Frontend must not become the only owner of service-goods eligibility or VP derivation rules.
- Frontend tests should include the real page submit shape, not only an idealized detail-derived payload.

### 4. Validation & Error Matrix
| Condition | Owner | Result |
|---|---|---|
| `vp_enabled != 1` | Backend | clear `vp_product_id`, `vp_product_name`, `vp_price_snapshot` |
| `vp_enabled = 1` and `spec_type != 10` | Backend | reject: `启用虚拟支付的商品仅支持单规格` |
| `vp_enabled = 1` and not service/no-delivery goods after backend context reconstruction | Backend | reject: `仅单规格且无需配送的服务商品可启用虚拟支付` |
| `vp_enabled = 1` and `goods_price <= 0` | Backend | reject: `虚拟支付商品价格必须大于0` |
| `vp_enabled = 1` and `goods_price >= 1` but not integer yuan | Backend | reject: `启用虚拟支付时，1元及以上的商品价格必须为整数` |
| `vp_enabled = 1` and request sends mismatched `vp_*` fields | Backend | overwrite with values derived from `goods_price` |
| `goods_price = 0.02` | Backend | canonicalize to `vip002 / vip002 / 2` |
| Edit/create payload omits `delivery_type` | Backend | rebuild validation context; do **not** reject only because frontend omitted the field |

### 5. Good / Base / Bad Cases
- Good:
  - edit existing service goods with payload omitting `delivery_type`, set `goods_price=0.02`, `vp_enabled=1` -> save succeeds and stores `vip002 / vip002 / 2`
- Base:
  - add new service goods with payload omitting `delivery_type`, `goods_price=88`, `vp_enabled=1` -> save succeeds and stores `vip88 / vip88 / 8800`
- Bad:
  - enable VP with `goods_price=9.9` -> rejected by integer rule
  - enable VP on non-service / deliverable-only goods -> rejected by service eligibility rule
  - treat detail API payload as the only truth for submit contract -> misses real-page omission regressions

### 6. Tests Required
- API regression tests
  - edit service goods with full payload -> assert canonical VP fields
  - edit service goods with **real UI payload missing `delivery_type`** -> assert save success
  - add service goods with **real UI payload missing `delivery_type`** -> assert save success
  - edit/add with `goods_price=9.9` -> assert integer-rule error
  - edit non-service goods -> assert service eligibility error
  - disable VP -> assert fields cleared
- Frontend helper tests
  - `0.01 -> vip001`
  - `0.11 -> vip011`
  - `158 -> vip158`
  - invalid `9.9`
- Assertion points
  - persisted `vp_product_id`
  - persisted `vp_product_name`
  - persisted `vp_price_snapshot`
  - response error message when rejected

### 7. Wrong vs Correct
#### Wrong
- Derive backend eligibility only from fields that happened to be submitted by the page.
- Assume detail API shape equals submit payload shape.
- Let frontend be the only place that knows a service goods page omits `delivery_type`.

#### Correct
- Reconstruct missing validation context on the backend from persisted model state / accessors when the page omits fields.
- Treat the real submit payload as a separate contract that needs regression coverage.
- Keep frontend auto-fill as UX only; backend still canonicalizes VP fields from `goods_price`.

## Practical review checklist
- [ ] Did I verify the actual create/edit payload shape instead of assuming the detail response shape?
- [ ] If a form field is omitted on submit, is backend validation still correct?
- [ ] Are derived VP fields still canonicalized only from `goods_price`?
- [ ] Did I avoid introducing `vp_product_name` into payment core logic?
