/** @type {import('tailwindcss').Config} */

/**
 * TP-HR brand palette, derived from the logo mark (#B79168 sits at 500).
 *
 * `violet` and `purple` are deliberately overridden rather than renamed. The
 * accent appears as violet-* / purple-* utilities in ~430 places across 49 PHP
 * files; remapping the scales here re-themes every one of them on the next
 * build, with no risk of a missed occurrence or a typo in a hand-edited class.
 *
 * `primary` is kept as an alias so either name works going forward.
 */
const brand = {
  50: '#f9f6f3',
  100: '#f3ede7',
  200: '#e8dccf',
  300: '#d8c4ad',
  400: '#c7a989',
  500: '#b79168',
  600: '#a3805b',
  700: '#8c6d4d',
  800: '#745a3f',
  900: '#5d4730',
};

module.exports = {
  content: [
    './**/*.php',
    '!./_work/**',
    '!./node_modules/**',
    '!./vendor/**',
  ],
  theme: {
    extend: {
      colors: {
        primary: brand,
        brand: brand,
        violet: brand,
        purple: brand,
      }
    }
  },
  plugins: [],
}
