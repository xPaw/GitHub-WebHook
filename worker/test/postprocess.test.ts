import type { APIComponentInContainer, APIContainerComponent } from 'discord-api-types/v10';
import { describe, expect, it } from 'vitest';
import { formatEvent, layoutMessage } from '../src/discord/converter.js';
import { IgnoredEventError } from '../src/errors.js';
import { parseRequest, type WebhookRequest } from '../src/github.js';
import { processEmbed, processPayload } from '../src/postprocess.js';
import { loadPayload, type Payload } from './fixtures.js';

/** A fixture, with changes applied to its payload, read the way the worker reads a delivery. */
function request(eventType: string, name: string, change?: (payload: Payload) => void): WebhookRequest {
	const headers = { 'X-GitHub-Event': eventType, 'Content-Type': 'application/json' };

	return parseRequest(new Request('https://example.workers.dev', { method: 'POST', headers }), JSON.stringify(loadPayload(name, change)));
}

function expectIgnored(webhook: WebhookRequest, reason: string): void {
	expect(() => processPayload(webhook)).toThrow(new IgnoredEventError(reason));
}

function expectSent(webhook: WebhookRequest): void {
	expect(() => processPayload(webhook)).not.toThrow();
}

/** Runs both stages, the way the worker does, and returns the components inside the card. */
function process(webhook: WebhookRequest): APIComponentInContainer[] {
	processPayload(webhook);

	const embed = formatEvent(webhook.eventType, webhook.payload);

	processEmbed(webhook, embed);

	return (layoutMessage(embed, webhook.payload).components[0] as APIContainerComponent).components;
}

describe('noise', () => {
	it('ignores every event sent by dependabot', () => {
		const webhook = request('issues', 'issue_opened', (p) => {
			p.sender.id = 49699333;
		});

		expectIgnored(webhook, 'issues - dependabot sender');
	});

	it.each(['renovate', 'dependabot'])('ignores pushes to %s branches', (bot) => {
		const webhook = request('push', 'push', (p) => {
			p.ref = `refs/heads/${bot}/npm/vitest-5.x`;
		});

		expectIgnored(webhook, `push - dependency update in a ${bot} branch`);
	});

	it('ignores pushes to merge queue branches', () => {
		const webhook = request('push', 'push', (p) => {
			p.ref = 'refs/heads/gh-readonly-queue/master/pr-12-0123456789abcdef';
		});

		expectIgnored(webhook, 'push - merge queue branch');
	});

	it('ignores the push of a pull request merged on github.com', () => {
		const webhook = request('push', 'push', (p) => {
			p.head_commit.committer.username = 'web-flow';
			p.head_commit.message = 'Merge pull request #6 from monalisa/feature\n\ntest pull request';
		});

		expectIgnored(webhook, 'push - web-flow pull request merge');
	});

	it('sends other commits made on github.com', () => {
		expectSent(
			request('push', 'push', (p) => {
				p.head_commit.committer.username = 'web-flow';
				p.head_commit.message = 'Update README.md';
			}),
		);
	});

	it('sends a push that has no head commit', () => {
		expectSent(
			request('push', 'push', (p) => {
				p.head_commit = null;
			}),
		);
	});

	it('sends a push whose head commit has no message', () => {
		expectSent(
			request('push', 'push', (p) => {
				p.head_commit.committer.username = 'web-flow';
				delete p.head_commit.message;
			}),
		);
	});

	it('sends the alerts of dependabot', () => {
		expectSent(
			request('dependabot_alert', 'dependabot_alert_created', (p) => {
				p.sender.id = 49699333;
			}),
		);
	});

	it('sends the pull requests that dependabot merges itself', () => {
		expectSent(
			request('pull_request', 'pull_request_closed_merged', (p) => {
				p.sender.id = 49699333;
			}),
		);
	});

	it('ignores pull requests that dependabot opens or closes without merging', () => {
		expectIgnored(request('pull_request', 'pull_request_dependabot'), 'pull_request - dependabot sender');
		expectIgnored(
			request('pull_request', 'pull_request_closed', (p) => {
				p.sender.id = 49699333;
			}),
			'pull_request - dependabot sender',
		);
		expectIgnored(
			request('issues', 'issue_closed', (p) => {
				p.sender.id = 49699333;
				p.pull_request = { merged: true };
			}),
			'issues - dependabot sender',
		);
	});

	it.each(['renovate', 'dependabot'])('ignores deletions of %s branches', (bot) => {
		const webhook = request('delete', 'delete_branch', (p) => {
			p.ref = `${bot}/npm/vitest-5.x`;
		});

		expectIgnored(webhook, `delete - dependency update in a ${bot} branch`);
	});

	it('ignores deletions of merge queue branches', () => {
		const webhook = request('delete', 'delete_branch', (p) => {
			p.ref = 'gh-readonly-queue/master/pr-12-0123456789abcdef';
		});

		expectIgnored(webhook, 'delete - merge queue branch');
	});

	it('sends deletions of other branches', () => {
		expectSent(request('delete', 'delete_branch'));
	});

	it('does not mistake a tag for a branch', () => {
		expectSent(
			request('push', 'push_tag', (p) => {
				p.ref = 'refs/tags/dependabot/1.0';
			}),
		);
		expectSent(
			request('delete', 'delete', (p) => {
				p.ref = 'gh-readonly-queue/1.0';
			}),
		);
	});
});

