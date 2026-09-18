import { sign } from '@octokit/webhooks-methods';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import worker from '../src/index.js';
import { readFixture as fixture } from './fixtures.js';

const EXACT_SECRET = 'exact secret';
const WILDCARD_SECRET = 'wildcard secret';
const ORG_SECRET = 'org secret';
const EXACT_HOOK = 'https://discord.com/api/webhooks/1/exact';
const WILDCARD_HOOK = 'https://discord.com/api/webhooks/2/wildcard';
const ORG_HOOK = 'https://discord.com/api/webhooks/3/org';

const env: Env = {
	REPOSITORIES: JSON.stringify({
		'xPaw/GitHub-WebHook': { secret: EXACT_SECRET, webhooks: [EXACT_HOOK] },
		'xPaw/*': { secret: WILDCARD_SECRET, webhooks: [WILDCARD_HOOK] },
		'SteamDatabase/repositories': { secret: ORG_SECRET, webhooks: [ORG_HOOK] },
	}),
};

async function buildRequest(
	eventType: string,
	body: string,
	options: { contentType?: string; secret?: string; signature?: string | null; method?: string } = {},
): Promise<Request> {
	const headers = new Headers({
		'X-GitHub-Event': eventType,
		'Content-Type': options.contentType ?? 'application/json',
	});

	const signature = options.signature === undefined ? await sign(options.secret ?? EXACT_SECRET, body) : options.signature;

	if (signature !== null) {
		headers.set('X-Hub-Signature-256', signature);
	}

	return new Request('https://example.workers.dev/', { method: options.method ?? 'POST', headers, body });
}

let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
	fetchMock = vi.fn(async () => new Response(null, { status: 204 }));
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	vi.unstubAllGlobals();
});

