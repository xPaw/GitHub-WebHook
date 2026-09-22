import { sign } from '@octokit/webhooks-methods';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import worker from '../src/index.js';
import { readFixture as fixture, loadPayload, type Payload, withAction } from './fixtures.js';

const SECRET = 'correct horse';
const ID = '123456789012345678';
const TOKEN = 'aBc-123_xYz';
const THREAD = '987654321098765432';
const WORKER = 'https://example.workers.dev';
const PATH = `/discordhook/${ID}/${TOKEN}`;
const HOOK = `https://discord.com/api/webhooks/${ID}/${TOKEN}`;
// Components are only sent to a webhook that no application owns when this is asked for
const POSTED = `${HOOK}?with_components=true`;

const env: Env = { SECRET };

async function buildRequest(
	eventType: string,
	body: string,
	options: { contentType?: string; secret?: string; signature?: string | null; path?: string } = {},
): Promise<Request> {
	const headers = new Headers({
		'X-GitHub-Event': eventType,
		'Content-Type': options.contentType ?? 'application/json',
	});

	const signature = options.signature === undefined ? await sign(options.secret ?? SECRET, body) : options.signature;

	if (signature !== null) {
		headers.set('X-Hub-Signature-256', signature);
	}

	return new Request(`${WORKER}${options.path ?? PATH}`, { method: 'POST', headers, body });
}

