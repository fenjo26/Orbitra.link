import js from '@eslint/js'
import globals from 'globals'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import { defineConfig, globalIgnores } from 'eslint/config'

export default defineConfig([
  globalIgnores(['dist']),
  {
    files: ['**/*.{js,jsx}'],
    extends: [
      js.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite,
    ],
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.browser,
      parserOptions: {
        ecmaVersion: 'latest',
        ecmaFeatures: { jsx: true },
        sourceType: 'module',
      },
    },
    rules: {
      // Capitalised names (React imports, components) may sit unused, in
      // variables and in parameters alike: an icon received as `({ icon: Icon })`
      // is "used" only as a JSX tag, which the core rule does not count.
      'no-unused-vars': ['error', { varsIgnorePattern: '^[A-Z_]', argsIgnorePattern: '^[A-Z_]' }],
      // Advice, not a defect: the panel loads its data and syncs form state in
      // effects throughout, and the rule cannot tell an async loader from a
      // synchronous setState. Kept visible as warnings; errors stay for what
      // can actually break rendering.
      'react-hooks/set-state-in-effect': 'warn',
    },
  },
])
