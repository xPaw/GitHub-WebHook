import { describe, expect, it } from 'vitest';
import { getEmbed } from '../src/discord/converter.js';
import { BadRequestError, IgnoredEventError, NotImplementedError } from '../src/errors.js';
import { readFixture } from './fixtures.js';

type Payload = Record<string, any>;

/** Loads the payload of a fixture and applies changes to it. */
function payload(fixture: string, change: (payload: Payload) => void = () => {}): Payload {
	const loaded = JSON.parse(readFixture(fixture)) as Payload;

	change(loaded);

	return loaded;
}

function withAction(fixture: string, action: string): Payload {
	return payload(fixture, (p) => {
		p.action = action;
	});
}

describe('ignored events', () => {
	it.each(['create', 'fork', 'watch', 'star', 'status'])('%s', (eventType) => {
		expect(() => getEmbed(eventType, {})).toThrow(new IgnoredEventError(eventType));
	});

	const actions: [event: string, fixture: string, actions: string[]][] = [
		['issues', 'issue_opened', ['edited', 'unpinned', 'milestoned', 'demilestoned', 'labeled', 'unlabeled', 'assigned', 'unassigned', 'typed', 'untyped']],
		[
			'pull_request',
			'pull_request_merged',
			[
				'edited',
				'synchronize',
				'labeled',
				'unlabeled',
				'assigned',
				'unassigned',
				'review_requested',
				'review_request_removed',
				'milestoned',
				'demilestoned',
				'enqueued',
				'dequeued',
				'auto_merge_disabled',
			],
		],
		['pull_request_review', 'pull_request_review', ['edited']],
		['pull_request_review_comment', 'pull_request_review_comment', ['edited', 'deleted']],
		['milestone', 'milestone', ['edited']],
		['release', 'release', ['created', 'edited', 'released', 'prereleased']],
		['member', 'member', ['edited']],
		['issue_comment', 'issue_comment', ['edited']],
		['discussion', 'discussion_created', ['edited', 'labeled', 'unlabeled', 'unanswered']],
		['discussion_comment', 'discussion_comment_created', ['edited']],
		['repository', 'repository', ['edited']],
		['code_scanning_alert', 'code_scanning_alert_created', ['appeared_in_branch']],
		['secret_scanning_alert', 'secret_scanning_alert_created', ['assigned', 'unassigned', 'validated']],
	];

	describe.each(actions)('%s', (eventType, fixture, ignored) => {
		it.each(ignored)('%s', (action) => {
			expect(() => getEmbed(eventType, withAction(fixture, action))).toThrow(
				new IgnoredEventError(`${eventType} - ${action}`),
			);
		});
	});

	it('pull_request_review - commented', () => {
		const review = payload('pull_request_review', (p) => {
			p.review.state = 'commented';
		});

		expect(() => getEmbed('pull_request_review', review)).toThrow(
			new IgnoredEventError('pull_request_review - commented'),
		);
	});
});

describe('unsupported events', () => {
	it('unknown event type', () => {
		expect(() => getEmbed('deployment', {})).toThrow(new NotImplementedError('deployment'));
	});

	const fixtures: [event: string, fixture: string][] = [
		['issues', 'issue_opened'],
		['pull_request', 'pull_request_merged'],
		['milestone', 'milestone'],
		['package', 'package'],
		['registry_package', 'registry_package'],
		['release', 'release'],
		['commit_comment', 'commit_comment'],
		['issue_comment', 'issue_comment'],
		['pull_request_review', 'pull_request_review'],
		['pull_request_review_comment', 'pull_request_review_comment'],
		['discussion', 'discussion_created'],
		['discussion_comment', 'discussion_comment_created'],
		['code_scanning_alert', 'code_scanning_alert_created'],
		['repository_advisory', 'repository_advisory_published'],
		['dependabot_alert', 'dependabot_alert_created'],
		['secret_scanning_alert', 'secret_scanning_alert_created'],
		['member', 'member'],
		['repository', 'repository'],
	];

	it.each(fixtures)('%s with an unknown action', (eventType, fixture) => {
		expect(() => getEmbed(eventType, withAction(fixture, 'some_new_action'))).toThrow(
			new NotImplementedError(eventType, 'some_new_action'),
		);
	});

	it('push that deletes a ref', () => {
		const push = payload('push', (p) => {
			p.deleted = true;
		});

		expect(() => getEmbed('push', push)).toThrow(NotImplementedError);
	});

	it('delete of an unknown ref type', () => {
		const deleted = payload('delete', (p) => {
			p.ref_type = 'repository';
		});

		expect(() => getEmbed('delete', deleted)).toThrow(new NotImplementedError('delete', 'repository'));
	});

	it('includes the detail in the message, but not in the event name', () => {
		const error = new NotImplementedError('issues', 'typed');

		expect(error.message).toBe('Unsupported GitHub event: issues - typed');
		expect(error.eventName).toBe('issues');
		expect(new NotImplementedError('issues').message).toBe('Unsupported GitHub event: issues');
	});
});

