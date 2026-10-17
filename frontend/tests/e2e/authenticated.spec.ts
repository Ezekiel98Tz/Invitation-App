import { expect, test } from '@playwright/test';
import { testEventTitle } from './support/environment';
import {
    activeElementOutline,
    expectNoHorizontalOverflow,
    signInAsTestUser,
} from './support/helpers';

test.describe('authenticated workspace', () => {
    test('workspace navigation exposes every existing destination and the active page', async ({ page }) => {
        await signInAsTestUser(page);

        const workspace = page.getByRole('navigation', { name: 'Workspace' });
        const events = workspace.getByRole('link', { name: 'Events', exact: true });

        await expect(events).toHaveAttribute('aria-current', 'page');
        await expect(events).toHaveClass(/is-active/);
        await expect(workspace.getByRole('link', { name: 'Celebrations', exact: true })).toBeVisible();
        await expect(workspace.getByRole('link', { name: 'Contacts', exact: true })).toBeVisible();
        await expect(workspace.getByRole('link', { name: 'Delivery Lab', exact: true })).toBeVisible();

        await expect(page.getByRole('navigation', { name: 'Account' }).getByRole('link', { name: 'Profile' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible();
    });

    test('event context shows the event name and the correct operational destinations', async ({ page }) => {
        await signInAsTestUser(page);

        await page.getByRole('link', { name: testEventTitle, exact: true }).click();
        await expect(page).toHaveURL(/\/events\/\d+$/);

        const breadcrumb = page.getByRole('navigation', { name: 'Breadcrumb' });
        await expect(breadcrumb.getByRole('link', { name: 'Events', exact: true })).toBeVisible();
        await expect(breadcrumb.getByRole('link', { name: testEventTitle, exact: true })).toBeVisible();
        await expect(breadcrumb.getByText('Guests', { exact: true })).toHaveAttribute('aria-current', 'page');

        const eventNavigation = page.getByRole('navigation', {
            name: `${testEventTitle} operations`,
        });

        for (const label of ['Guests', 'Invitations', 'Deliveries', 'Invitation design', 'Check-in', 'Pledges']) {
            await expect(eventNavigation.getByRole('link', { name: label, exact: true })).toBeVisible();
        }

        await eventNavigation.getByRole('link', { name: 'Invitations', exact: true }).click();
        await expect(page).toHaveURL(/#invitations$/);
        await expect(page.getByRole('tab', { name: 'Invitations', exact: true })).toHaveAttribute('aria-selected', 'true');
        await expect(
            eventNavigation.getByRole('link', { name: 'Invitations', exact: true }),
        ).toHaveAttribute('aria-current', 'page');
    });

    test('mobile navigation opens, closes with Escape, and closes after selection', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await signInAsTestUser(page);

        const openNavigation = page.getByRole('button', { name: 'Open navigation' });
        await expect(openNavigation).toBeVisible();
        await openNavigation.click();

        const closeMenu = page.getByRole('button', { name: 'Close menu' });
        await expect(closeMenu).toBeVisible();
        await expect(closeMenu).toBeFocused();

        await page.keyboard.press('Escape');
        await expect(closeMenu).toBeHidden();
        await expect(openNavigation).toBeVisible();
        await expect(openNavigation).toBeFocused();

        await openNavigation.click();
        await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', {
            name: 'Delivery Lab',
            exact: true,
        }).click();
        await expect(page).toHaveURL(/\/delivery-lab$/);
        await expect(openNavigation).toBeVisible();
        await expect(closeMenu).toBeHidden();
    });

    test('desktop and mobile pages do not overflow horizontally', async ({ page }) => {
        for (const viewport of [
            { width: 1440, height: 900 },
            { width: 390, height: 844 },
        ]) {
            await page.setViewportSize(viewport);
            await page.goto('/');
            await expectNoHorizontalOverflow(page, `landing page at ${viewport.width}px`);

            await page.goto('/login');
            await expectNoHorizontalOverflow(page, `login page at ${viewport.width}px`);
        }

        await page.setViewportSize({ width: 1440, height: 900 });
        await signInAsTestUser(page);
        await expectNoHorizontalOverflow(page, 'workspace at 1440px');

        await page.getByRole('link', { name: testEventTitle, exact: true }).click();
        await expect(page).toHaveURL(/\/events\/\d+$/);
        await expectNoHorizontalOverflow(page, 'event page at 1440px');

        await page.setViewportSize({ width: 390, height: 844 });
        await expectNoHorizontalOverflow(page, 'event page at 390px');
    });

    test('keyboard focus is visible and active navigation is exposed semantically', async ({ page }) => {
        await signInAsTestUser(page);

        await page.keyboard.press('Tab');
        const skipLink = page.getByRole('link', { name: 'Skip to main content' });
        await expect(skipLink).toBeFocused();
        await expect.poll(() => activeElementOutline(page)).not.toContain('none');

        const events = page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', {
            name: 'Events',
            exact: true,
        });
        await expect(events).toHaveAttribute('aria-current', 'page');
        await expect(events).toHaveClass(/is-active/);
    });
});
