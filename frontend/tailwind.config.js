/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        "./index.html",
        "./src/**/*.{vue,js,ts,jsx,tsx}",
    ],
    theme: {
        extend: {
            fontFamily: {
                // Keep in sync with glass --font-sans (Poppins before system UI).
                sans: ['"Uniform"', 'Poppins', 'ui-sans-serif', 'system-ui', 'sans-serif'],
            },
            gridTemplateColumns: {
                '13': 'repeat(13, minmax(0, 1fr))',
            }
        },
    },
    plugins: [],
}
