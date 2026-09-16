# XC_VM admin E2E (Playwright)

Browser tests for the admin panel. They run against a **live** panel (there is no
built-in server), so point them at a test instance, never at production:
the admin specs create, edit, start and delete real records.

## What runs

| File | Covers |
| --- | --- |
| `tests/auth.setup.ts` | Signs in once; the other specs reuse that session (`.auth/admin.json`) |
| `tests/dashboard.spec.ts`, `customizer.spec.ts`, `newui-tables.spec.ts` | Smoke: the new-UI shell, dashboard tiles, per-user UI prefs, every migrated list page loads |
| `tests/admin/catalog.spec.ts` | Stream category, bouquet and reseller package: create, rename / edit, reopen, delete |
| `tests/admin/subscribers.spec.ts` | Line with a bouquet: create, search, edit in the modal, disable / enable, ban / unban, delete; MAG and Enigma2 devices: create, find, delete |
| `tests/admin/bulk.spec.ts` | Lines bulk bar: select with the header checkbox, bulk disable, bulk delete |
| `tests/admin/resellers.spec.ts` | Reseller: create with credits, top up from the list, edit in the modal, disable / enable, delete |
| `tests/admin/blocklists.spec.ts` | Block and unblock an IP, a user agent and an ISP |
| `tests/admin/streams.spec.ts` | Live stream: add with a source and a server, start, wait until it runs with codecs and the Resources column (producer / CPU / RAM), stop, rename, delete |
| `tests/admin/signin.spec.ts` | A second administrator: created, refused with a wrong password, signs in and out, deleted |
| `tests/admin/cleanup.teardown.ts` | After everything: removes whatever the admin specs left behind (see below) |

## Run

```bash
# one-time: install deps + the Chromium browser
cd tests/e2e && npm ci && npx playwright install --with-deps chromium

# configure (or copy .env.example -> .env)
export XC_E2E_BASE_URL="http://<host>:<port>/<access-code>"
export XC_E2E_USER="<admin user>"
export XC_E2E_PASS="<admin pass>"

# from the repo root:
make e2e          # headless run
make e2e-ui       # interactive Playwright UI

# one spec, e.g. while working on it:
cd tests/e2e && npx playwright test tests/admin/streams.spec.ts
```

`XC_E2E_BASE_URL` is the admin base including the access-code path segment
(e.g. `http://panel.example.com:8080/ACCESS_CODE`) — the same prefix as `/<code>/login`.

Optional:

| Variable | Default | Used by |
| --- | --- | --- |
| `XC_E2E_STREAM_SOURCE` | Unified Streaming's public 24/7 demo channel | `streams.spec.ts` — a **live** source the panel's server can reach |
| `XC_E2E_SERVER` | `Main Server` | `streams.spec.ts` — the server the stream runs on |
| `XC_E2E_RUN` | a timestamp | the run tag in every record name (below) |

Traces/screenshots/video for failures land in `test-results/`, the HTML report in
`playwright-report/` (`npm run report`).

## A dedicated admin account

Use an account that exists only for the tests. A successful admin login re-hashes
the password, which **signs out every other session of that account** — each run
would log a person out of their own panel.

`tools/create-admin.php` creates (or re-keys) that account on the panel host, the
same way the first-run setup page does. The password is read from stdin:

```bash
scp tests/e2e/tools/create-admin.php root@panel:/tmp/
ssh root@panel 'sudo -u xc_vm /home/xc_vm/bin/php/bin/php /tmp/create-admin.php e2e_admin' < password.txt
```

## Test data

Every record the admin specs create is named after the run: `e2e-<run>-<label>`
(categories, bouquets, packages, streams, block lists), `e2e<run><label>` for
usernames, and devices carry an `e2e-<run>` tag in their notes. Each spec deletes
what it made; `cleanup.teardown.ts` runs after the whole suite (also after a
failure) and sweeps anything matching those patterns, from any earlier run. It
never touches other records, nor the `XC_E2E_USER` account.

Side effects on the panel host worth knowing:

- **Blocking an IP** adds an iptables DROP rule. The spec uses an address from
  198.51.100.0/24 (RFC 5737 documentation range) and lifts the block again.
- **The wrong-password test** counts once against the login flood limit
  (Settings → `login_flood`, failures per IP per day).
- **The stream test** pulls its source for a minute or two, and the panel's cron
  must be running: codecs and resources are filled in by `cron:streams`.
