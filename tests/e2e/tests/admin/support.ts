import { expect, type APIRequestContext, type FrameLocator, type Locator, type Page } from '@playwright/test';

/**
 * Shared helpers for the administrator-action specs.
 *
 * Everything these specs create is named with TAG (`e2e-<run>`), so a run can
 * find its own records and the cleanup project (cleanup.teardown.ts) can sweep
 * what a failed run left behind — without ever touching data it did not create.
 */

/** One id per run. Lowercase letters and digits only: usable in any name field. */
export const RUN = (process.env.XC_E2E_RUN || Date.now().toString(36)).toLowerCase();

/** Prefix of every record the suite creates, in any run. */
export const PREFIX = 'e2e';

/** This run's tag, e.g. `e2e-m1abc2`. */
export const TAG = `${PREFIX}-${RUN}`;

/** A display name for this run: `e2e-<run>-<label>`. */
export const uniq = (label: string): string => `${TAG}-${label}`;

/**
 * An alphanumeric identifier for fields that refuse punctuation (line and
 * reseller usernames): `e2e<run><label>`.
 */
export const ident = (label: string): string => `${PREFIX}${RUN}${label}`.replace(/[^a-z0-9]/gi, '');

/** A MAC address in the 00:1A:79 (MAG) range, unique per run and label. */
export function mac(label: string): string {
  let h = 0;
  for (const c of RUN + label) {
    h = (h * 31 + c.charCodeAt(0)) >>> 0;
  }
  const b = [h >>> 16, h >>> 8, h].map((x) => (x & 0xff).toString(16).padStart(2, '0'));
  return `00:1A:79:${b.join(':')}`.toUpperCase();
}

/** An address in 198.51.100.0/24 (RFC 5737 documentation range, never routed). */
export function docIP(label: string): string {
  let h = 7;
  for (const c of RUN + label) {
    h = (h * 33 + c.charCodeAt(0)) >>> 0;
  }
  return `198.51.100.${(h % 250) + 2}`;
}

type Scope = Page | FrameLocator;

/**
 * Submit an admin form and assert the panel accepted it.
 *
 * The new-UI forms post with fetch to `post.php?action=<action>` and answer
 * JSON — `{result: true, location}` on success, `{result: false, status}` when
 * validation refused it. The request is routed through the test so its body is
 * captured before the page navigates away on success (the browser drops the
 * body of a response whose document is gone). Routing on the page also covers
 * the requests of an edit modal's iframe.
 */
export async function submitForm(page: Page, scope: Scope, action: string, submit?: Locator): Promise<Record<string, unknown>> {
  const pattern = new RegExp(`/post\\.php\\?action=${action}(&|$)`);
  let captured: (text: string) => void = () => undefined;
  const answered = new Promise<string>((resolve) => (captured = resolve));
  const handler = async (route: import('@playwright/test').Route) => {
    if (route.request().method() !== 'POST') {
      return route.fallback();
    }
    const resp = await route.fetch();
    captured(await resp.text());
    await route.fulfill({ response: resp });
  };
  await page.route(pattern, handler);
  let text: string;
  try {
    await (submit ?? scope.locator('button[type="submit"]').first()).click();
    text = await Promise.race([
      answered,
      new Promise<string>((_, reject) => setTimeout(() => reject(new Error(`no post.php?action=${action} within 30s`)), 30_000)),
    ]);
  } finally {
    await page.unroute(pattern, handler);
  }
  let body: Record<string, unknown> | null = null;
  try {
    body = JSON.parse(text);
  } catch {
    // fall through to the assertion below with the raw text
  }
  expect(body, `post.php?action=${action} answered non-JSON: ${text.slice(0, 300)}`).not.toBeNull();
  expect(body!.result, `post.php?action=${action} refused the form: ${text.slice(0, 300)}`).not.toBe(false);
  return body!;
}

/**
 * Call an admin API action directly (the same GET the list pages make) and
 * return its JSON. `api?action=<action>&sub=<sub>&<params>`.
 */
