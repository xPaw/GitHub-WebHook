import type {
	APIContainerComponent,
	APITextDisplayComponent,
} from 'discord-api-types/v10';
import { describe, expect, it } from 'vitest';
import { getEmbed } from '../src/discord/converter.js';
import { limitLength } from '../src/discord/text.js';
import { BadRequestError, IgnoredEventError, NotImplementedError } from '../src/errors.js';
import { actionFixtures, loadPayload as payload, type Payload, withAction } from './fixtures.js';

describe('ignored actions', () => {
	const fixtureOf: Record<string, string> = Object.fromEntries(actionFixtures);

	const actions: [event: string, actions: string[]][] = [
		['issues', ['edited', 'unpinned', 'milestoned', 'demilestoned', 'labeled', 'unlabeled', 'assigned', 'unassigned', 'typed', 'untyped', 'field_added', 'field_removed']],
		[
			'pull_request',
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
				'stacked',
			],
		],
		['pull_request_review', ['edited']],
		['pull_request_review_comment', ['edited', 'deleted']],
		['milestone', ['edited']],
		['release', ['created', 'edited', 'released', 'prereleased']],
		['member', ['edited']],
		['issue_comment', ['edited', 'pinned', 'unpinned']],
		['discussion', ['edited', 'labeled', 'unlabeled', 'unanswered']],
		['discussion_comment', ['edited']],
		['repository', ['edited']],
		['dependabot_alert', ['assignees_changed']],
		['code_scanning_alert', ['appeared_in_branch', 'updated_assignment']],
		['secret_scanning_alert', ['assigned', 'unassigned', 'validated', 'metadata_created', 'metadata_removed']],
		['project', ['edited']],
		['workflow_run', ['requested', 'in_progress']],
		['sponsorship', ['cancelled', 'edited', 'tier_changed', 'pending_cancellation', 'pending_tier_change']],
		['branch_protection_rule', ['edited']],
		['projects_v2', ['edited']],
		['projects_v2_status_update', ['edited', 'deleted']],
	];

	describe.each(actions)('%s', (eventType, ignored) => {
		it.each(ignored)('%s', (action) => {
			expect(() => getEmbed(eventType, withAction(fixtureOf[eventType], action))).toThrow(
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

	it.each(['success', 'cancelled', 'skipped', 'neutral', 'action_required', 'stale', 'constructor', null])('workflow run that ended with %s is ignored', (conclusion) => {
		const run = payload('workflow_run_failed', (p) => {
			p.workflow_run.conclusion = conclusion;
		});

		expect(() => getEmbed('workflow_run', run)).toThrow(new IgnoredEventError(`workflow_run - ${conclusion}`));
	});

	it('workflow run that failed on another branch is ignored', () => {
		const run = payload('workflow_run_failed', (p) => {
			p.workflow_run.head_branch = 'feature';
		});

		expect(() => getEmbed('workflow_run', run)).toThrow(new IgnoredEventError('workflow_run - not the default branch'));
	});

	it('team edit that changes neither the name nor the privacy is ignored', () => {
		const edited = payload('team_edited', (p) => {
			p.changes = { description: { from: 'An older description' } };
		});

		expect(() => getEmbed('team', edited)).toThrow(new IgnoredEventError('team - edited'));
	});
});

describe('unsupported events', () => {
	it('unknown event type', () => {
		expect(() => getEmbed('check_run', {})).toThrow(new NotImplementedError('check_run'));
	});

	it.each(actionFixtures)('%s with an unknown action', (eventType, fixture) => {
		expect(() => getEmbed(eventType, withAction(fixture, 'some_new_action'))).toThrow(
			new NotImplementedError(eventType, 'some_new_action'),
		);
	});

	it.each([
		['organization', 'organization_member_added'],
		['team', 'team_created'],
	])('%s with an action that every object inherits', (eventType, fixture) => {
		expect(() => getEmbed(eventType, withAction(fixture, 'constructor'))).toThrow(
			new NotImplementedError(eventType, 'constructor'),
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

/** The one length Discord imposes, over which it turns a whole message down. */
const MAX_MESSAGE_LENGTH = 4000;

interface Card {
	scope: string | null;
	title: string;
	/** Every character Discord counts towards {@link MAX_MESSAGE_LENGTH}. */
	size: number;
	username?: string;
	avatar?: string;
	url?: string;
	description?: string;
	footer?: { text: string };
}

/** Takes a card apart again, so that a test can assert on one piece of it. */
function embed(eventType: string, fixture: string, change: (payload: Payload) => void): Card {
	const message = getEmbed(eventType, payload(fixture, change));
	const container = message.components[0] as APIContainerComponent;
	const [heading, ...rest] = container.components.map((component) => (component as APITextDisplayComponent).content);

	const lines = heading.split('\n');
	const titleLine = lines[lines.length - 1];
	const linked = /^### \[(.*)]\((.*)\)$/s.exec(titleLine);

	const card: Card = {
		scope: lines.length > 1 ? lines[0].slice('-# '.length) : null,
		title: linked ? linked[1] : titleLine.slice('### '.length),
		size: [heading, ...rest].reduce((total, part) => total + [...part].length, 0),
		username: message.username,
		avatar: message.avatar_url,
	};

	if (linked) {
		card.url = linked[2];
	}

	// The labels come before the body, and are the only one of the two set as subtext
	if (rest[0]?.startsWith('-# ')) {
		card.footer = { text: (rest.shift() as string).slice('-# '.length) };
	}

	if (rest.length > 0) {
		card.description = rest[0];
	}

	return card;
}

describe('optional fields', () => {
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

		expect(result.title).toBe('monalisa added **ghost** as a collaborator');
	});

	it('deleted comment from a deleted user', () => {
		const result = embed('issue_comment', 'issue_comment_delete', (p) => {
			p.comment.user = null;
		});

		expect(result.title).toBe('monalisa deleted comment in PR **#502** from **ghost**');
	});

	it('ping without the id of the hook object', () => {
		const result = embed('ping', 'ping', (p) => {
			delete p.hook;
		});

		expect(result.title).toBe('monalisa set up hook **7292732** — it works!');
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

	it('escapes the markdown in a label, which is somebody else text', () => {
		const result = embed('issues', 'issue_opened', (p) => {
			p.issue.labels = [{ name: '**wontfix**' }, { name: 'good_first_issue' }];
		});

		expect(result.footer).toEqual({ text: '\\*\\*wontfix\\*\\* · good\\_first\\_issue' });
	});

	it('code scanning alert without a severity', () => {
		const result = embed('code_scanning_alert', 'code_scanning_alert_created', (p) => {
			p.alert.rule.severity = null;
		});

		expect(result.footer).toEqual({ text: 'none · js/unsafe-jquery-plugin' });
	});

	it('secret scanning alert without a secret type', () => {
		const result = embed('secret_scanning_alert', 'secret_scanning_alert_created', (p) => {
			delete p.alert.secret_type;
			delete p.alert.secret_type_display_name;
		});

		expect(result.title).toBe('github created Secret scanning alert **#3**: unknown');
	});

	it('push protection bypassed by a deleted user', () => {
		const result = embed('secret_scanning_alert', 'secret_scanning_alert_created_bypassed', (p) => {
			p.alert.push_protection_bypassed_by = {};
		});

		expect(result.description).toBe('Push protection bypassed by **ghost**');
	});

	it('commit by an author without a username or a name', () => {
		const result = embed('push', 'push_no_author', (p) => {
			delete p.commits[0].author.name;
		});

		expect(result.description).toContain(' - *unknown*');
	});

	it('branch with a backtick in its name', () => {
		const result = embed('delete', 'delete_branch', (p) => {
			p.ref = 'weird`branch';
		});

		expect(result.title).toBe('Codertocat deleted branch `` weird`branch ``');
	});

	it('branch with two backticks in a row in its name', () => {
		const result = embed('delete', 'delete_branch', (p) => {
			p.ref = 'weird``branch';
		});

		expect(result.title).toBe('Codertocat deleted branch ``` weird``branch ```');
	});

	it('transferred repository without a previous owner', () => {
		const result = embed('repository', 'repository_transferred', (p) => {
			p.changes.owner.from = {};
		});

		expect(result.title).toBe('monalisa transferred **linguist**');
	});

	it('push to a ref without a refs/ prefix', () => {
		const result = embed('push', 'push', (p) => {
			p.ref = 'master';
		});

		expect(result.title).toBe('monalisa pushed 1 new commit to `master`');
	});

	it('ruleset of an organization has no page of its own', () => {
		const result = embed('repository_ruleset', 'repository_ruleset_created', (p) => {
			p.repository_ruleset._links.html = null;
		});

		expect(result.url).toBeUndefined();
	});

	it('team without a privacy', () => {
		const result = embed('team', 'team_edited_privacy', (p) => {
			delete p.team.privacy;
		});

		expect(result.title).toBe('Codertocat changed the privacy of team **github** to **unknown**');
	});

	it('answered discussion without the body of the answer', () => {
		const result = embed('discussion', 'discussion_answered', (p) => {
			p.answer.body = null;
		});

		expect(result).not.toHaveProperty('description');
	});

	it('sponsorship without a sponsor is announced as a private one', () => {
		const result = embed('sponsorship', 'sponsorship_created', (p) => {
			p.sponsorship.sponsor = null;
		});

		expect(result.title).toBe('octocat got a new private sponsor');
		expect(result.scope).toBe('@octocat');
	});

	it('private sponsorship does not name the sponsor anywhere', () => {
		const result = embed('sponsorship', 'sponsorship_created_private', () => {});

		expect(JSON.stringify(result)).not.toContain('monalisa');
	});

	it('sponsorship of a deleted account', () => {
		const result = embed('sponsorship', 'sponsorship_created', (p) => {
			p.sponsorship.sponsorable = null;
		});

		expect(result.title).toBe('monalisa is now sponsoring **ghost**');
	});

	it('workflow run escapes the message of its commit once', () => {
		const result = embed('workflow_run', 'workflow_run_failed', (p) => {
			p.workflow_run.head_commit.message = 'fix_bug';
		});

		expect(result.description).toBe('fix\\_bug');
	});

	it('keeps a message of astral characters that fits the limit', () => {
		// Twice as many code units as characters, which is what the limit counts
		const message = '🎉'.repeat(60);

		const result = embed('workflow_run', 'workflow_run_failed', (p) => {
			p.workflow_run.head_commit.message = message;
		});

		expect(result.description).toBe(message);
	});

	it('workflow run without a name is named after its workflow', () => {
		const result = embed('workflow_run', 'workflow_run_failed', (p) => {
			p.workflow_run.name = '';
			p.workflow.name = 'Tests';
		});

		expect(result.title).toBe('Codertocat broke `master` — workflow **Tests** failed');
	});

	it('workflow run without any name', () => {
		const result = embed('workflow_run', 'workflow_run_failed', (p) => {
			p.workflow_run.name = null;
			p.workflow = null;
		});

		expect(result.title).toBe('Codertocat broke `master` — workflow **unknown** failed');
	});

	it('project without a body', () => {
		const result = embed('project', 'project', (p) => {
			p.project.body = null;
		});

		expect(result).not.toHaveProperty('description');
	});

	it('project status update without a status', () => {
		const result = embed('projects_v2_status_update', 'projects_v2_status_update', (p) => {
			p.projects_v2_status_update.status = null;
		});

		expect(result.title).toBe('Codertocat posted a project status update');
	});

	it('project status update without a body', () => {
		const result = embed('projects_v2_status_update', 'projects_v2_status_update', (p) => {
			p.projects_v2_status_update.body = null;
		});

		expect(result).not.toHaveProperty('description');
	});

	it('membership event without a member', () => {
		const result = embed('membership', 'membership_added', (p) => {
			p.member = null;
		});

		expect(result.title).toBe('Codertocat added **ghost** to team **github**');
	});

	it('blocked user that was deleted', () => {
		const result = embed('org_block', 'org_block_blocked', (p) => {
			p.blocked_user = null;
		});

		expect(result.title).toBe('Codertocat blocked user **ghost**');
	});

	it('organization member that was deleted', () => {
		const result = embed('organization', 'organization_member_added', (p) => {
			p.membership.user = null;
		});

		expect(result.title).toBe('Codertocat added **ghost** (member) to the organization');
	});

	it('organization invitation by email does not reveal the address', () => {
		const result = embed('organization', 'organization_member_invited', (p) => {
			delete p.user;
			p.invitation.login = null;
			p.invitation.email = 'hacktocat@example.com';
		});

		expect(result.title).toBe('Codertocat invited someone by email (member) to the organization');
	});

	it('organization invitation names the role in plain words', () => {
		const result = embed('organization', 'organization_member_invited', (p) => {
			p.invitation.role = 'billing_manager';
		});

		expect(result.title).toBe('Codertocat invited **hacktocat** (billing manager) to the organization');
	});

	it('organization invitation that reinstates someone has no role to name', () => {
		const result = embed('organization', 'organization_member_invited', (p) => {
			p.invitation.role = 'reinstate';
		});

		expect(result.title).toBe('Codertocat invited **hacktocat** to the organization');
	});

	it('organization member event without a membership', () => {
		const result = embed('organization', 'organization_member_removed', (p) => {
			delete p.membership;
		});

		expect(result.title).toBe('Codertocat removed **ghost** from the organization');
	});

	it.each([
		['a name Discord takes', 'monalisa', 'monalisa on GitHub'],
		['a name holding discord', 'discordapp', undefined],
		['a name holding clyde in another case', 'ClydeBot', undefined],
		// "cannot be everyone" is an exact match, which the suffix is enough to get past
		['a name Discord reserves on its own', 'everyone', 'everyone on GitHub'],
	])('%s', (_, login, username) => {
		const result = embed('issues', 'issue_opened', (p) => {
			p.sender.login = login;
		});

		expect(result.username).toBe(username);

		// The card names the sender whether or not the message can be sent as them
		expect(result.title.startsWith(`${login} `)).toBe(true);
	});

	it('sender without an avatar falls back to the url GitHub keeps one at', () => {
		const result = embed('issues', 'issue_opened', (p) => {
			delete p.sender.avatar_url;
		});

		expect(result.avatar).toBe('https://github.com/monalisa.png');
	});

	it('renamed organization without the previous name', () => {
		const result = embed('organization', 'organization_renamed', (p) => {
			delete p.changes;
		});

		expect(result.title).toBe('Codertocat renamed the organization **Octocoders**');
	});
});

describe('markdown in a body', () => {
	function body(text: string): string | undefined {
		return embed('issues', 'issue_opened', (p) => {
			p.issue.body = text;
		}).description;
	}

	it('flattens every level of heading to bold', () => {
		expect(body('# One\n### Three\n###### Six')).toBe('**One**\n**Three**\n**Six**');
	});

	it('does not nest bold that a heading already had', () => {
		expect(body('## A **bold** heading')).toBe('**A bold heading**');
	});

	it('leaves a hash that starts no heading alone', () => {
		expect(body('#123 is the issue\n#!/bin/sh')).toBe('#123 is the issue\n#!/bin/sh');
	});

	it('strips subtext, which is what a card sets its own scope in', () => {
		expect(body('-# a note\nthe body')).toBe('a note\nthe body');
	});

	it('leaves headings and subtext inside fenced code alone', () => {
		const fenced = '```sh\n# a comment, not a heading\n-# not subtext either\n```';

		expect(body(fenced)).toBe(fenced);
	});

	it('demotes around a fence without touching what is inside it', () => {
		expect(body('## Before\n```\n# inside\n```\n## After')).toBe('**Before**\n```\n# inside\n```\n**After**');
	});

	it('keeps whole lines when it runs out of room', () => {
		const lines = Array.from({ length: 12 }, (_, index) => `line ${index}`);

		expect(body(lines.join('\n'))).toBe(`${lines.slice(0, 8).join('\n')}…`);
	});

	it('counts a long line as the several it wraps into', () => {
		// Eight lines of seventy characters, one of which is given up to the ellipsis
		expect(body('x'.repeat(1000))).toBe(`${'x'.repeat(559)}…`);
	});

	it('closes a fence it had to cut through', () => {
		const long = `\`\`\`\n${Array.from({ length: 30 }, () => 'a line of code').join('\n')}\n\`\`\``;
		const result = body(long) as string;

		expect(result.startsWith('```\n')).toBe(true);
		expect(result.endsWith('\n```')).toBe(true);
		// The one it opens with, the one it was cut before, and the one added back
		expect(result.split('```')).toHaveLength(3);
	});

	it('leaves a body that fits alone', () => {
		expect(body('short and **bold**')).toBe('short and **bold**');
	});

	it('takes backticks in the middle of a line for text, not for a fence', () => {
		expect(body('use ``` in a sentence\n# Heading after it')).toBe('use ``` in a sentence\n**Heading after it**');
	});

	it('does not close a block that was never opened', () => {
		expect(body('a line with ``` in it')).toBe('a line with ``` in it');
	});

	it('opens a block on an indented fence', () => {
		expect(body('intro\n  ```\n# not a heading\n  ```\n# a heading')).toBe(
			'intro\n  ```\n# not a heading\n  ```\n**a heading**',
		);
	});
});

describe('limitLength', () => {
	it('leaves text that fits alone', () => {
		expect(limitLength('short', 10)).toBe('short');
	});

	it('does not cut through an escaped character', () => {
		// Eleven of them fit, but the eleventh is the first half of a pair, so ten go in with the ellipsis
		expect(limitLength('\\'.repeat(20), 12)).toBe(`${'\\'.repeat(10)}…`);
	});
});

describe('the text budget of a message', () => {
	/** A long body, and enough labels to crowd it out of the message. */
	const crowded = (count: number) => (p: Payload) => {
		p.issue.labels = Array.from({ length: count }, (_, index) => ({ name: `label-${index}-${'x'.repeat(40)}` }));
		p.issue.body = 'x'.repeat(1000);
	};

	it('cuts the body down to what the labels leave it', () => {
		const result = embed('issues', 'issue_opened', crowded(70));

		expect(result.description?.endsWith('…')).toBe(true);
		expect(result.size).toBeLessThanOrEqual(MAX_MESSAGE_LENGTH);
	});

	it('drops the body outright when nothing is left for it', () => {
		const result = embed('issues', 'issue_opened', crowded(100));

		expect(result).not.toHaveProperty('description');
		expect(result.footer?.text.endsWith('…')).toBe(true);
		expect(result.size).toBeLessThanOrEqual(MAX_MESSAGE_LENGTH);
	});

	it('leaves a message that fits alone', () => {
		const result = embed('issues', 'issue_opened', () => {});

		expect(result.size).toBeLessThan(MAX_MESSAGE_LENGTH);
		expect(result.description?.endsWith('…')).toBe(false);
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
