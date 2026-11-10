/** @type {import('tailwindcss').Config} */
export default {
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        night: { 0: "#0a0f1e", 1: "#101830", 2: "#18213f" },
        indigo: { line: "#243056" },
        gold: { DEFAULT: "#e3c069", hi: "#f4dd9a" },
        ivory: "#f2ede2",
        sky: { DEFAULT: "#9aa4c2", dim: "#6b7699" },
      },
      fontFamily: {
        display: ['"Cormorant Garamond"', "Georgia", "serif"],
        body: ['"Jost"', "system-ui", "sans-serif"],
      },
    },
  },
  plugins: [],
};
