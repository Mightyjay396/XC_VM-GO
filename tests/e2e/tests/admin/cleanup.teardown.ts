import { test as teardown, expect, type Page } from '@playwright/test';
import { PREFIX, adminApi, tableRows } from './support';

/**
 * Sweep what the admin specs created — in this run or in an earlier one that
 * died half-way — so a test panel does not fill up with leftovers.
 *
 * Only records named by the suite are touched: display names `e2e-<run>-…`,
 * identifiers `e2e<run>…` (letters and digits only, so the `e2e_admin` login
 * never matches) and devices whose notes carry an `e2e-<run>` tag.
 */

const NAMED = new RegExp(`^${PREFIX}-[a-z0-9]+-`);
const IDENT = new RegExp(`^${PREFIX}[a-z0-9]+$`);
const TAGGED = new RegExp(`\\b${PREFIX}-[a-z0-9]+\\b`);
const ADMIN = process.env.XC_E2E_USER;

/** Delete every row `pick` selects from a server-side table, one API call each. */
async function sweepTable(
  page: Page,
  table: string,
  pick: (row: any) => boolean,
  remove: (row: any) => [string, Record<string, string | number>],
): Promise<number> {
  let removed = 0;
  for (const row of (await tableRows(page.request, table, PREFIX)).filter(pick)) {
    const [action, params] = remove(row);
    const body = await adminApi(page.request, action, params);
    expect.soft(body?.result, `${table}: ${action} ${JSON.stringify(params)} answered ${JSON.stringify(body)}`).toBe(true);
    removed++;
  }
  return removed;
}

/**
 * Delete every row of a client-side list page whose text matches NAMED, via the
 * id on its trash button (.js-del).
 */
async function sweepList(page: Page, list: string, action: string, idParam: string): Promise<number> {
  await page.goto('./' + list);
  const ids = await page.locator('tr, li.list-group-item').evaluateAll(
    (rows, source) => {
      const named = new RegExp(source);
      return rows
        .filter((r) => [...r.querySelectorAll('td, span')].some((c) => named.test((c.textContent || '').trim())))
        .map((r) => r.querySelector('.js-del')?.getAttribute('data-id'))
        .filter((id): id is string => !!id);
    },
    NAMED.source,
  );
  for (const id of new Set(ids)) {
    const body = await adminApi(page.request, action, { sub: 'delete', [idParam]: id });
    expect.soft(body?.result, `${list}: delete ${id} answered ${JSON.stringify(body)}`).toBe(true);
  }
  return new Set(ids).size;
}

teardown('remove test records', async ({ page }) => {
  teardown.setTimeout(120_000);
  const swept: Record<string, number> = {};

  swept.streams = await sweepTable(
    page,
    'streams',
    (r) => NAMED.test(r.title ?? ''),
    (r) => ['stream', { sub: 'delete', stream_id: r.id, server_id: r.server_col_id }],
  );
  swept.mags = await sweepTable(
    page,
    'mags',
    (r) => TAGGED.test(r.notes ?? ''),
    (r) => ['mag', { sub: 'delete', mag_id: r.mag_id }],
  );
  swept.enigmas = await sweepTable(
    page,
    'enigmas',
    (r) => TAGGED.test(r.notes ?? ''),
    (r) => ['enigma', { sub: 'delete', e2_id: r.device_id }],
  );
  swept.lines = await sweepTable(
    page,
    'lines',
    (r) => IDENT.test(r.username ?? ''),
    (r) => ['line', { sub: 'delete', user_id: r.id }],
  );
  swept.resellers = await sweepTable(
    page,
    'reg_users',
    (r) => IDENT.test(r.username ?? '') && r.username !== ADMIN,
    (r) => ['reg_user', { sub: 'delete', user_id: r.id }],
  );

  swept.packages = await sweepList(page, 'packages', 'package', 'package_id');
  swept.bouquets = await sweepList(page, 'bouquets', 'bouquet', 'bouquet_id');
  swept.categories = await sweepList(page, 'stream_categories', 'category', 'category_id');
  swept.useragents = await sweepList(page, 'useragents', 'useragent', 'ua_id');
  swept.isps = await sweepList(page, 'isps', 'isp', 'isp_id');
  // Category templates are cards; the delete entry carries the id and name.
  await page.goto('./category_templates');
  const templateIds = await page.locator('.js-btn-delete').evaluateAll(
    (buttons, source) =>
      buttons
        .filter((b) => new RegExp(source).test(b.getAttribute('data-name') || ''))
        .map((b) => b.getAttribute('data-id'))
        .filter((id): id is string => !!id),
    NAMED.source,
  );
  for (const id of templateIds) {
    const body = await adminApi(page.request, 'category_template_delete', { id });
    expect.soft(body?.result, `category_templates: delete ${id} answered ${JSON.stringify(body)}`).toBe(true);
  }
  swept.templates = templateIds.length;
  // Blocked IPs are named by their notes (the address itself is not tagged).
  await page.goto('./ips');
  const ipIds = await page
    .locator('tr')
    .filter({ hasText: TAGGED })
    .locator('.js-del')
    .evaluateAll((b) => b.map((x) => x.getAttribute('data-id')).filter((id): id is string => !!id));
  for (const id of ipIds) {
    const body = await adminApi(page.request, 'ip', { sub: 'delete', ip: id });
    expect.soft(body?.result, `ips: delete ${id} answered ${JSON.stringify(body)}`).toBe(true);
  }
  swept.ips = ipIds.length;

  const total = Object.values(swept).reduce((a, b) => a + b, 0);
  console.log(total ? `cleanup removed ${JSON.stringify(Object.fromEntries(Object.entries(swept).filter(([, n]) => n)))}` : 'cleanup: nothing left behind');
});
