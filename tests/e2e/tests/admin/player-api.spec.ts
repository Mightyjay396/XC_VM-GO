import { test, expect, type APIRequestContext } from '@playwright/test';
import { TAG, adminApi, ident, listRow, submitForm, tableRows, uniq } from './support';

/**
 * The Xtream Codes player API an IPTV app talks to: a line signs in and walks
 * every action. It once answered every call but the three stream lists with a
 * TypeError page (a null bouquet list reached a non-nullable parameter), so no
 * app could sign in — each action here must answer JSON.
 *
 * player_api.php lives at the web root of the panel's port, beside the admin
 * access-code path.
 */

const bouquet = uniq('api-bouquet');
const username = ident('apiline');
const password = ident('apipass');

const apiURL = new URL('/player_api.php', process.env.XC_E2E_BASE_URL || 'http://localhost/').toString();

/** Call player_api.php as the line and return the parsed JSON answer. */
async function playerApi(request: APIRequestContext, params: Record<string, string> = {}, creds = { username, password }): Promise<any> {
  const query = new URLSearchParams({ ...creds, ...params }).toString();
  const resp = await request.get(`${apiURL}?${query}`);
  expect(resp.status(), `player_api ${query}`).toBe(200);
  const text = await resp.text();
  let body: any;
  try {
    body = JSON.parse(text);
  } catch {
    const plain = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    throw new Error(`player_api ${query} answered non-JSON: ${plain.slice(0, 800)}`);
  }
  return body;
}

test.describe.serial('player API', () => {
  test('create a line with a bouquet', async ({ page }) => {
    await page.goto('./bouquet');
    await page.locator('#bouquet_name').fill(bouquet);
    await submitForm(page, page, 'bouquet');
    await page.waitForURL(/bouquets/);

    await page.goto('./line');
    await page.locator('#username').fill(username);
    await page.locator('#password').fill(password);
    await page.locator('#admin_notes').fill(TAG);
    await page.getByRole('tab', { name: /bouquets/i }).click();
    await page.locator('#tab-bouquets').getByLabel(bouquet, { exact: true }).check();
    await submitForm(page, page, 'line', page.locator('#line-submit'));
    await page.waitForURL(/lines/);
  });

  test('the line signs in and gets its account and server info', async ({ request }) => {
    // With the line cache on, a new line is served once the cache has it.
    await expect
      .poll(async () => (await playerApi(request)).user_info?.auth, { timeout: 90_000, intervals: [2_000, 5_000] })
      .toBe(1);
    const body = await playerApi(request);
    expect(body.user_info).toMatchObject({ username, password, auth: 1, status: 'Active' });
    expect(body.server_info?.timestamp_now).toEqual(expect.any(Number));
  });

  test('every action answers JSON', async ({ request }) => {
    for (const action of ['get_live_categories', 'get_vod_categories', 'get_series_categories', 'get_live_streams', 'get_vod_streams', 'get_series']) {
      expect(Array.isArray(await playerApi(request, { action })), action).toBe(true);
    }
    expect(await playerApi(request, { action: 'get_short_epg', stream_id: '1' })).toEqual({ epg_listings: expect.anything() });
    expect(await playerApi(request, { action: 'get_simple_data_table', stream_id: '1' })).toEqual({ epg_listings: expect.anything() });
    expect(await playerApi(request, { action: 'get_short_epg', stream_id: '1,2', limit: '2' })).toEqual({ epg_listings: expect.anything() });
  });

  test('info actions answer only for the line\'s own content', async ({ request }) => {
    // The bouquet is empty: no movie or series belongs to the line.
    const movies = await tableRows(request, 'movies', '');
    const vodID = String(movies[0]?.id ?? 1);
    expect(await playerApi(request, { action: 'get_vod_info', vod_id: vodID })).toEqual({ info: [] });
    expect(await playerApi(request, { action: 'get_series_info', series_id: '1' })).toEqual([]);
  });

  test('malformed parameters are ignored, not fatal', async ({ request }) => {
    const signIn = await playerApi(request, { 'action[]': 'get_live_streams' });
    expect(signIn.user_info?.auth).toBe(1);
    expect(await playerApi(request, { action: 'get_short_epg', 'stream_id[]': '1' })).toEqual({ epg_listings: [] });
  });

  test('a wrong password is refused with JSON', async ({ request }) => {
    const body = await playerApi(request, {}, { username, password: `wrong-${password}` });
    expect(body.user_info?.auth).toBe(0);
  });

  test('delete the line and the bouquet', async ({ page }) => {
    const [line] = (await tableRows(page.request, 'lines', username)).filter((r) => r.username === username);
    expect((await adminApi(page.request, 'line', { sub: 'delete', user_id: line.id }))?.result).toBe(true);

    await page.goto('./bouquets');
    const id = await listRow(page, bouquet).locator('.js-del').getAttribute('data-id');
    expect((await adminApi(page.request, 'bouquet', { sub: 'delete', bouquet_id: id! }))?.result).toBe(true);
  });
});