export async function adminApi(request: APIRequestContext, action: string, params: Record<string, string | number> = {}): Promise<any> {
  const resp = await request.get('api', {
    params: { action, ...Object.fromEntries(Object.entries(params).map(([k, v]) => [k, String(v)])) },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(resp.status(), `api?action=${action}`).toBe(200);
  return resp.json().catch(() => null);
}

/**
 * Rows of a server-side admin table (TableController, `./table?id=<id>`)
 * matching a search term — the JSON the DataTables on the list pages load.
 */
export async function tableRows(request: APIRequestContext, id: string, search: string, extra: Record<string, string> = {}): Promise<any[]> {
  const resp = await request.get('table', {
    params: { id, draw: '1', start: '0', length: '100', 'search[value]': search, ...extra },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(resp.status(), `table?id=${id}`).toBe(200);
  const body = await resp.json();
  return Array.isArray(body?.data) ? body.data : [];
}

/**
 * Wait for a DataTable to finish its next server-side load, then return. Arm it
 * BEFORE the action that triggers the reload.
 */
export function tableReload(page: Page): Promise<unknown> {
  return page.waitForResponse((r) => /\/table(\?|$)/.test(r.url()), { timeout: 30_000 });
}

/**
 * Type into a list page's search box and wait for the load that carries the
 * term — not merely the next load, which may be the unfiltered first draw still
 * in flight.
 */
export async function searchTable(page: Page, term: string, box = '#filter-search'): Promise<void> {
  const reload = page.waitForResponse(
    (r) => /\/table(\?|$)/.test(r.url()) && decodeURIComponent(r.url()).includes(`[value]=${term}`),
    { timeout: 30_000 },
  );
  const input = page.locator(box).first();
  await input.fill(term);
  // Some pages filter on keyup, which fill() does not send.
  await input.press('End');
  await reload;
}

/**
 * `selector` inside a table row, wherever DataTables Responsive put it. When the
 * table is too wide, low-priority columns (actions, resources, …) are folded
 * into a child row behind the row's "+" control; that row is opened here.
 */
export async function revealInRow(row: Locator, selector: string): Promise<Locator> {
  const inline = row.locator(selector);
  if (await inline.first().isVisible()) {
    return inline;
  }
  const child = row.locator('xpath=following-sibling::tr[1][contains(concat(" ", @class, " "), " child ")]');
  if (!(await child.count())) {
    await row.locator('td.control, td.dtr-control').first().click();
  }
  await expect(child.locator(selector).first()).toBeVisible();
  return child.locator(selector);
}

/** The element holding a row's actions dropdown (the row, or its child row). */
async function actionsHost(row: Locator): Promise<Locator> {
  const toggle = await revealInRow(row, '[data-bs-toggle="dropdown"]');
  return toggle.locator('xpath=ancestor::*[contains(concat(" ", @class, " "), " dropdown ")][1]');
}

/** Open `row`'s actions dropdown; returns the element holding the open menu. */
export async function openRowMenu(row: Locator): Promise<Locator> {
  const host = await actionsHost(row);
  await host.locator('[data-bs-toggle="dropdown"]').click();
  await expect(host.locator('.dropdown-menu')).toBeVisible();
  return host;
}

/** The single table row containing `text`. */
export async function rowWith(table: Locator, text: string): Promise<Locator> {
  const row = table.locator('tbody tr', { hasText: text });
  await expect(row, `one row containing "${text}"`).toHaveCount(1);
  return row;
}

/**
 * Open a row's actions dropdown and click the entry with `data-sub=<sub>`.
 * Confirms the SweetAlert / native dialog a destructive action raises (with
 * `confirm`), and returns the JSON of the API call it makes.
 */
export async function rowAction(page: Page, row: Locator, sub: string, opts: { confirm?: boolean; api?: RegExp } = {}): Promise<any> {
  const host = await openRowMenu(row);
  const item = host.locator(`.dropdown-menu [data-sub="${sub}"]`);
  await expect(item).toBeVisible();
  // Match this action's own call: the header polls ./api?action=... on its own.
  const api = opts.api ?? new RegExp(`/api\\?action=[a-z_]+&sub=${sub}(&|$)`);
  const called = page.waitForResponse((r) => api.test(r.url()), { timeout: 30_000 });
  await confirmNext(page, opts.confirm ?? false, () => item.click());
  const resp = await called;
  return resp.json().catch(() => null);
}

/**
 * Run `act`, accepting whichever confirmation it raises: the shared SweetAlert
 * modal (xcConfirm / confirmSwal) or a native window.confirm.
 */
export async function confirmNext(page: Page, expectDialog: boolean, act: () => Promise<void>): Promise<void> {
  const onDialog = (d: import('@playwright/test').Dialog) => d.accept();
  page.once('dialog', onDialog);
  try {
    await act();
    if (expectDialog) {
      const swal = page.locator('.swal2-confirm');
      // A native confirm was already accepted by the handler; only a Swal needs a click.
      await swal.click({ timeout: 3_000 }).catch(() => undefined);
    }
  } finally {
    page.off('dialog', onDialog);
  }
}

/**
 * Open the list page's edit modal for `row` and return its iframe. The pages
 * name the modal differently (#frameModal / #editModal), so it is found as the
 * one that is shown.
 */
export async function openEditModal(page: Page, row: Locator): Promise<FrameLocator> {
  const host = await openRowMenu(row);
  await host.locator('.dropdown-menu .js-edit').click();
  await expect(page.locator('.modal.show iframe')).toBeVisible();
  const frame = page.frameLocator('.modal.show iframe');
  await expect(frame.locator('form').first()).toBeVisible({ timeout: 20_000 });
  return frame;
}

/**
 * The row of a client-side list page (categories, bouquets, packages, block
 * lists) that names `name` — a table row or a list-group item.
 */
export const listRow = (page: Page, name: string): Locator => page.locator('tr, li.list-group-item').filter({ hasText: name });

/**
 * Delete the record in a client-side list row with its trash button (.js-del),
 * confirming, and assert the API call it makes (matched by `api`) succeeded.
 */
export async function deleteListRow(page: Page, row: Locator, api: RegExp): Promise<void> {
  await expect(row, 'one row to delete').toHaveCount(1);
  const done = page.waitForResponse((r) => api.test(r.url()), { timeout: 30_000 });
  await confirmNext(page, true, () => row.locator('.js-del').click());
  const body = await (await done).json().catch(() => null);
  expect(body?.result, `delete answered ${JSON.stringify(body)}`).toBe(true);
}
