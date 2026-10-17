import { expect, type Page } from '@playwright/test';
import { emptyTestUser, testUser } from './environment';

export async function signInAs(page: Page, user = testUser): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(user.email);
    await page.getByLabel('Password').fill(user.password);
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { name: 'Events', level: 1 })).toBeVisible();
}

export async function signInAsTestUser(page: Page): Promise<void> {
    await signInAs(page, testUser);
}

export async function signInAsEmptyUser(page: Page): Promise<void> {
    await signInAs(page, emptyTestUser);
}

export async function expectNoHorizontalOverflow(page: Page, label: string): Promise<void> {
    const dimensions = await page.evaluate(() => ({
        clientWidth: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
    }));

    expect(dimensions.scrollWidth, `${label} should not overflow horizontally`).toBeLessThanOrEqual(
        dimensions.clientWidth + 1,
    );
}

export async function activeElementOutline(page: Page): Promise<string> {
    return page.evaluate(() => {
        const active = document.activeElement;
        if (!(active instanceof HTMLElement)) return '';

        const style = window.getComputedStyle(active);
        return `${style.outlineStyle}|${style.outlineWidth}|${style.outlineColor}`;
    });
}
