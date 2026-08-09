/** @type {import('tailwindcss').Config} */

/**
 * TP-HR brand palette, derived from the logo mark (#B79168 sits at 500).
 *
 * `violet` and `purple` are overridden rather than renamed, because the accent
 * appears as violet-* / purple-* utilities in ~430 places across 49 PHP files.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  DO NOT rebuild assets/css/app.css from this config without checking the
 *  output. Rebuilding on 2026-08-09 produced a file that was MISSING 98 of
 *  the selectors the shipped build had — including grid-cols-5, gap-8, ml-4
 *  and w-auto, which PHP files demonstrably use — and it visibly broke the
 *  desktop layout in production. The content scan is not picking up
 *  everything the shipped CSS was built from, and that is unresolved.
 *
 *  To change brand colours, run `php scripts/retint_app_css.php
 *  assets/css/app.css` instead. It substitutes colour values inside the
 *  known-good build and leaves every selector byte-for-byte identical.
 *
 *  If you do need a real rebuild, diff the selector list against the previous
 *  file first and confirm nothing disappeared.
 * ─────────────────────────────────────────────────────────────────────────
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
