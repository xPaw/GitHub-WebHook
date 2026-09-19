import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		restoreMocks: true,
		coverage: {
			include: ['src/**'],
			reporter: ['text', 'html'],
			thresholds: { 100: true },
		},
	},
});
