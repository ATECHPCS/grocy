const { defineConfig, devices } = require('@playwright/test');

const mobileViewport = { width: 390, height: 844 };

module.exports = defineConfig({
	testDir: './specs',
	workers: 1,
	retries: 0,
	reporter: 'line',
	outputDir: './node_modules/.playwright-output',
	use: {
		baseURL: 'http://127.0.0.1:4173',
		trace: 'on-first-retry'
	},
	projects: [
		{
			name: 'chromium-mobile',
			use: {
				...devices['iPhone 13'],
				viewport: mobileViewport
			}
		},
		{
			name: 'webkit-mobile',
			use: {
				...devices['iPhone 13'],
				viewport: mobileViewport,
				browserName: 'webkit'
			}
		}
	],
	webServer: {
		command: 'node support/server.mjs',
		url: 'http://127.0.0.1:4173/health',
		reuseExistingServer: false,
		timeout: 10_000
	}
});
