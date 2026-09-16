import { test, expect } from '@playwright/test';
import { deleteListRow, listRow, submitForm, uniq } from './support';

/**
 * The catalogue an administrator builds before selling anything: a stream
 * category, a bouquet, and a reseller package that grants the bouquet — each
 * created, edited and deleted through the admin pages.
 */

const category = uniq('category');
const categoryRenamed = uniq('category-renamed');
const bouquet = uniq('bouquet');
const bouquetRenamed = uniq('bouquet-renamed');
const pkg = uniq('package');

test.describe.serial('catalogue: categories, bouquets, packages', () => {
  test('create a live stream category', async ({ page }) => {
    await page.goto('./stream_category');
    await expect(page.locator('#category_type')).toHaveValue('live');
    await page.locator('#category_name').fill(category);
    await submitForm(page, page, 'stream_category');

    await page.waitForURL(/stream_categories/);
    await expect(listRow(page, category)).toHaveCount(1);
  });

  test('rename the category', async ({ page }) => {
    await page.goto('./stream_categories');
    await listRow(page, category).locator('a[href*="stream_category?id="]').click();
    await expect(page.locator('#category_name')).toHaveValue(category);
    await page.locator('#category_name').fill(categoryRenamed);
    await submitForm(page, page, 'stream_category');

    await page.waitForURL(/stream_categories/);
    await expect(listRow(page, categoryRenamed)).toHaveCount(1);
    await expect(listRow(page, category).filter({ hasNotText: categoryRenamed })).toHaveCount(0);
  });

  test('create a bouquet', async ({ page }) => {
    await page.goto('./bouquet');
    await page.locator('#bouquet_name').fill(bouquet);
    await submitForm(page, page, 'bouquet');

    await page.waitForURL(/bouquets/);
    await expect(listRow(page, bouquet)).toHaveCount(1);
  });

  test('rename the bouquet', async ({ page }) => {
    await page.goto('./bouquets');
    await listRow(page, bouquet).locator('a[href*="bouquet?id="]').click();
    await expect(page.locator('#bouquet_name')).toHaveValue(bouquet);
    await page.locator('#bouquet_name').fill(bouquetRenamed);
    await submitForm(page, page, 'bouquet');

    await page.waitForURL(/bouquets/);
    await expect(listRow(page, bouquetRenamed)).toHaveCount(1);
  });

  test('create a reseller package granting the bouquet', async ({ page }) => {
    await page.goto('./package');
    await page.locator('#package_name').fill(pkg);
    await page.locator('#is_official').check();
    await page.locator('#official_credits').fill('1');
    await page.locator('#official_duration').fill('1');
    await page.locator('#official_duration_in').selectOption('months');

    await page.getByRole('tab', { name: /groups/i }).click();
    await page.locator('#tab-groups').getByLabel('Resellers', { exact: true }).check();
    await page.getByRole('tab', { name: /bouquets/i }).click();
    await page.locator('#tab-bouquets').getByLabel(bouquetRenamed, { exact: true }).check();

    await submitForm(page, page, 'package', page.locator('#package-submit'));
    await page.waitForURL(/packages/);
    await expect(listRow(page, pkg)).toHaveCount(1);
  });

  test('the package keeps its bouquet, group and settings when reopened', async ({ page }) => {
    await page.goto('./packages');
    await listRow(page, pkg).locator('a[href*="package?id="]').click();
    await expect(page.locator('#package_name')).toHaveValue(pkg);
    await expect(page.locator('#is_official')).toBeChecked();
    await expect(page.locator('#official_duration_in')).toHaveValue('months');
    await expect(page.locator('#tab-groups').getByLabel('Resellers', { exact: true })).toBeChecked();
    await expect(page.locator('#tab-bouquets').getByLabel(bouquetRenamed, { exact: true })).toBeChecked();

    // Edit: two connections instead of one.
    await page.getByRole('tab', { name: /options/i }).click();
    await page.locator('#max_connections').fill('2');
    await submitForm(page, page, 'package', page.locator('#package-submit'));
    await page.waitForURL(/packages/);

    await listRow(page, pkg).locator('a[href*="package?id="]').click();
    await expect(page.locator('#max_connections')).toHaveValue('2');
  });

  test('delete the package, bouquet and category', async ({ page }) => {
    await page.goto('./packages');
    await deleteListRow(page, listRow(page, pkg), /api\?action=package&sub=delete/);
    await page.reload();
    await expect(listRow(page, pkg)).toHaveCount(0);

    await page.goto('./bouquets');
    await deleteListRow(page, listRow(page, bouquetRenamed), /api\?action=bouquet&sub=delete/);
    await page.reload();
    await expect(listRow(page, bouquetRenamed)).toHaveCount(0);

    await page.goto('./stream_categories');
    await deleteListRow(page, listRow(page, categoryRenamed), /api\?action=category&sub=delete/);
    await page.reload();
    await expect(listRow(page, categoryRenamed)).toHaveCount(0);
  });
});
