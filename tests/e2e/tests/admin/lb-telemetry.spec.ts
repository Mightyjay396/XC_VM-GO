import { test, expect } from '@playwright/test';
import { health, lb, row, serverView, withFlow } from './cluster-support';

/**
 * Phase 3: a load balancer's telemetry comes from its agent (the TELEMETRY
 * flow). Its heartbeat carries the host's sample, which MAIN turns into the
 * server's watchdog data in the legacy shape the server page and the dashboard
 * read, kept fresh within seconds, with the node active all along.
 * Without XC_E2E_LB_SERVER the file is skipped.
 */

test.skip(!lb, 'XC_E2E_LB_SERVER (a load balancer enrolled in the cluster API) not set');

/** The legacy watchdog keys the server page and the dashboard read (SystemInfo::getStats). */
const LEGACY = ['cpu', 'cpu_cores', 'cpu_avg', 'total_mem', 'total_mem_used_percent', 'total_disk_space', 'free_disk_space', 'kernel', 'uptime', 'network_info', 'cpu_average_array'];

test('the server page reads the load balancer\'s telemetry, kept fresh by its agent', async ({ page }) => {
  test.setTimeout(180_000);
  await withFlow(page, 'telemetry', async () => {
    await expect.poll(async () => health(await row(page)), { timeout: 60_000 }).toBe('ok');

    const first = await serverView(page.request, lb);
    expect(first.watchdog, 'the server page has watchdog data').toBeTruthy();
    for (const key of LEGACY) {
      expect(first.watchdog, `watchdog_data.${key}`).toHaveProperty(key);
    }
    expect(Number(first.watchdog!.cpu_cores), 'cores').toBeGreaterThan(0);
    expect(Number(first.watchdog!.total_mem), 'memory').toBeGreaterThan(0);
    expect(String(first.watchdog!.kernel), 'kernel').not.toBe('');

    // Fresh: the uptime (to the second) moves on between two reads a few
    // seconds apart, each heartbeat (every 2 s, flushed at most every 5 s)
    // bringing a new sample.
    await expect
      .poll(async () => (await serverView(page.request, lb)).watchdog?.uptime, { timeout: 30_000, intervals: [3_000] })
      .not.toBe(first.watchdog!.uptime);

    // The server page itself renders it, with no PHP error.
    const resp = await page.goto(`./server_view?id=${lb}`);
    expect(resp?.status(), 'server_view').toBe(200);
    await expect(page.locator('#watchdog_cpu')).toBeVisible();
    await expect(page.locator('body')).not.toContainText(/Fatal error|Uncaught|Stack trace/);
  });
});
