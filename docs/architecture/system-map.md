# YoShop System Map

> Purpose: route a change to the smallest credible code boundary. This is a navigation map, not a substitute for source code or `.trellis/spec/` contracts.

## Runtime Topology

```text
WeChat mini-program / H5        Merchant console             Platform admin
  yoshop2.0-uniapp/             yoshop2.0-store/             yoshop2.0-admin/
          |                            |                            |
          | app/api endpoints          | app/store endpoints        | app/admin endpoints
          +----------------------------+----------------------------+
                                       |
                             ThinkPHP backend
                               yoshop2.0/
                      app/api | app/store | app/admin
                         app/common | app/timer
                                       |
                              MySQL / Redis / WeChat
                                       |
                    deploy/ + deploy.sh release workflow
```

## Package Boundaries

| Trellis package | Source path | Owns | Primary entry points |
|---|---|---|---|
| `backend` | `yoshop2.0/` | ThinkPHP APIs, services, models, callbacks, timers | `route/app.php`, `app/api/`, `app/store/`, `app/admin/`, `app/common/`, `app/timer/`, `think` |
| `miniapp` | `yoshop2.0-uniapp/` | uni-app WeChat/H5 client | `main.js`, `App.vue`, `pages.json`, `pages/`, `api/`, `core/payment/`, `store/` |
| `merchant-console` | `yoshop2.0-store/` | Merchant Vue 2 console | `src/main.js`, `src/views/`, `src/api/`, `src/router/`, `src/permission.js` |
| `admin-console` | `yoshop2.0-admin/` | Platform-admin Vue 2 console | `src/main.js`, `src/views/`, `src/api/`, `src/router/`, `src/permission.js` |
| `deployment` | `deploy/` | Build, package, release, status, rollback, environment and migration tooling | root `deploy.sh`, `deploy/deploy.py`, `deploy/README.md`, `deploy/tests/` |

## Backend Authority Surfaces

The backend is one source tree with consumer-specific surfaces:

- `app/api/`: miniapp/H5-facing controllers, services, models and validation.
- `app/store/`: merchant-console-facing controllers, services and models.
- `app/admin/`: platform-admin-facing controllers, services and models.
- `app/common/`: genuinely shared enums, models, libraries and services. Do not start here unless a caller proves the behavior is shared.
- `app/timer/`: scheduled reconciliation/convergence work.

For a frontend symptom, start in the frontend package. Cross into the corresponding backend surface only when an API, permission, state or payload provides evidence.

## Source, Generated and Runtime Boundaries

Treat these as source:

- `yoshop2.0/app/`, `config/`, `route/`, project scripts
- `yoshop2.0-uniapp/pages/`, `components/`, `api/`, `core/`, `store/`, `utils/`
- `yoshop2.0-store/src/`
- `yoshop2.0-admin/src/`
- `deploy/` excluding generated outputs/reports

Do not use these as initial search roots:

```text
**/node_modules/        dependencies
**/vendor/              Composer dependencies
**/dist/                generated frontend output
yoshop2.0/runtime/      runtime state/cache
yoshop2.0/runtim/       legacy/runtime noise
yoshop2.0/public/uploads/ user/content data
yoshop2.0/public/store/ compiled merchant output
yoshop2.0/public/admin/ compiled admin output
deploy/out/             release artifacts
deploy/reports/         generated reports
.trellis/tasks/archive/ historical work records
```

## Knowledge Entry Points

| Question | Read first |
|---|---|
| Which application owns this symptom? | [Change Routing](change-routing.md) |
| What validation should run? | [Verification Matrix](verification-matrix.md) |
| How should an AI task be run? | [AI Development Manual](../ai-development-manual.md) |
| What coding contract applies? | `python3 ./.trellis/scripts/get_context.py --mode packages`, then one package Spec index |
| What does a business term mean? | root `CONTEXT.md` / future `CONTEXT-MAP.md` |
| Why was a hard-to-reverse decision made? | `docs/adr/` |
| How is production released? | `$yoshop-deploy` and `deploy/README.md` |

## Maintenance Rule

Update this map only when an application boundary, primary entry point, generated/source boundary, or knowledge entry point changes. Put volatile implementation details in source or focused contract Specs instead.
