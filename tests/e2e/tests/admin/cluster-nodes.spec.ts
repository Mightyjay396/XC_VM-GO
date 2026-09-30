import { test, expect, type Locator, type Page } from '@playwright/test';

/**
 * The Cluster Nodes page against a real load balancer enrolled in the cluster
 * API (XC_E2E_LB_SERVER: its server id). The node's flows, its token rotation,
 * a quarantine and a fence are driven from the page and seen reaching the node
 * (its agent acks each command); every test puts the node back as it found it.
 * Without XC_E2E_LB_SERVER the file is skipped.
 */

const lb = Number(process.env.XC_E2E_LB_SERVER || 0);

// One worker runs these in order (playwright.config); each test puts the node
// back itself, so one failing does not skip the others.
test.skip(!lb, 'XC_E2E_LB_SERVER (a load balancer enrolled in the cluster API) not set');

/** Open the page and return the node's row. */
async function row(page: Page): Promise<Locator> {
  const resp = await page.goto('./cluster_nodes');
  expect(resp?.status(), 'cluster_nodes').toBe(200);
  const tr = page.locator(`#node-${lb}`);
  await expect(tr, `node ${lb} on the page`).toBeVisible();
  return tr;
}

/** Press one of the row's cluster_action buttons; the page posts and reloads. */
async function act(page: Page, action: string): Promise<void> {
  const tr = await row(page);
  const posted = page.waitForResponse((r) => r.request().method() === 'POST' && /cluster_nodes/.test(r.url()));
  await tr.locator(`button[name="cluster_action"][value="${action}"]`).first().click();
  expect((await posted).status(), action).toBeLessThan(400);
  await expect(page.locator('body')).not.toContainText(/Fatal error|Uncaught|Stack trace/);
}

/** Reload the page until the row satisfies `ok`, or fail after `ms`. */
async function until(page: Page, what: string, ok: (tr: Locator) => Promise<boolean>, ms = 120_000): Promise<void> {
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

const health = async (tr: Locator): Promise<string> => (await tr.locator('td').nth(1).locator('.badge').first().innerText()).trim();
const flowOn = async (tr: Locator, flow: string): Promise<boolean> => (await tr.locator(`button[value="${flow}_off"]`).count()) > 0;
const epochCell = (tr: Locator): Locator => tr.locator('td').filter({ hasText: /\(gen \d+\)/ }).first();
const epoch = async (tr: Locator): Promise<number> => Number((await epochCell(tr).innerText()).trim().split(/\s+/)[0]);
/** The node's command queue: the badge three cells after the epoch's. */
const queued = async (tr: Locator): Promise<number> => Number((await epochCell(tr).locator('xpath=following-sibling::td[3]').innerText()).trim());

/** Run `body` with the node's COMMANDS flow on, then put the flow back as it was. */
async function withCommands(page: Page, body: () => Promise<void>): Promise<void> {
  const wasOn = await flowOn(await row(page), 'commands');
  if (!wasOn) {
    await act(page, 'commands_on');
    await until(page, 'COMMANDS on', (tr) => flowOn(tr, 'commands'), 30_000);
  }
  try {
    await body();
  } finally {
    if (!wasOn) {
      await act(page, 'commands_off');
    }
  }
}

test.beforeEach(async ({ page }) => {
  page.on('dialog', (d) => d.accept()); // fence and quarantine ask first
});

test('the load balancer is active, heard and names its agent', async ({ page }) => {
  const tr = await row(page);
  expect(await health(tr), 'health').toBe('ok');
  await expect(tr).toContainText(/\d+\.\d+\.\d+/); // the agent's version
  expect(await epoch(tr)).toBeGreaterThan(0);
});

test('a flow switched on reaches the node and is switched off again', async ({ page }) => {
  test.setTimeout(120_000);
  const wasOn = await flowOn(await row(page), 'telemetry');
  await act(page, wasOn ? 'telemetry_off' : 'telemetry_on');
  await until(page, 'telemetry switched', async (tr) => (await flowOn(tr, 'telemetry')) !== wasOn, 30_000);
  // The node keeps heart-beating under the new flows.
  await page.waitForTimeout(6000);
  expect(await health(await row(page))).toBe('ok');
  await act(page, wasOn ? 'telemetry_on' : 'telemetry_off');
  await until(page, 'telemetry restored', async (tr) => (await flowOn(tr, 'telemetry')) === wasOn, 30_000);
});

test('rotate now gives the node a newer token', async ({ page }) => {
  test.setTimeout(240_000);
  await withCommands(page, async () => {
    const before = await epoch(await row(page));
    await act(page, 'rotate_now');
    await expect(page.locator('.alert-success')).toBeVisible();
    await until(page, `an epoch above ${before}`, async (tr) => (await epoch(tr)) > before, 150_000);
  });
});

test('a quarantine is lifted with trust', async ({ page }) => {
  test.setTimeout(240_000);
  await withCommands(page, async () => {
    await act(page, 'quarantine');
    try {
      await until(page, 'quarantined', async (tr) => (await health(tr)) === 'quarantined', 60_000);
    } finally {
      await act(page, 'trust');
    }
    await until(page, 'trusted again', async (tr) => (await health(tr)) === 'ok', 90_000);
  });
});

test('a fence and its lifting reach the node', async ({ page }) => {
  test.setTimeout(240_000);
  await withCommands(page, async () => {
    await act(page, 'fence');
    try {
      await expect(page.locator('.alert-success')).toBeVisible();
      await until(page, 'the fence acked', async (tr) => (await queued(tr)) === 0, 90_000);
    } finally {
      await act(page, 'unfence');
    }
    await until(page, 'the unfence acked', async (tr) => (await queued(tr)) === 0, 90_000);
    expect(await health(await row(page))).toBe('ok');
  });
});