describe('SchemaExplorer', () => {
	const BOT = 'github-actions[bot]';

	it('ignores a push that only has commits of github-actions', () => {
		const webhook = request('push', 'push', (p) => {
			p.repository.full_name = 'ValveResourceFormat/SchemaExplorer';
			p.commits[0].author.username = BOT;
		});

		expectIgnored(webhook, `push - ${BOT} commits`);
	});

	it('leaves the commits of github-actions out of a push that has others', () => {
		const webhook = request('push', 'push', (p) => {
			p.repository.full_name = 'valveresourceformat/schemaexplorer';
			p.commits.unshift({
				...p.commits[0],
				id: '0123456789abcdef0123456789abcdef01234567',
				message: 'Update cs2 schema',
				author: { ...p.commits[0].author, username: BOT },
			});
		});

		const text = process(webhook)
			.map((component) => ('content' in component ? component.content : ''))
			.join('\n');

		expect(text).toContain('pushed 1 new commit');
		expect(text).not.toContain('Update cs2 schema');
	});

	it('sends a push that has no commits', () => {
		expectSent(
			request('push', 'push_tag', (p) => {
				p.repository.full_name = 'ValveResourceFormat/SchemaExplorer';
			}),
		);
	});

	it('sends a push of github-actions to another repository', () => {
		expectSent(
			request('push', 'push', (p) => {
				p.commits[0].author.username = BOT;
			}),
		);
	});
});

describe('SteamTracking', () => {
	it.each(['SteamTracking/SteamTracking', 'SteamTracking/GameTracking-CS2'])('links a push to %s to DiffsHub', (name) => {
		const webhook = request('push', 'push', (p) => {
			p.repository.full_name = name;
		});

		expect(process(webhook).at(-1)).toEqual({
			type: 1,
			components: [
				{
					type: 2,
					style: 5,
					label: 'View on DiffsHub',
					url: 'https://diffshub.com/monalisa/Hello-World/commit/8ddec647cc7a5a819669ad55d08cfabe49925311',
				},
			],
		});
	});

	it.each(['SteamTracking/GameTracking', 'SteamTracking/GameTrackingX', 'SteamTracking/GameTracking-', 'SteamTracking/SteamTracking-GDPR', 'SteamTracking/Protobufs', 'monalisa/Hello-World'])(
		'does not link a push to %s to DiffsHub',
		(name) => {
			const webhook = request('push', 'push', (p) => {
				p.repository.full_name = name;
			});

			expect(process(webhook).every((component) => component.type === 10)).toBe(true);
		},
	);

	it('does not link an event to DiffsHub that has no link to GitHub', () => {
		const webhook = request('push', 'push', (p) => {
			p.repository.full_name = 'SteamTracking/SteamTracking';
		});

		for (const url of [undefined, 'https://example.com/compare']) {
			const embed = { title: 'pushed', url, author: { name: 'monalisa', icon_url: '' } };

			processEmbed(webhook, embed);

			expect(embed).not.toHaveProperty('links');
		}
	});
});
