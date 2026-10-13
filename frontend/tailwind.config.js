/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          DEFAULT: '#425B9A',
          50: '#eef1f8',
          100: '#dce3f0',
          200: '#b9c6e1',
          300: '#93a8d2',
          400: '#6b87be',
          500: '#425B9A',
          600: '#354a80',
          700: '#2a3a66',
          800: '#1f2b4d',
          900: '#171f38',
          950: '#0f162b',
        },
        skyblue: '#76C0EC',
        cream: '#FFF6DC',
        blush: '#FF95A5',
      },
    },
  },
  plugins: [],
};