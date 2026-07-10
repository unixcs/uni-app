# Wxapp Singleton Content Contracts

> Executable contracts for wxapp singleton-content flows that span merchant config, API decisions, and miniapp rendering.

---

## Scenario: Homepage first-login popup and privacy agreement singleton content

### 1. Scope / Trigger
- Trigger: wxapp singleton content now flows through **merchant config -> API controller/model -> user row consume state -> miniapp page gating**.
- Trigger: this feature adds both **new API signatures** and a **new user-table field**, so the contract must be documented outside task-local artifacts.
- Applies to:
  - `yoshop2.0/app/common/model/wxapp/Setting.php`
  - `yoshop2.0/app/store/model/wxapp/Setting.php`
  - `yoshop2.0/app/api/controller/Setting.php`
  - `yoshop2.0/app/api/controller/User.php`
  - `yoshop2.0/app/api/model/User.php`
  - `yoshop2.0-store/src/views/client/wxapp/Setting.vue`
  - `yoshop2.0-uniapp/api/setting.js`
  - `yoshop2.0-uniapp/api/user.js`
  - `yoshop2.0-uniapp/pages/index/index.vue`
  - `yoshop2.0-uniapp/components/first-login-popup/index.vue`
  - `yoshop2.0-uniapp/pages/user/privacy.vue`

### 2. Signatures
- Merchant singleton config keys under `wxapp_setting.basic.values`
  - `firstLoginPopupEnabled: bool`
  - `firstLoginPopupBody: string`
  - `privacyAgreementContent: string`
- User storage field
  - `yoshop_user.first_login_popup_seen_time: int unsigned not null default 0`
- Merchant save entry
  - wxapp settings form submits the three keys above through the existing basic-settings save flow.
- Public content API
  - `GET setting/privacyAgreement`
  - response payload: `{ content: string }`
- Login-required popup decision API
  - `POST user/firstLoginPopup`
  - response payload: `{ popup: { show: boolean, body: string } }`

### 3. Contracts
#### Merchant config ownership
- `wxapp_setting.basic` is the single source of truth for both singleton-content features.
- Store settings UI must read and save the same keys; do not create a second storage location for the same content.

#### Privacy agreement content contract
- The privacy agreement page is a dedicated singleton-content page, not an article-detail reuse.
- API returns only the content needed for rendering the agreement page.
- Empty content is valid; frontend should render an empty state instead of failing.

#### First-login popup decision contract
- Popup eligibility is **account-scoped**, never device-scoped or local-cache-scoped.
- `first_login_popup_seen_time` is the consume marker; `0` means not yet shown.
- The decision API also consumes the chance. Do not split it into a separate "check" call followed by a later "consume" call.
- Backend returns `show=false` when any blocking condition applies:
  - popup disabled
  - popup body empty
  - current account already consumed the chance
- Backend returns `show=true` only to the request that successfully flips `first_login_popup_seen_time` from `0` to a timestamp.

#### Frontend gating contract
- Homepage popup flow is **WeChat miniapp only**.
- Homepage must wait for `components/privacy-popup/index.vue` to emit `end` before attempting `user/firstLoginPopup`.
- Frontend may suppress the API call when the user is not logged in.
- Frontend must mount a dedicated popup component; do not overload the privacy popup or article detail page for this business content.

### 4. Validation & Error Matrix
| Condition | Owner | Result |
|---|---|---|
| `firstLoginPopupEnabled != true` | Backend | `popup.show = false` |
| `firstLoginPopupBody` empty after trim/business read | Backend | `popup.show = false` |
| `first_login_popup_seen_time > 0` | Backend | `popup.show = false` |
| first concurrent request updates `first_login_popup_seen_time` from `0` | Backend | `popup.show = true`, `popup.body = configured body` |
| later concurrent request sees update already consumed | Backend | `popup.show = false` |
| homepage is not logged in | Frontend | do not show popup; API call may be skipped |
| privacy popup has not emitted `end` yet | Frontend | do not call popup decision API yet |
| privacy agreement content empty | Frontend | render empty-state page, not article metadata shell |

### 5. Good / Base / Bad Cases
- Good:
  - logged-in wxapp user enters homepage, privacy popup ends, first request wins conditional update, business popup shows once, later visits do not show again.
- Base:
  - privacy authorization is not needed in current environment, `PrivacyPopup` emits `end` immediately, homepage still runs the same first-login popup decision flow.
- Bad:
  - using local storage or device cache to track popup display state.
  - calling `user/firstLoginPopup` before `PrivacyPopup @end`, causing competing overlays.
  - designing separate `shouldShow` and `consume` endpoints, which reintroduces race windows.
  - reusing article-detail page for privacy agreement singleton content.

### 6. Tests Required
- Backend/API checks
  - disabled popup -> assert `show=false`
  - empty popup body -> assert `show=false`
  - first eligible account hit -> assert `show=true` and timestamp persisted
  - second hit for same account -> assert `show=false`
  - concurrent/repeated calls -> assert only one request can consume the chance
- Merchant UI checks
  - save/reopen wxapp settings -> assert three singleton-content fields round-trip correctly
- Miniapp checks
  - homepage does not attempt business popup before privacy popup `end`
  - popup closes on mask click and content click
  - `pages/user/privacy` loads singleton content and renders empty state when blank
- Assertion points
  - persisted `first_login_popup_seen_time`
  - returned `popup.show`
  - returned `popup.body`
  - page registration for `pages/user/privacy`
  - `pages/user/index.vue` contains the privacy-agreement entry

### 7. Wrong vs Correct
#### Wrong
- Track first-login popup state in local storage because it feels simpler.
- Let frontend decide display state without a backend consume marker.
- Show business popup as soon as homepage `onShow` runs, regardless of privacy-popup lifecycle.
- Reuse article infrastructure for singleton legal content just because it can render HTML.

#### Correct
- Store the consume marker on `yoshop_user` and make the backend own the once-per-account decision.
- Use one decision API that also consumes the chance through a conditional update.
- Gate business-popup attempts on `PrivacyPopup @end` in the miniapp homepage.
- Use a dedicated privacy-agreement page fed by singleton content from `wxapp_setting.basic`.

## Practical review checklist
- [ ] Did I keep `wxapp_setting.basic` as the only content source?
- [ ] Is popup display state tied to account data, not device-local data?
- [ ] Does homepage wait for `PrivacyPopup @end` before asking for the business popup?
- [ ] Can only one request consume the popup chance for a given account?
- [ ] Does the privacy agreement stay independent from article-detail metadata?
