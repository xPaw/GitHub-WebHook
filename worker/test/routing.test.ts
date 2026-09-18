import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { sign } from '@octokit/webhooks-methods';
import { describe, expect, it } from 'vitest';
import { verifySignature } from '../src/github.js';
import { matchPatterns, parseRouteConfig, wildcard } from '../src/routing.js';

describe('wildcard', () => {
	it.each([
		['xPaw/GitHub-WebHook', 'xPaw/GitHub-WebHook', true],
		['xPaw/GitHub-WebHook', 'xpaw/github-webhook', false],
		['xPaw/GitHub-WebHook', 'xPaw/GitHub', false],
		['xPaw/GitHub-WebHook', 'xPaw/*', true],
		['xPaw/GitHub-WebHook', '*', true],
		['xPaw/GitHub-WebHook', '*/GitHub-*', true],
		['xPaw/GitHub-WebHook', 'xPaw/*Hook', true],
		['xPaw/GitHub-WebHook', 'xPaw/*Hooks', false],
		['NotxPaw/GitHub-WebHook', 'xPaw/*', false],
		['xPaw/', 'xPaw/*', true],
		// Everything other than "*" is literal
		['xPaw/aXb', 'xPaw/a.b*', false],
		['xPaw/a.b', 'xPaw/a.b*', true],
		['xPaw/repo', 'xPaw/(repo|other)*', false],
		['xPaw/a+b', 'xPaw/a+*', true],
	])('%s against %s is %s', (value, pattern, expected) => {
		expect(wildcard(value, pattern)).toBe(expected);
	});
});

describe('verifySignature', () => {
	it('verifies a signature made with the same secret', async () => {
		const signature = await sign('secret', '{}');

		expect(await verifySignature('secret', '{}', signature)).toBe(true);
		expect(await verifySignature('other', '{}', signature)).toBe(false);
		expect(await verifySignature('secret', '{ }', signature)).toBe(false);
	});

	it.each(['sha256=', 'sha1=abc', 'garbage'])('rejects the signature %j', async (signature) => {
		expect(await verifySignature('secret', '{}', signature)).toBe(false);
	});

	it('rejects instead of throwing when there is nothing to verify', async () => {
		expect(await verifySignature('secret', '', await sign('secret', '{}'))).toBe(false);
	});
});

describe('repositories.json.example', () => {
	it('is a valid config', () => {
		const example = readFileSync(join(import.meta.dirname, '..', 'repositories.json.example'), 'utf8');

		expect(Object.keys(parseRouteConfig(example))).not.toHaveLength(0);
	});
});

describe('matchPatterns', () => {
	it('returns every matching pattern in config order', () => {
		const route = { secret: 's', webhooks: [] };
		const config = { 'SteamDatabase/*': route, 'xPaw/*': route, 'xPaw/GitHub-WebHook': route, '*': route };

		expect(matchPatterns(config, 'xPaw/GitHub-WebHook')).toEqual(['xPaw/*', 'xPaw/GitHub-WebHook', '*']);
		expect(matchPatterns(config, 'Other/Repo')).toEqual(['*']);
		expect(matchPatterns({}, 'xPaw/GitHub-WebHook')).toEqual([]);
	});
});