/** Delivers a fixture, with changes applied to its payload, the way GitHub would. */
async function deliver(eventType: string, name: string, change?: (payload: Payload) => void): Promise<Response> {
	return worker.fetch(await buildRequest(eventType, JSON.stringify(loadPayload(name, change))), env);
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
	it('sends the converted embed to the Discord webhook of the url', async () => {
		const body = fixture('push');
		const response = await worker.fetch(await buildRequest('push', body), env);

		expect(response.status).toBe(202);
		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(fetchMock.mock.calls[0][0]).toBe(POSTED);

		const init = fetchMock.mock.calls[0][1] as RequestInit;
		expect(init.method).toBe('POST');
		expect((init.headers as Record<string, string>)['User-Agent']).toBe('https://github.com/xPaw/GitHub-WebHook');
		expect(JSON.parse(init.body as string)).toEqual({
			allowed_mentions: { parse: [] },
			flags: 32768,
			username: 'monalisa on GitHub',
			avatar_url: 'https://avatars.githubusercontent.com/u/90000001?v=3',
			components: [
				expect.objectContaining({
					type: 17,
					components: [
						expect.objectContaining({
							content: expect.stringContaining('-# Hello-World\n### [monalisa pushed 1 new commit]'),
						}),
						expect.anything(),
					],
				}),
			],
		});

		const text = await response.text();
		expect(text).toContain('Received push in repository monalisa/Hello-World');
		expect(text).toContain('Discord HTTP 204');
		expect(text).not.toContain(TOKEN);
	});

	describe('url', () => {
		it('posts into the thread of the url', async () => {
			const response = await worker.fetch(await buildRequest('push', fixture('push'), { path: `${PATH}?thread_id=${THREAD}` }), env);

			expect(response.status).toBe(202);
			expect(fetchMock.mock.calls[0][0]).toBe(`${POSTED}&thread_id=${THREAD}`);
		});

		it('does not forward any other query parameter', async () => {
			const response = await worker.fetch(await buildRequest('push', fixture('push'), { path: `${PATH}?wait=true&thread_name=x` }), env);

			expect(response.status).toBe(202);
			expect(fetchMock.mock.calls[0][0]).toBe(POSTED);
		});

		it.each([
			['no webhook', '/'],
			['no prefix', `/${ID}/${TOKEN}`],
			['the path of Discord', `/api/webhooks/${ID}/${TOKEN}`],
			['an extra segment', `${PATH}/github`],
			['an invalid id', `/discordhook/123/${TOKEN}`],
			['an invalid token', `/discordhook/${ID}/abc%2Fdef`],
			['a token that leaves the path', `/discordhook/${ID}/..%2F..%2F..%2Fevil`],
			['an invalid thread', `${PATH}?thread_id=general`],
		])('rejects a url with %s', async (_, path) => {
			const response = await worker.fetch(await buildRequest('push', fixture('push'), { path }), env);

			expect(response.status).toBe(400);
			expect(fetchMock).not.toHaveBeenCalled();
		});

		it('does not reveal whether the url is valid without a valid signature', async () => {
			const response = await worker.fetch(await buildRequest('push', fixture('push'), { path: '/', secret: 'wrong' }), env);

			expect(response.status).toBe(401);
			expect(fetchMock).not.toHaveBeenCalled();
		});
	});

	it('rejects a different secret', async () => {
		const response = await worker.fetch(await buildRequest('push', fixture('push'), { secret: 'wrong' }), env);

		expect(response.status).toBe(401);
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
		const signature = await sign(SECRET, fixture('push'));
		const tampered = fixture('push').replace('"pusher"', '"pusher" ');

		const response = await worker.fetch(await buildRequest('push', tampered, { signature }), env);

		expect(response.status).toBe(401);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('verifies bodies that contain multibyte text', async () => {
		const response = await worker.fetch(await buildRequest('issues', fixture('issue_opened_unicode')), env);

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
		const response = await worker.fetch(new Request(`${WORKER}${PATH}`, { method }), env);

		expect(response.status).toBe(405);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('rejects an empty body', async () => {
		const signature = `sha256=${'0'.repeat(64)}`;
		const response = await worker.fetch(await buildRequest('push', '', { signature }), env);

		expect(response.status).toBe(401);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('rejects a signature header that was sent twice', async () => {
		const body = fixture('push');
		const request = await buildRequest('push', body);
		request.headers.append('X-Hub-Signature-256', await sign(SECRET, body));

		expect((await worker.fetch(request, env)).status).toBe(401);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('reads the headers case insensitively', async () => {
		const body = fixture('push');
		const request = new Request(`${WORKER}${PATH}`, {
			method: 'POST',
			body,
			headers: {
				'x-github-event': 'push',
				'content-type': 'APPLICATION/JSON',
				'x-hub-signature-256': await sign(SECRET, body),
			},
		});

		expect((await worker.fetch(request, env)).status).toBe(202);
	});

	it('rejects a signature in uppercase, which GitHub never sends', async () => {
		const body = fixture('push');
		const signature = (await sign(SECRET, body)).toUpperCase().replace('SHA256=', 'sha256=');
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

	it('returns 200 for ignored actions', async () => {
		const edited = JSON.stringify(withAction('issue_opened', 'edited'));
		const response = await worker.fetch(await buildRequest('issues', edited), env);

		expect(response.status).toBe(200);
		expect(await response.text()).toContain('Ignored GitHub event: issues - edited');
		expect(fetchMock).not.toHaveBeenCalled();
	});

	describe('noise', () => {
		async function expectIgnored(response: Response, reason: string): Promise<void> {
			expect(response.status).toBe(200);
			expect(await response.text()).toBe(`Ignored GitHub event: ${reason}\n`);
			expect(fetchMock).not.toHaveBeenCalled();
		}

		it('ignores every event sent by dependabot', async () => {
			const response = await deliver('issues', 'issue_opened', (p) => {
				p.sender.id = 49699333;
			});

			await expectIgnored(response, 'issues - dependabot sender');
		});

		it.each(['renovate', 'dependabot'])('ignores pushes to %s branches', async (bot) => {
			const response = await deliver('push', 'push', (p) => {
				p.ref = `refs/heads/${bot}/npm/vitest-5.x`;
			});

			await expectIgnored(response, `push - dependency update in a ${bot} branch`);
		});

		it('ignores pushes to merge queue branches', async () => {
			const response = await deliver('push', 'push', (p) => {
				p.ref = 'refs/heads/gh-readonly-queue/master/pr-12-0123456789abcdef';
			});

			await expectIgnored(response, 'push - merge queue branch');
		});

		it('ignores the push of a pull request merged on github.com', async () => {
			const response = await deliver('push', 'push', (p) => {
				p.head_commit.committer.username = 'web-flow';
				p.head_commit.message = 'Merge pull request #6 from monalisa/feature\n\ntest pull request';
			});

			await expectIgnored(response, 'push - web-flow pull request merge');
		});

		it('sends other commits made on github.com', async () => {
			const response = await deliver('push', 'push', (p) => {
				p.head_commit.committer.username = 'web-flow';
				p.head_commit.message = 'Update README.md';
			});

			expect(response.status).toBe(202);
		});

		it('sends a push that has no head commit', async () => {
			const response = await deliver('push', 'push', (p) => {
				p.head_commit = null;
			});

			expect(response.status).toBe(202);
		});

		it('sends a push whose head commit has no message', async () => {
			const response = await deliver('push', 'push', (p) => {
				p.head_commit.committer.username = 'web-flow';
				delete p.head_commit.message;
			});

			expect(response.status).toBe(202);
		});

		it('sends the alerts of dependabot', async () => {
			const response = await deliver('dependabot_alert', 'dependabot_alert_created', (p) => {
				p.sender.id = 49699333;
			});

			expect(response.status).toBe(202);
		});

		it('sends the pull requests that dependabot merges itself', async () => {
			const response = await deliver('pull_request', 'pull_request_closed_merged', (p) => {
				p.sender.id = 49699333;
			});

			expect(response.status).toBe(202);
		});

		it('ignores pull requests that dependabot opens or closes without merging', async () => {
			const opened = await deliver('pull_request', 'pull_request_dependabot');
			const closed = await deliver('pull_request', 'pull_request_closed', (p) => {
				p.sender.id = 49699333;
			});
			const mergedElsewhere = await deliver('issues', 'issue_closed', (p) => {
				p.sender.id = 49699333;
				p.pull_request = { merged: true };
			});

			await expectIgnored(opened, 'pull_request - dependabot sender');
			await expectIgnored(closed, 'pull_request - dependabot sender');
			await expectIgnored(mergedElsewhere, 'issues - dependabot sender');
		});

		it.each(['renovate', 'dependabot'])('ignores deletions of %s branches', async (bot) => {
			const response = await deliver('delete', 'delete_branch', (p) => {
				p.ref = `${bot}/npm/vitest-5.x`;
			});

			await expectIgnored(response, `delete - dependency update in a ${bot} branch`);
		});

		it('ignores deletions of merge queue branches', async () => {
			const response = await deliver('delete', 'delete_branch', (p) => {
				p.ref = 'gh-readonly-queue/master/pr-12-0123456789abcdef';
			});

			await expectIgnored(response, 'delete - merge queue branch');
		});

		it('sends deletions of other branches', async () => {
			const response = await deliver('delete', 'delete_branch');

			expect(response.status).toBe(202);
		});

		it('does not mistake a tag for a branch', async () => {
			const pushed = await deliver('push', 'push_tag', (p) => {
				p.ref = 'refs/tags/dependabot/1.0';
			});
			const deleted = await deliver('delete', 'delete', (p) => {
				p.ref = 'gh-readonly-queue/1.0';
			});

			expect(pushed.status).toBe(202);
			expect(deleted.status).toBe(202);
		});
	});

	it('does not reveal whether an event is supported without a valid signature', async () => {
		const edited = JSON.stringify(withAction('issue_opened', 'edited'));
		const ignored = await worker.fetch(await buildRequest('issues', edited, { secret: 'wrong' }), env);
		const unsupported = await worker.fetch(await buildRequest('check_run', fixture('push'), { signature: null }), env);

		expect(ignored.status).toBe(401);
		expect(unsupported.status).toBe(401);
	});

	it('returns 501 for unsupported events', async () => {
		const response = await worker.fetch(await buildRequest('check_run', fixture('push')), env);

		expect(response.status).toBe(501);
		expect(await response.text()).toContain('Unsupported GitHub event: check_run');
	});

	it('accepts event names that have digits in them', async () => {
		const response = await worker.fetch(await buildRequest('projects_v2_item', fixture('push')), env);

		expect(response.status).toBe(501);
		expect(await response.text()).toContain('Unsupported GitHub event: projects_v2_item');
	});

	it('reports org-only payloads as <org>/repositories', async () => {
		const response = await worker.fetch(await buildRequest('ping', fixture('ping_org')), env);

		expect(response.status).toBe(202);
		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(await response.text()).toContain('Received ping in repository octo-org/repositories');
	});

	it('reports a sponsorship as <sponsored account>/sponsors', async () => {
		const response = await worker.fetch(await buildRequest('sponsorship', fixture('sponsorship_created')), env);

		expect(response.status).toBe(202);
		expect(await response.text()).toContain('Received sponsorship in repository octocat/sponsors');
	});

	it('reports the ping of a sponsors listing as <sender>/sponsors', async () => {
		const response = await worker.fetch(await buildRequest('ping', fixture('ping_sponsors')), env);

		expect(response.status).toBe(202);
		expect(await response.text()).toContain('Received ping in repository octocat/sponsors');
	});

	it('rejects the ping of another kind of hook that has no repository', async () => {
		const response = await deliver('ping', 'ping_sponsors', (p) => {
			p.hook.type = 'App';
		});

		expect(response.status).toBe(400);
		expect(await response.text()).toContain('Missing repository information.');
	});

	it('returns 502 when Discord rejects the message', async () => {
		fetchMock.mockImplementation(async () => new Response('nope', { status: 500 }));

		const response = await worker.fetch(await buildRequest('push', fixture('push')), env);

		expect(response.status).toBe(502);
		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(await response.text()).toContain('Discord HTTP 500');
	});

	it.each([
		['missing', {} as Env],
		['empty', { SECRET: '' }],
	])('returns 500 when the secret is %s', async (_, unconfigured) => {
		const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
		const response = await worker.fetch(await buildRequest('push', fixture('push')), unconfigured);

		expect(response.status).toBe(500);
		expect(await response.text()).toBe('Worker is not configured.\n');
		expect(consoleError).toHaveBeenCalledTimes(1);
		expect(fetchMock).not.toHaveBeenCalled();
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
		const response = await deliver('push', 'push', (p) => {
			delete p.repository.full_name;
			p.repository.owner.name = 'monalisa';
			p.repository.name = 'Hello-World';
		});

		expect(response.status).toBe(202);
		expect(await response.text()).toContain('Received push in repository monalisa/Hello-World');
	});

	it('returns 500 without details when the payload can not be converted', async () => {
		const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
		const response = await deliver('push', 'push', (p) => {
			delete p.commits;
		});

		expect(response.status).toBe(500);
		expect(await response.text()).toBe('Failed to process this event.\n');
		expect(consoleError).toHaveBeenCalled();
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('returns 400 when the payload has no sender', async () => {
		const response = await deliver('push', 'push', (p) => {
			delete p.sender;
		});

		expect(response.status).toBe(400);
	});

	it('reports a Discord request that never completed', async () => {
		fetchMock.mockRejectedValue(new Error('connection reset'));

		const response = await worker.fetch(await buildRequest('push', fixture('push')), env);

		expect(response.status).toBe(502);
		expect(await response.text()).toContain('Discord request failed: connection reset');
	});

	describe('rate limits', () => {
		it('retries once after the delay Discord asks for', async () => {
			fetchMock
				.mockResolvedValueOnce(Response.json({ retry_after: 0.01 }, { status: 429 }))
				.mockResolvedValueOnce(new Response(null, { status: 204 }));

			const response = await worker.fetch(await buildRequest('push', fixture('push')), env);

			expect(response.status).toBe(202);
			expect(fetchMock).toHaveBeenCalledTimes(2);
			expect(fetchMock.mock.calls[1][0]).toBe(POSTED);
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
