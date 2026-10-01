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
                sans: ['Tahoma', 'Arial', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: { primary: '#164d3b', hover: '#103c2e', accent: '#e88a26', canvas: '#faf8f2' },
            },
        },
    },

    plugins: [forms],
};
