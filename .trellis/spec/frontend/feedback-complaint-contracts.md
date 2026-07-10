# Feedback Complaint Contracts

> Executable contracts for the feedback / complaint MVP that spans miniapp media selection, shared upload helpers, and merchant-backend entry placement.

---

## Scenario: Miniapp feedback image upload on MP-Weixin

### 1. Scope / Trigger
- Trigger: the feedback page lets users choose evidence images and reuses the shared upload helper.
- Trigger: MP-Weixin `chooseMedia` returns `tempFilePath`, while legacy upload call sites may still read `path`.
- Applies to:
  - `yoshop2.0-uniapp/pages/feedback/index.vue`
  - `yoshop2.0-uniapp/utils/request/upload/utils.js`
  - any future miniapp page that feeds `chooseMedia` results into the shared upload helper

### 2. Required Contract
- Normalize every selected media item before storing it in page state.
- The normalized object must preserve both keys when possible:
  - `path`: `image.path || image.tempFilePath || ''`
  - `tempFilePath`: `image.tempFilePath || image.path || ''`
- Shared upload helpers must resolve the upload source as `item.path || item.tempFilePath`.
- Image preview code may read either field, but must not assume `path` always exists on MP-Weixin.

### 3. Why This Exists
- `uni.chooseImage` often returns `tempFiles[].path`.
- `uni.chooseMedia` on MP-Weixin commonly returns `tempFiles[].tempFilePath`.
- If page state stores raw `chooseMedia` results and the uploader only reads `item.path`, `uni.uploadFile` receives an empty or invalid `filePath`, which surfaces as `uploadFile:fail parameter`.

### 4. Good / Base / Bad Cases
- Good:
  - user selects images from album on MP-Weixin, page state keeps both `path` and `tempFilePath`, upload succeeds
  - preview and upload both work for images chosen from camera or album
- Base:
  - legacy `chooseImage` call sites that already provide `path` continue to work without extra branching
- Bad:
  - passing raw `chooseMedia` objects into the uploader without normalization
  - writing a new uploader call site that only reads `item.path`

### 5. Review Checklist
- [ ] Did the page normalize selected media before persisting to `imageList`?
- [ ] Does the uploader accept `item.path || item.tempFilePath`?
- [ ] Does preview still work after normalization?

## Scenario: Merchant placement for popup/privacy content editing

### 1. Scope / Trigger
- Trigger: homepage first-login popup content and privacy-agreement content remain editable in merchant backend, but no longer live inside wxapp base settings.
- Applies to:
  - `yoshop2.0-store/src/config/router.config.js`
  - `yoshop2.0-store/src/views/client/wxapp/Setting.vue`
  - `yoshop2.0-store/src/views/content/editor/Index.vue`
  - matching store-menu permission rows in `yoshop_store_menu` / `yoshop_store_menu_api`

### 2. Required Contract
- Keep wxapp base settings focused on access, app credentials, shipping toggle, and domain hints.
- Expose popup/privacy editing under the merchant tree:
  - second level: `/store/content` with title `内容编辑`
  - third level page: `/store/content/editor`
- Do not reintroduce popup/privacy form fields into `/client/wxapp/setting`.

### 3. Review Checklist
- [ ] Does the store sidebar show `店铺管理 -> 内容编辑 -> 首页首登弹窗与隐私协议`?
- [ ] Does `client/wxapp/Setting.vue` exclude popup/privacy form fields?
- [ ] Do merchant permissions include `/store/content` and `/store/content/editor` plus the reused wxapp setting APIs?
