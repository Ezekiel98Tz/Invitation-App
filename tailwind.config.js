import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                studio: {
                    canvas: 'var(--color-canvas)',
                    surface: 'var(--color-surface)',
                    subtle: 'var(--color-surface-subtle)',
                    text: 'var(--color-text)',
                    muted: 'var(--color-muted)',
                    border: 'var(--color-border)',
                    accent: 'var(--color-accent)',
                    'accent-hover': 'var(--color-accent-hover)',
                    'accent-soft': 'var(--color-accent-soft)',
                    focus: 'var(--color-focus)',
                    disabled: 'var(--color-disabled)',
                    'disabled-surface': 'var(--color-disabled-surface)',
                    success: 'var(--color-success)',
                    'success-soft': 'var(--color-success-soft)',
                    warning: 'var(--color-warning)',
                    'warning-soft': 'var(--color-warning-soft)',
                    danger: 'var(--color-danger)',
                    'danger-hover': 'var(--color-danger-hover)',
                    'danger-soft': 'var(--color-danger-soft)',
                    info: 'var(--color-info)',
                    'info-soft': 'var(--color-info-soft)',
                },
            },
        },
    },

    plugins: [forms],
};
