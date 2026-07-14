# Tencent production rebuild — final evidence (2026-07-15)

## Evidence interpretation

This file records the final authorized execution/cutover state. Earlier task
research that says production was on maintenance, no live release had occurred,
or an authorization gate was still pending remains valid evidence for that
prior phase; the final results below supersede those status statements without
rewriting the historical record. No credential, plaintext password, PEM
content, private SQL, AppSecret, or phone number is included.

## Repository and deterministic release input

- The original GitHub remote (`git@github.com:unixcs/uni-app.git`) retained
  `main`, whose history was rewritten and updated with `--force-with-lease`.
- Old generated artifacts, non-sentinel uploads, and committed key material
  were removed from the full rewritten history; strict `git fsck` passed.
- At the final repository checkpoint before this evidence-only append, the work
  tree was clean and `.git` was approximately 7.5 MiB.
- The exposed WxApp AppSecret was redacted from history but still requires
  manual rotation; redaction is not revocation.

Final deterministic-build defects were found and corrected in nested Composer
VCS metadata, ThinkPHP's wall-clock services output, Webpack's random app CSS
name, Composer autoload order, and the root Composer interactive prompt. Two
complete builds of the same commit then matched exactly:

- package SHA-256:
  `73994b08593b16705169ed81dac51e9eff87f19596e588153c998af076532081`;
- manifest SHA-256:
  `205fa97db68f8a1344ebd2c88cdeb60d2af3a81998065ee8e7f883a973244f85`;
- no nested VCS metadata was present;
- content-addressed admin and store CSS assets returned HTTP 200 in production.

Final local automated verification passed 37/37 deployment tests and 12/12
production-initializer tests.

## Live release and rollback

A real one-command release succeeded. Final release status was `ok`:

- current: `20260714215523-b777b892d048`;
- previous: `20260714210305-c8ac8179f5c8`;
- release directories present: 3;
- disk use: 13%.

Production smoke checks passed 10/10 after release. A real code rollback to the
previous release also passed 10/10, after which the latest release was restored.
The shared-state snapshot was unchanged across release, rollback, and restore.
Its SHA-256 was
`11a88da94d69be1fd18a308a57beb1a2e582027feecf69ecb2b5b49ad7d59b7f`.
The content-addressed CSS checks continued returning HTTP 200.

The permission regression was fixed and revalidated:

- `/srv/yoshop` root: 0711;
- incoming: `deployer:www-data` 0750;
- backups: `root:root` 0700;
- restricted deployer-boundary test: passed.

## Production data and shared-state boundary

Final sanitized production counts/configuration:

- 64 tables, 11 goods, one page, four uploads;
- one admin and one store user;
- zero customer users, orders, refunds, payment trades, or feedback history;
- virtual payment disabled, production environment selected, B-domain notify
  configuration selected, and secret fields blank;
- zero A-domain references.

Uploads, payment material, and `.env` remained independent shared links. Their
checksums and the overall shared snapshot remained unchanged through the live
release and rollback. No plaintext account credentials are recorded here.
First-login admin/store password changes remain required.

## Backup and restore

- Daily backup: `daily-20260714T211208Z.complete`.
- A real restore into a temporary database succeeded with all 64 tables.
- Database checksums and uploads were verified.
- The temporary database was removed after verification.
- The backup timer is enabled and active.

COS/CAM/offsite backup is deferred; therefore this local-disk proof does not yet
cover total host loss.

## Operations and security

- UFW is active and permits only TCP 22/80/443.
- Fail2ban's SSH jail is active.
- SSH password and keyboard-interactive authentication are off; public-key
  authentication is on; maximum authentication attempts are 3.
- There are no unknown public TCP listeners and no failed systemd units; all
  essential services are active.
- Disk use is 13%.
- ModemManager, udisks2, upower, PackageKit, and fwupd are absent.
- Docker and containerd were intentionally preserved.
- Full Certbot dry runs passed for wx and gxwqb/www; active certificate SANs are
  correct.
- `gxwqb.cn` and `www.gxwqb.cn` return HTTP 200 with identical content SHA-256.
  Host Nginx serves these filing pages from `/mnt/vps/tencent/static-site`.

The final application Timer passed the required stability gate. At the start
of the final sample it had run for 2,075 seconds; it remained enabled and
active, `NRestarts` stayed 0 before and after a further 60 seconds, the journal
had zero warning-or-higher entries, Timer-log growth was zero bytes, and PID
state remained under the shared runtime path.

## Remaining manual/deferred actions

- Rotate the SSH key.
- Rotate the previously exposed WxApp AppSecret.
- Change admin and store passwords at first login.
- Implement COS/CAM/offsite backup.