describe('optional fields', () => {
	function embed(eventType: string, fixture: string, change: (payload: Payload) => void) {
		return getEmbed(eventType, payload(fixture, change)).embeds[0];
	}

	it('ping without zen has no description', () => {
		const result = embed('ping', 'ping', (p) => {
			delete p.zen;
		});

		expect(result).not.toHaveProperty('description');
	});

	it('merged pull request from a deleted user', () => {
		const result = embed('pull_request', 'pull_request_closed_merged', (p) => {
			p.pull_request.user = null;
		});

		expect(result.description).toBe('Merged from **ghost** to `master`');
	});

	it('member event without a member', () => {
		const result = embed('member', 'member', (p) => {
			p.member = null;
		});

		expect(result.title).toBe('added **ghost** as a collaborator');
	});

	it('deleted comment from a deleted user', () => {
		const result = embed('issue_comment', 'issue_comment_delete', (p) => {
			p.comment.user = null;
		});

		expect(result.title).toBe('deleted comment in PR **#502** from **ghost**');
	});

	it('ping without the id of the hook object', () => {
		const result = embed('ping', 'ping', (p) => {
			delete p.hook;
		});

		expect(result.title).toBe('Hook 7292732 worked!');
	});

	it('answered discussion without an answer links to the discussion', () => {
		const result = embed('discussion', 'discussion_answered', (p) => {
			delete p.answer;
		});

		expect(result.url).toBe('https://github.com/octo-org/octo-repo/discussions/90');
	});

	it('only an answered discussion links to the answer', () => {
		const result = embed('discussion', 'discussion_answered', (p) => {
			p.action = 'locked';
		});

		expect(result.url).toBe('https://github.com/octo-org/octo-repo/discussions/90');
	});

	it('resolved secret scanning alert with an empty resolution', () => {
		const result = embed('secret_scanning_alert', 'secret_scanning_alert_resolved', (p) => {
			p.alert.resolution = '';
		});

		expect(result).not.toHaveProperty('description');
	});

	it('transferred repository without a previous owner', () => {
		const result = embed('repository', 'repository_transferred', (p) => {
			p.changes.owner.from = {};
		});

		expect(result.title).toBe('transferred **SteamDocsScraper**');
	});

	it('push to a ref without a refs/ prefix', () => {
		const result = embed('push', 'push', (p) => {
			p.ref = 'master';
		});

		expect(result.title).toBe('pushed 1 new commit to `master`');
	});
});

describe('html stripping', () => {
	it.each(['<a ', '<!--', '<a "', "<a '"])('stays fast on a large body of unclosed %s', (opener) => {
		const issue = payload('issue_opened', (p) => {
			p.issue.body = opener.repeat(20000);
		});

		const start = performance.now();
		getEmbed('issues', issue);

		expect(performance.now() - start).toBeLessThan(250);
	});
});

describe('malformed payloads', () => {
	it('rejects a payload without a sender', () => {
		const push = payload('push', (p) => {
			delete p.sender;
		});

		expect(() => getEmbed('push', push)).toThrow(BadRequestError);
	});
});
