import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow } from './support/helpers';

test.describe('public surfaces', () => {
    test('landing page renders its purpose and account actions', async ({ page }) => {
        await page.goto('/');

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: /beautiful digital invitations, guests and rsvps in one place/i,
            }),
        ).toBeVisible();
        await expect(page.getByRole('link', { name: /create account/i }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: /^sign in$/i }).first()).toBeVisible();
        await expectNoHorizontalOverflow(page, 'landing page');
    });

    test('login page exposes usable labelled form controls', async ({ page }) => {
        await page.goto('/login');

        await expect(page.getByRole('heading', { name: 'Sign in', level: 1 })).toBeVisible();

        const email = page.getByLabel('Email');
        const password = page.getByLabel('Password');
        const submit = page.getByRole('button', { name: 'Sign in' });

        await expect(email).toBeVisible();
        await expect(password).toBeVisible();
        await expect(submit).toBeEnabled();

        await email.fill('keyboard@example.test');
        await password.fill('not-a-real-password');
        await expect(email).toHaveValue('keyboard@example.test');
        await expect(password).toHaveValue('not-a-real-password');
        await expectNoHorizontalOverflow(page, 'login page');
    });
});
