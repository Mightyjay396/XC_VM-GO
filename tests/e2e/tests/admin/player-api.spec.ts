import { test, expect, type APIRequestContext } from '@playwright/test';
import { TAG, adminApi, ident, listRow, submitForm, tableRows, uniq } from './support';

/**
 * The Xtream Codes player API an IPTV app talks to: a line signs in and walks
 * every action. It once answered every call but the three stream lists with a
 * TypeError page (a null bouquet list reached a non-nullable parameter), so no
 * app could sign in — each action here must answer JSON.
 *
 * The line gets a bouquet holding one live channel in its own category, so the
 * listings have something to list. The channel is never started: the API lists
 * what a line may watch, not what is running.
 *
 * player_api.php lives at the web root of the panel's port, beside the admin
 * access-code path.
 */

const SOURCE = process.env.XC_E2E_STREAM_SOURCE || 'https://demo.unified-streaming.com/k8s/live/stable/scte35.isml/.m3u8';

const category = uniq('api-category');
const bouquet = uniq('api-bouquet');
const channel = uniq('api-channel');
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

let channelID = 0;
let categoryID = '';

test.describe.serial('player API', () => {
  test('create a category, a bouquet, a channel in both, and a line', async ({ page }) => {
    await page.goto('./stream_category');
    await page.locator('#category_name').fill(category);
    await submitForm(page, page, 'stream_category');
    await page.waitForURL(/stream_categories/);

    await page.goto('./bouquet');
    await page.locator('#bouquet_name').fill(bouquet);
    await submitForm(page, page, 'bouquet');
    await page.waitForURL(/bouquets/);

    await page.goto('./stream');
    await page.locator('#stream_display_name').fill(channel);
    await page.locator('#notes').fill(TAG);
    await page.locator('#category_id').selectOption({ label: category }, { force: true });
    await page.locator('#bouquets').selectOption({ label: bouquet }, { force: true });
    await page.getByRole('tab', { name: /sources/i }).click();
    await page.locator('input[name="stream_source[]"]').first().fill(SOURCE);
    await submitForm(page, page, 'stream', page.locator('#stream-submit'));
    await page.waitForURL(/\/(stream_view\?id=\d+|streams)/, { waitUntil: 'commit' });
    const row = (await tableRows(page.request, 'streams', channel)).find((r) => r.title === channel);
    expect(row, 'the channel is listed').toBeTruthy();
    channelID = Number(row.id);

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
    expect(body.user_info).toMatchObject({ username, password, auth: 1, status: 'Active', active_cons: '0' });
    expect(body.server_info?.timestamp_now).toEqual(expect.any(Number));

    // Some apps use the path form nginx rewrites to the same endpoint.
    const pathForm = await request.get(new URL(`/player_api/${username}/${password}`, apiURL).toString());
    expect((await pathForm.json()).user_info?.auth).toBe(1);
    const pathAction = await request.get(new URL(`/player_api/${username}/${password}/get_live_categories`, apiURL).toString());
    expect(Array.isArray(await pathAction.json())).toBe(true);
  });

  test('the channel is listed under its category', async ({ request }) => {
    // The stream and bouquet caches pick new content up on their next pass.
    test.setTimeout(420_000);
    await expect
      .poll(async () => (await playerApi(request, { action: 'get_live_streams' })).map((s: any) => s.stream_id), {
        timeout: 200_000,
        intervals: [5_000, 10_000],
        message: `${channel} never appeared in get_live_streams`,
      })
      .toContain(channelID);

    // The category follows once the heavy cache pass has rebuilt the bouquet →
    // category map, which can be a pass later than the stream itself.
    await expect
      .poll(async () => (await playerApi(request, { action: 'get_live_categories' })).find((c: any) => c.category_name === category)?.category_id, {
        timeout: 200_000,
        intervals: [5_000, 10_000],
        message: `${category} never appeared in get_live_categories`,
      })
      .toBeTruthy();
    const categories = await playerApi(request, { action: 'get_live_categories' });
    categoryID = categories.find((c: any) => c.category_name === category).category_id;

    const inCategory = await playerApi(request, { action: 'get_live_streams', category_id: categoryID });
    expect(inCategory).toEqual([expect.objectContaining({ stream_id: channelID, name: channel, stream_type: 'live', category_id: categoryID })]);
  });

  test('every other action answers JSON', async ({ request }) => {
    for (const action of ['get_vod_categories', 'get_series_categories', 'get_vod_streams', 'get_series']) {
      expect(Array.isArray(await playerApi(request, { action })), action).toBe(true);
    }
    const id = String(channelID);
    expect(await playerApi(request, { action: 'get_short_epg', stream_id: id })).toEqual({ epg_listings: expect.anything() });
    expect(await playerApi(request, { action: 'get_simple_data_table', stream_id: id })).toEqual({ epg_listings: expect.anything() });
    expect(await playerApi(request, { action: 'get_short_epg', stream_id: `${id},${id}`, limit: '2' })).toEqual({ epg_listings: expect.anything() });
  });

  test('info actions answer only for the line\'s own movies and series', async ({ request }) => {
    // The bouquet holds no movie or series; a live channel id is not a movie.
    expect(await playerApi(request, { action: 'get_vod_info', vod_id: String(channelID) })).toEqual({ info: [] });
    const movies = await tableRows(request, 'movies', '');
    if (movies[0]?.id) {
      expect(await playerApi(request, { action: 'get_vod_info', vod_id: String(movies[0].id) })).toEqual({ info: [] });
    }
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

  test('delete the line, channel, bouquet and category', async ({ page }) => {
    const [line] = (await tableRows(page.request, 'lines', username)).filter((r) => r.username === username);
    expect((await adminApi(page.request, 'line', { sub: 'delete', user_id: line.id }))?.result).toBe(true);

    const stream = (await tableRows(page.request, 'streams', channel)).find((r) => r.title === channel);
    expect((await adminApi(page.request, 'stream', { sub: 'delete', stream_id: stream.id, server_id: stream.server_col_id ?? '' }))?.result).toBe(true);

    await page.goto('./bouquets');
    const bouquetID = await listRow(page, bouquet).locator('.js-del').getAttribute('data-id');
    expect((await adminApi(page.request, 'bouquet', { sub: 'delete', bouquet_id: bouquetID! }))?.result).toBe(true);

    await page.goto('./stream_categories');
    const categoryRowID = await listRow(page, category).locator('.js-del').getAttribute('data-id');
    expect((await adminApi(page.request, 'category', { sub: 'delete', category_id: categoryRowID! }))?.result).toBe(true);
  });
});
