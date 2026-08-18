import parser from '@typescript-eslint/parser';

export default [{ files: ['resources/js/**/*.ts'], languageOptions: { parser, parserOptions: { ecmaVersion: 'latest', sourceType: 'module' } }, rules: { semi: ['error', 'always'], quotes: ['error', 'single'] } }];
