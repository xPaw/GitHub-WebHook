import { describe, expect, it } from 'vitest';
import { getEmbed } from '../src/discord/converter.js';
import { BadRequestError, IgnoredEventError, NotImplementedError } from '../src/errors.js';
import { loadPayload as payload, type Payload } from './fixtures.js';

function withAction(fixture: string, action: string): Payload {
	return payload(fixture, (p) => {
		p.action = action;
	});
}

describe('ignored actions', () => {
	const actions: [event: string, fixture: string, actions: string[]][] = [
		['issues', 'issue_opened', ['edited', 'unpinned', 'milestoned', 'demilestoned', 'labeled', 'unlabeled', 'assigned', 'unassigned', 'typed', 'untyped', 'field_added', 'field_removed']],
		[
			'pull_request',
			'pull_request_closed_merged',
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
		['pull_request_review', 'pull_request_review', ['edited']],
		['pull_request_review_comment', 'pull_request_review_comment', ['edited', 'deleted']],
		['milestone', 'milestone', ['edited']],
		['release', 'release', ['created', 'edited', 'released', 'prereleased']],
		['member', 'member', ['edited']],
		['issue_comment', 'issue_comment', ['edited', 'pinned', 'unpinned']],
		['discussion', 'discussion_created', ['edited', 'labeled', 'unlabeled', 'unanswered']],
		['discussion_comment', 'discussion_comment_created', ['edited']],
		['repository', 'repository', ['edited']],
		['dependabot_alert', 'dependabot_alert_created', ['assignees_changed']],
		['code_scanning_alert', 'code_scanning_alert_created', ['appeared_in_branch', 'updated_assignment']],
		['secret_scanning_alert', 'secret_scanning_alert_created', ['assigned', 'unassigned', 'validated', 'metadata_created', 'metadata_removed']],
		['project', 'project', ['edited']],
		['branch_protection_rule', 'branch_protection_rule_created', ['edited']],
		['projects_v2', 'projects_v2_created', ['edited']],
		['projects_v2_status_update', 'projects_v2_status_update', ['edited', 'deleted']],
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
		expect(() => getEmbed('workflow_run', {})).toThrow(new NotImplementedError('workflow_run'));
	});

	const fixtures: [event: string, fixture: string][] = [
		['issues', 'issue_opened'],
		['pull_request', 'pull_request_closed_merged'],
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
		['project', 'project'],
		['projects_v2', 'projects_v2_created'],
		['projects_v2_status_update', 'projects_v2_status_update'],
		['branch_protection_configuration', 'branch_protection_configuration_enabled'],
		['branch_protection_rule', 'branch_protection_rule_created'],
		['repository_ruleset', 'repository_ruleset_created'],
		['deploy_key', 'deploy_key_created'],
		['meta', 'meta_deleted'],
		['organization', 'organization_member_added'],
		['org_block', 'org_block_blocked'],
		['membership', 'membership_added'],
		['team', 'team_created'],
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

		expect(result.title).toBe('⚠ Secret scanning alert **#3** created: unknown');
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

		expect(result.title).toBe('deleted branch `` weird`branch ``');
	});

	it('transferred repository without a previous owner', () => {
		const result = embed('repository', 'repository_transferred', (p) => {
			p.changes.owner.from = {};
		});

		expect(result.title).toBe('transferred **linguist**');
	});

	it('push to a ref without a refs/ prefix', () => {
		const result = embed('push', 'push', (p) => {
			p.ref = 'master';
		});

		expect(result.title).toBe('pushed 1 new commit to `master`');
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

		expect(result.title).toBe('changed the privacy of team **github** to **unknown**');
	});

	it('team edit that changes neither the name nor the privacy is ignored', () => {
		const edited = payload('team_edited', (p) => {
			p.changes = { description: { from: 'An older description' } };
		});

		expect(() => getEmbed('team', edited)).toThrow(new IgnoredEventError('team - edited'));
	});

	it('answered discussion without the body of the answer', () => {
		const result = embed('discussion', 'discussion_answered', (p) => {
			p.answer.body = null;
		});

		expect(result).not.toHaveProperty('description');
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

		expect(result.title).toBe('posted a project status update');
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

		expect(result.title).toBe('added **ghost** to team **github**');
	});

	it('blocked user that was deleted', () => {
		const result = embed('org_block', 'org_block_blocked', (p) => {
			p.blocked_user = null;
		});

		expect(result.title).toBe('blocked user **ghost**');
	});

	it('organization member that was deleted', () => {
		const result = embed('organization', 'organization_member_added', (p) => {
			p.membership.user = null;
		});

		expect(result.title).toBe('added **ghost** (member) to the organization');
	});

	it('organization invitation by email does not reveal the address', () => {
		const result = embed('organization', 'organization_member_invited', (p) => {
			delete p.user;
			p.invitation.login = null;
			p.invitation.email = 'hacktocat@example.com';
		});

		expect(result.title).toBe('invited someone by email (member) to the organization');
	});

	it('organization invitation names the role in plain words', () => {
		const result = embed('organization', 'organization_member_invited', (p) => {
			p.invitation.role = 'billing_manager';
		});

		expect(result.title).toBe('invited **hacktocat** (billing manager) to the organization');
	});

	it('organization invitation that reinstates someone has no role to name', () => {
		const result = embed('organization', 'organization_member_invited', (p) => {
			p.invitation.role = 'reinstate';
		});

		expect(result.title).toBe('invited **hacktocat** to the organization');
	});

	it('organization member event without a membership', () => {
		const result = embed('organization', 'organization_member_removed', (p) => {
			delete p.membership;
		});

		expect(result.title).toBe('removed **ghost** from the organization');
	});

	it('renamed organization without the previous name', () => {
		const result = embed('organization', 'organization_renamed', (p) => {
			delete p.changes;
		});

		expect(result.title).toBe('renamed the organization **Octocoders**');
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
