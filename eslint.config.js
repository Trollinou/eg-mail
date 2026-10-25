const js = require( '@eslint/js' );
const tseslint = require( 'typescript-eslint' );
const globals = require( 'globals' );

module.exports = tseslint.config(
	{
		ignores: [
			'node_modules/**',
			'vendor/**',
			'build/**',
			'dist/**',
			'assets/js/**',
			'assets/css/**',
			'scripts/**',
			'jules-scratch/**',
		],
	},
	js.configs.recommended,
	...tseslint.configs.strict,
	...tseslint.configs.stylistic,
	{
		files: [ 'src/**/*.{ts,tsx}' ],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'module',
			globals: {
				...globals.browser,
				wp: 'readonly',
				ajaxurl: 'readonly',
			},
		},
		rules: {
			// =================================================================
			// 1. QUALITÉ & TYPAGE STRICT TYPESCRIPT (Zéro tolérance)
			// =================================================================
			'eqeqeq': [ 'error', 'smart' ],
			'curly': [ 'error', 'all' ],
			'prefer-const': 'error',
			'no-var': 'error',
			'no-shadow': 'off',
			'@typescript-eslint/no-shadow': 'error',
			'@typescript-eslint/no-explicit-any': 'error',
			'@typescript-eslint/ban-ts-comment': [
				'error',
				{
					'ts-expect-error': 'allow-with-description',
					'ts-ignore': true,
					'ts-nocheck': true,
				},
			],
			'@typescript-eslint/consistent-type-imports': [
				'error',
				{ prefer: 'type-imports' },
			],
			'@typescript-eslint/no-unused-vars': [
				'error',
				{
					argsIgnorePattern: '^_',
					varsIgnorePattern: '^_',
					caughtErrorsIgnorePattern: '^_',
				},
			],
			'no-console': [ 'warn', { allow: [ 'warn', 'error' ] } ],

			// =================================================================
			// 2. ADAPTATIONS WORDPRESS
			// =================================================================
			'camelcase': 'off', // Autorise les propriétés snake_case issues de PHP/REST
			'no-alert': 'off',  // Autorise window.confirm() / window.alert() en admin WP
		},
	}
);