# Verification Matrix

> Verification should be risk-proportional and behavior-focused. Run the narrowest check that can detect the introduced defect, then widen when contracts or package boundaries changed.

## Package Checks

| Package | Syntax/lint | Focused tests | Build/smoke |
|---|---|---|---|
| `backend` | `php -l <changed.php>` for every changed PHP file | contract scripts under `yoshop2.0/scripts/tests/` when matching the domain | exercise the owning endpoint/service where safe; payment/refund uses its Spec matrix |
| `miniapp` | project has no generic lint script in `package.json` | scripts under `yoshop2.0-uniapp/scripts/tests/` when matching | `npm --prefix yoshop2.0-uniapp run build:h5`; WeChat-specific: `build:mp-weixin:test` plus DevTools/manual smoke |
| `merchant-console` | `npm --prefix yoshop2.0-store run lint:nofix` | `npm --prefix yoshop2.0-store run test:unit -- <focused path/pattern>` when supported by local runner | `npm --prefix yoshop2.0-store run build`; browser smoke for the changed page |
| `admin-console` | `npm --prefix yoshop2.0-admin run lint:nofix` | unit runner exists but current test coverage is minimal | `npm --prefix yoshop2.0-admin run build`; browser smoke |
| `deployment` | language/shell syntax appropriate to changed file | `python3 -m unittest discover -s deploy/tests -p 'test_*.py'` and focused shell tests as applicable | only use preflight/dry-run/status through `$yoshop-deploy`; remote success requires guarded production evidence |

## Risk-to-Validation Rule

| Change class | Minimum expectation |
|---|---|
| Copy/CSS/local template | focused syntax/lint + visual smoke |
| Local component logic | lint/build or focused unit + scenario smoke |
| API payload/enum | both consumer and producer checks + contract Spec |
| DB/state machine/payment/refund | focused contract tests + cross-layer checks + rollback/compatibility review |
| Production/deployment | `$yoshop-deploy` preflight/dry-run/status gates; never infer production success from local build |

## Important Non-Equivalences

- H5 success ≠ WeChat mini-program success.
- Frontend build success ≠ correct backend permission or API semantics.
- `php -l` success ≠ business-state correctness.
- Local deploy tests ≠ production release success.
- A broad green test suite ≠ the changed user scenario was exercised.

Always report skipped checks and remaining manual verification.
