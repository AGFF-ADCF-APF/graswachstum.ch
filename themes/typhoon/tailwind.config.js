const colors = require('tailwindcss/colors');
const path = require('path');
const process = require('process');
const dirname = process.env.PWD || process.cwd();
const normalize = (paths) => {
  return paths.map((_path) => path.normalize(`${dirname}/${_path}`));
}
module.exports = {
  content: normalize([
    '../../config/**/*.yaml',
    '../../pages/**/*.md',
    './blueprints/**/*.yaml',
    './js/**/*.js',
    './templates/**/*.twig',
    './typhoon.yaml',
    './typhoon.php',
    './available-classes.md',
    '../../plugins/sandbox-support/templates/**/*.twig',
  ]),
  theme: {
    extend: {
      inset: {
        '2': '0.5rem',
        'full': '100%',
        '1/2': '50%',
        '-1/2': '-50%',
        'inherit': 'inherit'
      },
      fontFamily: {
        sans: [
          'Inter var',
          '-apple-system',
          'BlinkMacSystemFont',
          '"Segoe UI"',
          'Roboto',
          '"Helvetica Neue"',
          'Arial',
          '"Noto Sans"',
          'sans-serif',
          '"Apple Color Emoji"',
          '"Segoe UI Emoji"',
          '"Segoe UI Symbol"',
          '"Noto Color Emoji"',
        ],
      },
      width: {
        'content': 'max-content',
      },
      maxHeight: {
        '0': '0',
      },
      lineHeight: {
        'tighter': '1.15',
      },
      strokeWidth: {
        '3/2': '1.5',
        '5/2': '2.5',
      },
      typography: (theme) => ({
        DEFAULT: {
          css: {
            color: 'inherit',
            lineHeight: 'inherit',
            maxWidth: 'inherit',
            a: {
              transition: 'all 500ms',
              color: theme('colors.primary.DEFAULT'),
              '&:hover': {
                color: theme('colors.primary.darker')
              },
              textDecoration: 'none'
            },
            strong: {
              color: 'inherit'
            },
            code: {
              backgroundColor: theme('colors.gray.100'),
              color: theme('colors.indigo.600'),
              fontWeight: 'inherit'
            },
            pre: {
              backgroundColor: theme('colors.blue.100'),
              backgroundOpacity: theme('opacity-50'),
              color: theme('colors.blue.800'),
            },
          }
        }
      }),
      colors: {
        gray: colors.zinc,
        transparent: 'transparent',
        inherit: 'inherit',
        primary: {
          DEFAULT: 'rgb(var(--color-primary) / <alpha-value>)',
          'lighter': 'rgb(var(--color-primary__lighter) / <alpha-value>)',
          'darker': 'rgb(var(--color-primary__darker) / <alpha-value>)',
        },
      },
    },
    columnCount: [ 1, 2, 3, 4 ],
  },
  plugins: [
    require('@tailwindcss/typography'),
    require('@tailwindcss/forms'),
    require('tailwindcss-multi-column')(),
    require('tailwindcss-debug-screens'),
  ],
  darkMode: 'class',
}
