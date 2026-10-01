import { expect, type APIRequestContext, type Locator, type Page } from '@playwright/test';
import { adminApi } from './support';

/**
 * The Cluster Nodes page's row of the load balancer the LB specs drive
 * (XC_E2E_LB_SERVER: its server id, enrolled in the cluster API), and the
 * helpers they share. Each spec skips itself without XC_E2E_LB_SERVER.
 */

export const lb = Number(process.env.XC_E2E_LB_SERVER || 0);

/** Open the page and return the node's row. */
export async function row(page: Page): Promise<Locator> {
  const resp = await page.goto('./cluster_nodes');
  expect(resp?.status(), 'cluster_nodes').toBe(200);
  const tr = page.locator(`#node-${lb}`);
  await expect(tr, `node ${lb} on the page`).toBeVisible();
  return tr;
}

/** Press one of the row's cluster_action buttons; the page posts and reloads. */
export async function act(page: Page, action: string): Promise<void> {
  const tr = await row(page);
  const posted = page.waitForResponse((r) => r.request().method() === 'POST' && /cluster_nodes/.test(r.url()));
  await tr.locator(`button[name="cluster_action"][value="${action}"]`).first().click();
  expect((await posted).status(), action).toBeLessThan(400);
  await expect(page.locator('body')).not.toContainText(/Fatal error|Uncaught|Stack trace/);
}

/** Reload the page until the row satisfies `ok`, or fail after `ms`. */
export async function until(page: Page, what: string, ok: (tr: Locator) => Promise<boolean>, ms = 120_000): Promise<void> {
  const end = Date.now() + ms;
  for (;;) {
    if (await ok(await row(page))) {
      return;
    }
    if (Date.now() > end) {
      throw new Error(`timed out waiting for: ${what}`);
    }
    await page.waitForTimeout(3000);
  }
}

export const health = async (tr: Locator): Promise<string> => (await tr.locator('td').nth(1).locator('.badge').first().innerText()).trim();
export const flowOn = async (tr: Locator, flow: string): Promise<boolean> => (await tr.locator(`button[value="${flow}_off"]`).count()) > 0;
export const epochCell = (tr: Locator): Locator => tr.locator('td').filter({ hasText: /\(gen \d+\)/ }).first();
export const epoch = async (tr: Locator): Promise<number> => Number((await epochCell(tr).innerText()).trim().split(/\s+/)[0]);
/** The node's command queue: the badge three cells after the epoch's. */
export const queued = async (tr: Locator): Promise<number> => Number((await epochCell(tr).locator('xpath=following-sibling::td[3]').innerText()).trim());
/** The node's last heartbeat as the page shows it (UTC, to the second), in the cell after the queue's. */
export const lastSeen = async (tr: Locator): Promise<string> =>
  ((await epochCell(tr).locator('xpath=following-sibling::td[4]').innerText()).match(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/) ?? [''])[0];

/** The load balancer's server name, as the page's first cell shows it. */
export async function lbName(page: Page): Promise<string> {
  return (await (await row(page)).locator('td').first().innerText()).split('\n')[0].trim();
}

/** Run `body` with the node's `flow` on, then put the flow back as it was. */
export async function withFlow(page: Page, flow: string, body: () => Promise<void>): Promise<void> {
  const wasOn = await flowOn(await row(page), flow);
  if (!wasOn) {
    await act(page, `${flow}_on`);
    await until(page, `${flow} on`, (tr) => flowOn(tr, flow), 30_000);
  }
  try {
    await body();
  } finally {
    if (!wasOn) {
      await act(page, `${flow}_off`);
    }
  }
}

/** The server page's live figures for a server (`api?action=server_view`): its watchdog data and counts. */
export async function serverView(request: APIRequestContext, serverID: number): Promise<{ watchdog: Record<string, any> | null; [k: string]: any }> {
  const body = await adminApi(request, 'server_view', { server_id: serverID });
  return body?.data ?? { watchdog: null };
}
