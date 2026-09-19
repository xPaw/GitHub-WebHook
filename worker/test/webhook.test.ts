import { sign } from '@octokit/webhooks-methods';
import { describe, expect, it } from 'vitest';
import { parseTarget, targetUrl } from '../src/discord/webhook.js';
import { BadRequestError } from '../src/errors.js';
import { verifySignature } from '../src/github.js';

const ID = '123456789012345678';
const THREAD = '987654321098765432';
const TOKEN = 'aBc-123_xYz';
const PATH = `/discordhook/${ID}/${TOKEN}`;

function parse(path: string) {
	return parseTarget(new URL(`https://example.workers.dev${path}`));
}

describe('parseTarget', () => {
	it('reads the id and the token from the path', () => {
		expect(parse(PATH)).toEqual({ id: ID, token: TOKEN });
	});

	it('reads the thread from the query', () => {
		expect(parse(`${PATH}?thread_id=${THREAD}`)).toEqual({ id: ID, token: TOKEN, threadId: THREAD });
	});

	it('ignores every other query parameter', () => {
		expect(parse(`${PATH}?wait=true&thread_name=x`)).toEqual({ id: ID, token: TOKEN });
	});

	it.each([
		['no path', '/'],
		['no prefix', `/${ID}/${TOKEN}`],
		['a different prefix', `/api/webhooks/${ID}/${TOKEN}`],
		['a prefix in another case', `/DiscordHook/${ID}/${TOKEN}`],
		['no token', `/discordhook/${ID}`],
		['an empty token', `/discordhook/${ID}/`],
		['a trailing slash', `${PATH}/`],
		['an extra segment', `${PATH}/github`],
		['a leading empty segment', `/${PATH}`],
		['a short id', `/discordhook/1234567890123456/${TOKEN}`],
		['a long id', `/discordhook/123456789012345678901/${TOKEN}`],
		['an id that is not a number', `/discordhook/12345678901234567a/${TOKEN}`],
		['an id with fullwidth digits', `/discordhook/${'１'.repeat(17)}/${TOKEN}`],
		['an encoded slash in the token', `/discordhook/${ID}/abc%2Fdef`],
		['a dot in the token', `/discordhook/${ID}/abc.def`],
		['an encoded parent directory as the token', `/discordhook/${ID}/%2e%2e`],
		['a space in the token', `/discordhook/${ID}/abc%20def`],
		['an empty thread', `${PATH}?thread_id=`],
		['a thread that is not a number', `${PATH}?thread_id=general`],
		['a short thread', `${PATH}?thread_id=123`],
	])('rejects %s', (_, path) => {
		expect(() => parse(path)).toThrow(BadRequestError);
	});
});

describe('targetUrl', () => {
	it('builds the url of the Discord webhook', () => {
		expect(targetUrl({ id: ID, token: TOKEN })).toBe(`https://discord.com/api/webhooks/${ID}/${TOKEN}`);
	});

	it('adds the thread', () => {
		expect(targetUrl({ id: ID, token: TOKEN, threadId: THREAD })).toBe(
			`https://discord.com/api/webhooks/${ID}/${TOKEN}?thread_id=${THREAD}`,
		);
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
