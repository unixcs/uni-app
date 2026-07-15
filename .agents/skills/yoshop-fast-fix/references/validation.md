# Fast Fix Validation

Choose the smallest check that directly covers the modified file. Do not run production/deployment commands.

| Package | Narrow checks | Escalation notes |
|---|---|---|
| `backend` | `php -l <changed.php>`; focused existing test if present | API/DB/payment/state changes are not Fast Fix |
| `miniapp` | inspect page registration/imports; focused existing script; `npm --prefix yoshop2.0-uniapp run build:h5` when compilation coverage is needed | Use `build:mp-weixin:test` for WeChat-specific source/conditional changes; manual WeChat DevTools may remain required |
| `merchant-console` | `npm --prefix yoshop2.0-store run lint:nofix`; focused unit test if one exists | Use `build` when bundling/routes/config are touched |
| `admin-console` | `npm --prefix yoshop2.0-admin run lint:nofix`; focused unit test if one exists | Use `build` when bundling/routes/config are touched |

For CSS/copy-only changes, a focused browser/manual smoke plus syntax/lint coverage is usually more meaningful than unrelated full-suite tests. Record anything not executed and why.
