import { test, expect } from '@playwright/test';
import { deleteListRow, listRow, submitForm, uniq } from './support';

/**
 * Who may do what, and how streams are transcoded: a reseller member group and
 * a transcoding profile — each created, reopened, edited and deleted through
 * the admin pages.
 */

const group = uniq('group');
const groupRenamed = uniq('group-renamed');
const profile = uniq('profile');
const profileRenamed = uniq('profile-renamed');

test.describe.serial('member groups and transcoding profiles', () => {
  test('create a reseller group', async ({ page }) => {
    await page.goto('./group');
    await page.locator('#group_name').fill(group);
    await page.locator('#is_reseller').check();
    await submitForm(page, page, 'group', page.locator('#group-submit'));

    await page.waitForURL(/groups/);
    await expect(listRow(page, group)).toHaveCount(1);
  });

  test('the group keeps its flags when reopened, and can be renamed', async ({ page }) => {
    await page.goto('./groups');
    await listRow(page, group).locator('a[href*="group?id="]').click();
    await expect(page.locator('#group_name')).toHaveValue(group);
    await expect(page.locator('#is_reseller')).toBeChecked();
    await expect(page.locator('#is_admin')).not.toBeChecked();

    await page.locator('#group_name').fill(groupRenamed);
    await submitForm(page, page, 'group', page.locator('#group-submit'));
    await page.waitForURL(/groups/);
    await expect(listRow(page, groupRenamed)).toHaveCount(1);
  });

  test('delete the group', async ({ page }) => {
    await page.goto('./groups');
    await deleteListRow(page, listRow(page, groupRenamed), /api\?action=group&sub=delete/);
    await page.reload();
    await expect(listRow(page, groupRenamed)).toHaveCount(0);
  });

  test('create a transcoding profile', async ({ page }) => {
    await page.goto('./profile');
    await page.locator('#profile_name').fill(profile);
    await submitForm(page, page, 'profile', page.locator('#submit_button'));

    await page.waitForURL(/profiles/);
    await expect(listRow(page, profile)).toHaveCount(1);
  });

  test('rename the profile', async ({ page }) => {
    await page.goto('./profiles');
    await listRow(page, profile).locator('a[href*="profile?id="]').click();
    await expect(page.locator('#profile_name')).toHaveValue(profile);
    await page.locator('#profile_name').fill(profileRenamed);
    await submitForm(page, page, 'profile', page.locator('#submit_button'));

    await page.waitForURL(/profiles/);
    await expect(listRow(page, profileRenamed)).toHaveCount(1);
  });

  test('delete the profile', async ({ page }) => {
    await page.goto('./profiles');
    await deleteListRow(page, listRow(page, profileRenamed), /api\?action=profile&sub=delete/);
    await page.reload();
    await expect(listRow(page, profileRenamed)).toHaveCount(0);
  });
});