describe('worker', () => {
	it('sends the converted embed to the pattern whose secret signed the request', async () => {
		const body = fixture('push');
		const response = await worker.fetch(await buildRequest('push', body), env);

		expect(response.status).toBe(202);
		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(fetchMock.mock.calls[0][0]).toBe(EXACT_HOOK);

		const init = fetchMock.mock.calls[0][1] as RequestInit;
		expect(init.method).toBe('POST');
		expect((init.headers as Record<string, string>)['User-Agent']).toBe('https://github.com/xPaw/GitHub-WebHook');
		expect(JSON.parse(init.body as string)).toEqual({
			username: 'GitHub-WebHook',
			allowed_mentions: { parse: [] },
			embeds: [
				expect.objectContaining({
					title: 'pushed 1 new commit to `master`',
					author: expect.objectContaining({ name: 'xPaw' }),
				}),
			],
		});

		const text = await response.text();
		expect(text).toContain('Received push in repository xPaw/GitHub-WebHook');
		expect(text).toContain('Matched "xPaw/GitHub-WebHook" as "xPaw/GitHub-WebHook"');
		expect(text).not.toContain('xPaw/*');
		expect(text).toContain('Discord HTTP 204');
		expect(text).not.toContain(EXACT_HOOK);
	});

	describe('username', () => {
		async function sentUsername(eventType: string, body: string, secret = EXACT_SECRET): Promise<unknown> {
			const response = await worker.fetch(await buildRequest(eventType, body, { secret }), env);

			expect(response.status).toBe(202);

			return JSON.parse((fetchMock.mock.calls[0][1] as RequestInit).body as string).username;
		}

		function pushTo(name: string): string {
			const payload = JSON.parse(fixture('push'));
			payload.repository.name = name;

			return JSON.stringify(payload);
		}

		it('is the name of the repository', async () => {
			expect(await sentUsername('push', fixture('push'))).toBe('GitHub-WebHook');
		});

		it('is the organization for events without a repository', async () => {
			expect(await sentUsername('ping', fixture('ping_org'), ORG_SECRET)).toBe('SteamDatabase');
		});

		it.each(['DiscordBot', 'my-clyde', 'everyone', 'here', 'x'.repeat(81)])('is left out for %s, which Discord rejects', async (name) => {
			expect(await sentUsername('push', pushTo(name))).toBeUndefined();
		});

		it('is kept for names that only resemble a reserved word', async () => {
			expect(await sentUsername('push', pushTo('everyone-else'))).toBe('everyone-else');
		});
	});

	describe('avatar', () => {
		const everything: Env = { REPOSITORIES: JSON.stringify({ '*': { secret: EXACT_SECRET, webhooks: [EXACT_HOOK] } }) };

		async function sentAvatar(eventType: string, body: string): Promise<unknown> {
			const response = await worker.fetch(await buildRequest(eventType, body), everything);

			expect(response.status).toBe(202);

			return JSON.parse((fetchMock.mock.calls[0][1] as RequestInit).body as string).avatar_url;
		}

		it('is the avatar of the owner of the repository', async () => {
			const payload = JSON.parse(fixture('issue_opened'));
			payload.repository.owner.avatar_url = 'https://avatars.githubusercontent.com/u/1?v=4';
			payload.sender.avatar_url = 'https://avatars.githubusercontent.com/u/2?v=4';

			expect(await sentAvatar('issues', JSON.stringify(payload))).toBe('https://avatars.githubusercontent.com/u/1?v=4');
		});

		it('is the avatar of the organization for events without a repository', async () => {
			expect(await sentAvatar('ping', fixture('ping_org'))).toBe(JSON.parse(fixture('ping_org')).organization.avatar_url);
		});

		it('falls back to the organization when the owner has no avatar', async () => {
			const payload = JSON.parse(fixture('issue_opened'));
			delete payload.repository.owner.avatar_url;

			expect(await sentAvatar('issues', JSON.stringify(payload))).toBe(payload.organization.avatar_url);
		});

		it('is left out when the payload has none, so the avatar of the webhook is used', async () => {
			expect(await sentAvatar('push', fixture('push'))).toBeUndefined();
		});
	});

	it('matches wildcard patterns with their own secret', async () => {
		const response = await worker.fetch(await buildRequest('push', fixture('push'), { secret: WILDCARD_SECRET }), env);

		expect(response.status).toBe(202);
		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(fetchMock.mock.calls[0][0]).toBe(WILDCARD_HOOK);
	});

	it('fans out to every matching pattern that shares the secret, once per webhook', async () => {
		const shared: Env = {
			REPOSITORIES: JSON.stringify({
				'xPaw/GitHub-WebHook': { secret: EXACT_SECRET, webhooks: [EXACT_HOOK, WILDCARD_HOOK] },
				'xPaw/*': { secret: EXACT_SECRET, webhooks: [WILDCARD_HOOK] },
			}),
		};

		const response = await worker.fetch(await buildRequest('push', fixture('push')), shared);

		expect(response.status).toBe(202);
		expect(fetchMock.mock.calls.map((call) => call[0]).sort()).toEqual([EXACT_HOOK, WILDCARD_HOOK].sort());
	});

	it('rejects the secret of a different repository', async () => {
		const response = await worker.fetch(await buildRequest('push', fixture('push'), { secret: ORG_SECRET }), env);

		expect(response.status).toBe(401);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('rejects repositories that are not configured the same way as a bad secret', async () => {
		// issue_opened is a SteamDatabase repository, no pattern matches it
		const unknown = await worker.fetch(await buildRequest('issues', fixture('issue_opened')), env);
		const invalid = await worker.fetch(await buildRequest('push', fixture('push'), { secret: 'wrong' }), env);

		expect(unknown.status).toBe(401);
		expect(await unknown.text()).toBe(await invalid.text());
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('accepts form-urlencoded requests', async () => {
		const body = new URLSearchParams({ payload: fixture('push') }).toString();
		const response = await worker.fetch(
			await buildRequest('push', body, { contentType: 'application/x-www-form-urlencoded; charset=utf-8' }),
			env,
		);

		expect(response.status).toBe(202);
		expect(fetchMock).toHaveBeenCalledTimes(1);
	});

	it('rejects an invalid signature', async () => {
		const body = fixture('push');
		const response = await worker.fetch(await buildRequest('push', body, { signature: `sha256=${'0'.repeat(64)}` }), env);

		expect(response.status).toBe(401);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it.each(['sha256=', 'sha1=abc', 'garbage'])('rejects the malformed signature %s without reading the body', async (signature) => {
		const request = await buildRequest('push', '{not json', { signature });
		const response = await worker.fetch(request, env);

		expect(response.status).toBe(401);
		expect(request.bodyUsed).toBe(false);
	});

	it('rejects a body that was changed after it was signed', async () => {
		const signature = await sign(EXACT_SECRET, fixture('push'));
		const tampered = fixture('push').replace('"pusher"', '"pusher" ');

		const response = await worker.fetch(await buildRequest('push', tampered, { signature }), env);

		expect(response.status).toBe(401);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('verifies bodies that contain multibyte text', async () => {
		const all: Env = { REPOSITORIES: JSON.stringify({ '*': { secret: EXACT_SECRET, webhooks: [EXACT_HOOK] } }) };
		const response = await worker.fetch(await buildRequest('issues', fixture('issue_opened_unicode')), all);

		expect(response.status).toBe(202);
	});

	it('does not accept the legacy sha1 signature header', async () => {
		const request = await buildRequest('push', fixture('push'), { signature: null });
		request.headers.set('X-Hub-Signature', 'sha1=0000000000000000000000000000000000000000');

		expect((await worker.fetch(request, env)).status).toBe(401);
	});

	it('rejects a missing signature', async () => {
		const response = await worker.fetch(await buildRequest('push', fixture('push'), { signature: null }), env);

		expect(response.status).toBe(401);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it.each(['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])('rejects %s requests', async (method) => {
		const response = await worker.fetch(new Request('https://example.workers.dev/', { method }), env);

		expect(response.status).toBe(405);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('rejects an empty body', async () => {
		const signature = `sha256=${'0'.repeat(64)}`;
		const response = await worker.fetch(await buildRequest('push', '', { signature }), env);

		expect(response.status).toBe(400);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('rejects a signature header that was sent twice', async () => {
		const body = fixture('push');
		const request = await buildRequest('push', body);
		request.headers.append('X-Hub-Signature-256', await sign(EXACT_SECRET, body));

		expect((await worker.fetch(request, env)).status).toBe(401);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('reads the headers case insensitively', async () => {
		const body = fixture('push');
		const request = new Request('https://example.workers.dev/', {
			method: 'POST',
			body,
			headers: {
				'x-github-event': 'push',
				'content-type': 'APPLICATION/JSON',
				'x-hub-signature-256': await sign(EXACT_SECRET, body),
			},
		});

		expect((await worker.fetch(request, env)).status).toBe(202);
	});

	it('rejects a signature in uppercase, which GitHub never sends', async () => {
		const body = fixture('push');
		const signature = (await sign(EXACT_SECRET, body)).toUpperCase().replace('SHA256=', 'sha256=');
		const request = await buildRequest('push', body, { signature });

		expect((await worker.fetch(request, env)).status).toBe(401);
		expect(request.bodyUsed).toBe(false);
	});

	it('rejects an unknown content type', async () => {
		const response = await worker.fetch(await buildRequest('push', fixture('push'), { contentType: 'text/plain' }), env);

		expect(response.status).toBe(400);
	});

	it('rejects a malformed event header', async () => {
		const response = await worker.fetch(await buildRequest('Push-Event', fixture('push')), env);

		expect(response.status).toBe(400);
	});

	it('rejects a payload without repository or organization', async () => {
		const response = await worker.fetch(await buildRequest('push', '{}'), env);

		expect(response.status).toBe(400);
	});

	it('returns 200 for ignored events', async () => {
		const response = await worker.fetch(await buildRequest('watch', fixture('push')), env);

		expect(response.status).toBe(200);
		expect(await response.text()).toContain('Ignored GitHub event: watch');
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('does not reveal whether an event is supported without a valid signature', async () => {
		const ignored = await worker.fetch(await buildRequest('watch', fixture('push'), { secret: 'wrong' }), env);
		const unsupported = await worker.fetch(await buildRequest('deployment', fixture('push'), { signature: null }), env);

		expect(ignored.status).toBe(401);
		expect(unsupported.status).toBe(401);
	});

	it('returns 501 for unsupported events', async () => {
		const response = await worker.fetch(await buildRequest('deployment', fixture('push')), env);

		expect(response.status).toBe(501);
		expect(await response.text()).toContain('Unsupported GitHub event: deployment');
	});

	it('accepts event names that have digits in them', async () => {
		const response = await worker.fetch(await buildRequest('projects_v2_item', fixture('push')), env);

		expect(response.status).toBe(501);
		expect(await response.text()).toContain('Unsupported GitHub event: projects_v2_item');
	});

	it('routes org-only payloads as <org>/repositories', async () => {
		const response = await worker.fetch(await buildRequest('ping', fixture('ping_org'), { secret: ORG_SECRET }), env);

		expect(response.status).toBe(202);
		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(fetchMock.mock.calls[0][0]).toBe(ORG_HOOK);
		expect(await response.text()).toContain('Received ping in repository SteamDatabase/repositories');
	});

	it('returns 502 when every send fails', async () => {
		fetchMock.mockImplementation(async () => new Response('nope', { status: 500 }));

		const response = await worker.fetch(await buildRequest('push', fixture('push')), env);

		expect(response.status).toBe(502);
		expect(fetchMock).toHaveBeenCalledTimes(1);
	});

	it.each([
		'not json',
		'["not an object"]',
		'{"xPaw/*":["https://discord.com/api/webhooks/1/a"]}',
		'{"xPaw/*":{"secret":"","webhooks":[]}}',
		'{"xPaw/*":{"secret":"s"}}',
		'{"xPaw/*":{"secret":"s","webhooks":[1]}}',
	])('returns 500 when the config is %s', async (config) => {
		const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
		const response = await worker.fetch(await buildRequest('push', fixture('push')), { REPOSITORIES: config });

		expect(response.status).toBe(500);
		expect(await response.text()).toBe('Worker is not configured.\n');
		expect(consoleError).toHaveBeenCalledTimes(1);
	});

	it('rejects a missing event header', async () => {
		const request = await buildRequest('push', fixture('push'));
		request.headers.delete('X-GitHub-Event');

		const response = await worker.fetch(request, env);

		expect(response.status).toBe(400);
		expect(await response.text()).toContain('Missing event header.');
	});

	it('rejects a missing content type', async () => {
		const request = await buildRequest('push', fixture('push'));
		request.headers.delete('Content-Type');

		expect((await worker.fetch(request, env)).status).toBe(400);
	});

	it('rejects a form without a payload field', async () => {
		const response = await worker.fetch(
			await buildRequest('push', 'other=1', { contentType: 'application/x-www-form-urlencoded' }),
			env,
		);

		expect(response.status).toBe(400);
		expect(await response.text()).toContain('Missing payload.');
	});

	it.each(['{not json', '[]', '"text"', 'null'])('rejects the body %s', async (body) => {
		const response = await worker.fetch(await buildRequest('push', body), env);

		expect(response.status).toBe(400);
		expect(await response.text()).toContain('Failed to decode JSON');
	});

	it('falls back to the owner and name when the repository has no full name', async () => {
		const payload = JSON.parse(fixture('push'));
		delete payload.repository.full_name;
		payload.repository.owner.name = 'xPaw';
		payload.repository.name = 'GitHub-WebHook';

		const response = await worker.fetch(await buildRequest('push', JSON.stringify(payload)), env);

		expect(response.status).toBe(202);
		expect(await response.text()).toContain('Received push in repository xPaw/GitHub-WebHook');
	});

	it('returns 202 without sending when the pattern has no webhooks', async () => {
		const empty: Env = {
			REPOSITORIES: JSON.stringify({ 'xPaw/*': { secret: EXACT_SECRET, webhooks: [] } }),
		};

		const response = await worker.fetch(await buildRequest('push', fixture('push')), empty);

		expect(response.status).toBe(202);
		expect(await response.text()).toContain('nothing was sent');
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('returns 500 without details when the payload can not be converted', async () => {
		const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
		const payload = JSON.parse(fixture('push'));
		delete payload.commits;

		const response = await worker.fetch(await buildRequest('push', JSON.stringify(payload)), env);

		expect(response.status).toBe(500);
		expect(await response.text()).toBe('Failed to process this event.\n');
		expect(consoleError).toHaveBeenCalled();
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('returns 400 when the payload has no sender', async () => {
		const payload = JSON.parse(fixture('push'));
		delete payload.sender;

		const response = await worker.fetch(await buildRequest('push', JSON.stringify(payload)), env);

		expect(response.status).toBe(400);
	});

	it('reports a Discord request that never completed', async () => {
		fetchMock.mockRejectedValue(new Error('connection reset'));

		const response = await worker.fetch(await buildRequest('push', fixture('push')), env);

		expect(response.status).toBe(502);
		expect(await response.text()).toContain('Discord request failed: connection reset');
	});

	it('returns 202 when only some of the sends fail', async () => {
		const both: Env = {
			REPOSITORIES: JSON.stringify({ 'xPaw/*': { secret: EXACT_SECRET, webhooks: [EXACT_HOOK, WILDCARD_HOOK] } }),
		};

		fetchMock.mockImplementation(async (url: string) => new Response(null, { status: url === EXACT_HOOK ? 204 : 404 }));

		const response = await worker.fetch(await buildRequest('push', fixture('push')), both);
		const text = await response.text();

		expect(response.status).toBe(202);
		expect(text).toContain('Discord HTTP 204');
		expect(text).toContain('Discord HTTP 404');
	});

	describe('rate limits', () => {
		it('retries once after the delay Discord asks for', async () => {
			fetchMock
				.mockResolvedValueOnce(Response.json({ retry_after: 0.01 }, { status: 429 }))
				.mockResolvedValueOnce(new Response(null, { status: 204 }));

			const response = await worker.fetch(await buildRequest('push', fixture('push')), env);

			expect(response.status).toBe(202);
			expect(fetchMock).toHaveBeenCalledTimes(2);
			expect(fetchMock.mock.calls[1][0]).toBe(EXACT_HOOK);
			expect(await response.text()).toContain('Discord HTTP 204');
		});

		it('does not retry a second time', async () => {
			fetchMock.mockImplementation(async () => Response.json({ retry_after: 0.01 }, { status: 429 }));

			const response = await worker.fetch(await buildRequest('push', fixture('push')), env);

			expect(response.status).toBe(502);
			expect(fetchMock).toHaveBeenCalledTimes(2);
			expect(await response.text()).toContain('Discord HTTP 429');
		});

		it('does not wait for a delay that does not fit in the time budget', async () => {
			fetchMock.mockImplementation(async () => Response.json({ retry_after: 60 }, { status: 429 }));

			const response = await worker.fetch(await buildRequest('push', fixture('push')), env);

			expect(response.status).toBe(502);
			expect(fetchMock).toHaveBeenCalledTimes(1);
		});

		it.each([
			['no retry_after', () => Response.json({ message: 'slow down' }, { status: 429 })],
			['a body that is not json', () => new Response('rate limited', { status: 429 })],
		])('does not retry with %s', async (_, respond) => {
			fetchMock.mockImplementation(async () => respond());

			const response = await worker.fetch(await buildRequest('push', fixture('push')), env);

			expect(response.status).toBe(502);
			expect(fetchMock).toHaveBeenCalledTimes(1);
		});
	});
});
