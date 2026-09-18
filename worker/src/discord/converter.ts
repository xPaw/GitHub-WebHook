import type { operations } from '@octokit/openapi-webhooks-types';
import { BadRequestError, IgnoredEventError, NotImplementedError } from '../errors.js';
import { escape, escapeCode, shortDescription, shortMessage } from './text.js';

/** Every payload of an event, the operations are keyed as `event` or `event/action`. */
type Payload<Event extends string> =
	operations[Extract<keyof operations, Event | `${Event}/${string}`>]['requestBody']['content']['application/json'];

type PingEvent = Payload<'ping'>;
type PushEvent = Payload<'push'>;
type DeleteEvent = Payload<'delete'>;
type PublicEvent = Payload<'public'>;
type GollumEvent = Payload<'gollum'>;
type CommitCommentEvent = Payload<'commit-comment'>;
type IssuesEvent = Payload<'issues'>;
type IssueCommentEvent = Payload<'issue-comment'>;
type PullRequestEvent = Payload<'pull-request'>;
type PullRequestReviewEvent = Payload<'pull-request-review'>;
type PullRequestReviewCommentEvent = Payload<'pull-request-review-comment'>;
type DiscussionEvent = Payload<'discussion'>;
type DiscussionCommentEvent = Payload<'discussion-comment'>;
type MemberEvent = Payload<'member'>;
type MilestoneEvent = Payload<'milestone'>;
type PackageEvent = Payload<'package'>;
type ProjectEvent = Payload<'project'>;
type ReleaseEvent = Payload<'release'>;
type RepositoryEvent = Payload<'repository'>;
type VulnerabilityAlertEvent = Payload<'repository-vulnerability-alert'>;

export interface DiscordEmbed {
	title: string;
	description?: string;
	url?: string;
	color?: number;
	author: { name: string; url: string; icon_url: string };
	footer?: { text: string };
	fields?: { name: string; value: string }[];
}

export interface DiscordMessage {
	embeds: DiscordEmbed[];
}

/** Events we deliberately never forward. */
const IGNORED_EVENTS = new Set(['fork', 'watch', 'star', 'status']);

/**
 * Converts a GitHub webhook payload into a Discord webhook message.
 *
 * @throws {IgnoredEventError} for events we deliberately skip.
 * @throws {NotImplementedError} for events (or actions) we do not format.
 */
export function getEmbed(eventType: string, payload: unknown): DiscordMessage {
	if (IGNORED_EVENTS.has(eventType)) {
		throw new IgnoredEventError(eventType);
	}

	const embed = format(eventType, payload);

	if (!embed.description) {
		delete embed.description;
	}

	return { embeds: [embed] };
}

function format(eventType: string, payload: unknown): DiscordEmbed {
	switch (eventType) {
		case 'ping':
			return formatPing(payload as PingEvent);
		case 'push':
			return formatPush(payload as PushEvent);
		case 'delete':
			return formatDelete(payload as DeleteEvent);
		case 'discussion':
			return formatDiscussion(payload as DiscussionEvent);
		case 'discussion_comment':
			return formatComment(eventType, payload as DiscussionCommentEvent, 'discussion', (payload as DiscussionCommentEvent).discussion);
		case 'public':
			return formatPublic(payload as PublicEvent);
		case 'issues':
			return formatIssues(payload as IssuesEvent);
		case 'member':
			return formatMember(payload as MemberEvent);
		case 'gollum':
			return formatGollum(payload as GollumEvent);
		case 'package':
			return formatPackage(payload as PackageEvent);
		case 'project':
			return formatProject(payload as ProjectEvent);
		case 'release':
			return formatRelease(payload as ReleaseEvent);
		case 'milestone':
			return formatMilestone(payload as MilestoneEvent);
		case 'repository':
			return formatRepository(payload as RepositoryEvent);
		case 'pull_request':
			return formatPullRequest(payload as PullRequestEvent);
		case 'issue_comment': {
			const { issue } = payload as IssueCommentEvent;

			return formatComment(eventType, payload as IssueCommentEvent, issue.pull_request ? 'PR' : 'issue', issue);
		}
		case 'commit_comment':
			return formatCommitComment(payload as CommitCommentEvent);
		case 'pull_request_review':
			return formatPullRequestReview(payload as PullRequestReviewEvent);
		case 'pull_request_review_comment':
			return formatPullRequestReviewComment(payload as PullRequestReviewCommentEvent);
		case 'repository_vulnerability_alert':
			return formatVulnerabilityAlert(payload as VulnerabilityAlertEvent);
		default:
			throw new NotImplementedError(eventType);
	}
}

/** Most, but not all, payload schemas mark `sender` as optional; GitHub always sends it. */
type Sender = { login: string; html_url: string; avatar_url: string } | null | undefined;

function formatAuthor(sender: Sender): DiscordEmbed['author'] {
	if (!sender) {
		throw new BadRequestError('Payload is missing the sender.');
	}

	return {
		name: sender.login,
		url: sender.html_url,
		icon_url: sender.avatar_url,
	};
}

const DEFAULT_COLOR = 5025616;

/** Throws unless the action is one that gets formatted, ignored actions are checked first. */
function assertAction(event: string, action: string, supported: readonly string[], ignored: readonly string[] = []): void {
	if (ignored.includes(action)) {
		throw new IgnoredEventError(`${event} - ${action}`);
	}

	if (!supported.includes(action)) {
		throw new NotImplementedError(event, action);
	}
}

function actionColor(action: string): number {
	switch (action) {
		case 'enabled auto-merge':
		case 'created':
		case 'resolved':
		case 'reopened':
			return 16750592;

		case 'locked':
		case 'deleted':
		case 'dismissed':
		case 'unpublished':
		case 'force-pushed':
		case 'requested changes in':
		case 'closed without merging':
			return 16007990;

		case 'closed as not planned':
		case 'closed':
			return 8540383;

		case 'merged':
			return 7291585;

		default:
			return DEFAULT_COLOR;
	}
}

/** `refs/heads/some/branch` -> `some/branch`. */
function refName(ref: string): string {
	return ref.replace(/^refs\/[^/]+\//, '');
}

function shortSha(sha: string): string {
	return sha.slice(0, 6);
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#ping
 */
function formatPing(payload: PingEvent): DiscordEmbed {
	return {
		title: `Hook ${payload.hook?.id} worked!`,
		description: escape(payload.zen ?? ''),
		color: DEFAULT_COLOR,
		author: formatAuthor(payload.sender),
	};
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#push
 */
function formatPush(payload: PushEvent): DiscordEmbed {
	const commits = payload.commits.filter((commit) => commit.distinct !== false && commit.message);
	const ref = escapeCode(refName(payload.ref));
	const baseRef = payload.base_ref ? escapeCode(refName(payload.base_ref)) : null;
	const newCommits = `${commits.length} new commit${commits.length === 1 ? '' : 's'}`;

	const embed: DiscordEmbed = {
		title: '',
		url: payload.compare,
		author: formatAuthor(payload.sender),
	};

	if (payload.created) {
		if (payload.ref.startsWith('refs/tags/')) {
			embed.title = `tagged ${ref} at ${baseRef ?? escapeCode(shortSha(payload.after))}`;
			embed.color = actionColor('tagged');
		} else {
			embed.title = `created ${ref}`;

			if (baseRef) {
				embed.title += ` from ${baseRef}`;
			} else if (commits.length > 0) {
				embed.title += ` at ${escapeCode(shortSha(payload.after))}`;
			}

			if (commits.length > 0) {
				embed.title += ` (+${newCommits})`;
			}
		}
	} else if (payload.deleted) {
		throw new NotImplementedError('push', 'deleted (use DeleteEvent if needed)');
	} else if (payload.forced) {
		embed.title = `force-pushed ${ref} from ${escape(shortSha(payload.before))} to ${escape(shortSha(payload.after))}`;
		embed.color = actionColor('force-pushed');
	} else if (commits.length === 0 && payload.commits.length > 0) {
		if (baseRef) {
			embed.title = `merged ${baseRef} into ${ref}`;
			embed.color = actionColor('merged');
		} else {
			embed.title = `fast-forwarded ${ref} from ${escape(shortSha(payload.before))} to ${escape(shortSha(payload.after))}`;
			embed.color = actionColor('fast-forwarded');
		}
	} else {
		embed.title = `pushed ${newCommits} to ${ref}`;
	}

	if (payload.forced) {
		// GitHub supports displaying proper diffs for force pushes, but it only appears to work
		// if the diff url has full hashes, so we construct the url ourselves instead of using
		// the one in the payload. ".." instead of "..." makes GitHub display the changes between
		// the commits rather than the entire diff of the force push.
		embed.url = `${payload.repository.html_url}/compare/${payload.before}..${payload.after}`;
	}

	if (commits.length > 0) {
		// Newest commits first, and never more than five of them
		embed.description = commits
			.slice(-5)
			.reverse()
			.map((commit) => {
				let line = `[${escapeCode(shortSha(commit.id))}](${commit.url}) ${shortMessage(commit.message)}`;

				if (commit.author.username) {
					if (commit.author.username !== payload.sender?.login) {
						line += ` - ${escape(commit.author.username)}`;
					}
				} else {
					line += ` - *${escape(commit.author.name)}*`;
				}

				return line;
			})
			.join('\n');
	}

	return embed;
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#delete
 */
function formatDelete(payload: DeleteEvent): DiscordEmbed {
	if (payload.ref_type !== 'tag' && payload.ref_type !== 'branch') {
		throw new NotImplementedError('delete', payload.ref_type);
	}

	return {
		title: `deleted ${payload.ref_type} ${escapeCode(payload.ref)}`,
		url: payload.repository.html_url,
		color: actionColor('deleted'),
		author: formatAuthor(payload.sender),
	};
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#issues
 */
function formatIssues(payload: IssuesEvent): DiscordEmbed {
	assertAction(
		'issues',
		payload.action,
		['opened', 'closed', 'reopened', 'deleted', 'pinned', 'locked', 'unlocked', 'transferred'],
		['edited', 'unpinned', 'milestoned', 'demilestoned', 'labeled', 'unlabeled', 'assigned', 'unassigned'],
	);

	const action =
		payload.action === 'closed' && payload.issue.state_reason === 'not_planned' ? 'closed as not planned' : payload.action;

	const embed: DiscordEmbed = {
		title: `Issue **#${payload.issue.number}** ${action}: ${escape(payload.issue.title)}`,
		url: payload.issue.html_url,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};

	if (payload.action === 'opened') {
		embed.description = shortDescription(payload.issue.body);

		if (payload.issue.labels && payload.issue.labels.length > 0) {
			embed.footer = { text: payload.issue.labels.map((label) => label.name).join(' | ') };
		}
	}

	return embed;
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#pull_request
 */
function formatPullRequest(payload: PullRequestEvent): DiscordEmbed {
	let action: string = payload.action;

	if (payload.action === 'closed') {
		action = payload.pull_request.merged ? 'merged' : 'closed without merging';
	} else if (payload.action === 'ready_for_review') {
		action = 'readied';
	} else if (payload.action === 'auto_merge_enabled') {
		action = 'enabled auto-merge';
	} else if (payload.action === 'converted_to_draft') {
		action = 'converted to draft';
	}

	assertAction(
		'pull_request',
		action,
		[
			'opened',
			'reopened',
			'deleted',
			'merged',
			'locked',
			'unlocked',
			'readied',
			'enabled auto-merge',
			'converted to draft',
			'closed without merging',
		],
		['edited', 'synchronize', 'labeled', 'unlabeled', 'assigned', 'unassigned', 'review_requested', 'review_request_removed'],
	);

	const embed: DiscordEmbed = {
		title: `${payload.pull_request.draft ? 'Draft ' : ''}PR **#${payload.pull_request.number}** ${action}: ${escape(payload.pull_request.title)}`,
		url: payload.pull_request.html_url,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};

	if (action === 'opened') {
		embed.description = shortDescription(payload.pull_request.body);
	} else if (action === 'merged') {
		embed.description = `Merged from **${escape(payload.pull_request.user?.login ?? '')}** to ${escapeCode(payload.pull_request.base.ref)}`;
	}

	return embed;
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#milestone
 */
function formatMilestone(payload: MilestoneEvent): DiscordEmbed {
	assertAction('milestone', payload.action, ['opened', 'closed', 'created', 'deleted'], ['edited']);

	return {
		title: `${payload.action} milestone **#${payload.milestone.number}**: ${escape(payload.milestone.title)}`,
		description: shortDescription(payload.milestone.description),
		url: payload.milestone.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#package
 */
function formatPackage(payload: PackageEvent): DiscordEmbed {
	assertAction('package', payload.action, ['published', 'updated']);

	return {
		title: `${payload.action} ${payload.package.package_type} package: **${escape(payload.package.name)}** ${payload.package.package_version?.version}`,
		description: shortDescription(payload.package.package_version?.body as string | null | undefined),
		url: payload.package.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#project
 */
function formatProject(payload: ProjectEvent): DiscordEmbed {
	assertAction('project', payload.action, ['created', 'closed', 'reopened', 'deleted'], ['edited']);

	return {
		title: `${payload.action} project **#${payload.project.number}**: ${escape(payload.project.name)}`,
		description: shortDescription(payload.project.body),
		url: payload.project.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#release
 */
function formatRelease(payload: ReleaseEvent): DiscordEmbed {
	assertAction('release', payload.action, ['published', 'unpublished']);

	let name = payload.release.tag_name;

	if (payload.release.name && payload.release.name !== payload.release.tag_name) {
		name += ` (${payload.release.name})`;
	}

	const kind = `${payload.release.draft ? 'draft ' : ''}${payload.release.prerelease ? 'pre-' : ''}release`;

	return {
		title: `${payload.action} a ${kind}: ${escape(name)}`,
		description: shortDescription(payload.release.body),
		url: payload.release.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#commit_comment
 */
function formatCommitComment(payload: CommitCommentEvent): DiscordEmbed {
	assertAction('commit_comment', payload.action, ['created']);

	return {
		title: `commented on commit ${escapeCode(shortSha(payload.comment.commit_id))}`,
		description: shortDescription(payload.comment.body),
		url: payload.comment.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

/**
 * Comments on issues, pull requests and discussions.
 *
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#issue_comment
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#discussion_comment
 */
function formatComment(
	event: string,
	payload: IssueCommentEvent | DiscussionCommentEvent,
	kind: string,
	subject: { number: number; title: string },
): DiscordEmbed {
	assertAction(event, payload.action, ['created', 'deleted'], ['edited']);

	const embed: DiscordEmbed = {
		title: `commented on ${kind} **#${subject.number}**: ${escape(subject.title)}`,
		description: shortDescription(payload.comment.body),
		url: payload.comment.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};

	if (payload.action === 'deleted') {
		embed.title = `deleted comment in ${kind} **#${subject.number}** from ${payload.comment.user?.login}`;
		delete embed.description;
	}

	return embed;
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#pull_request_review
 */
function formatPullRequestReview(payload: PullRequestReviewEvent): DiscordEmbed {
	if (payload.action !== 'submitted') {
		throw new NotImplementedError('pull_request_review', payload.action);
	}

	if (payload.review.state === 'commented') {
		throw new IgnoredEventError(`pull_request_review - ${payload.review.state}`);
	}

	const state = payload.review.state === 'changes_requested' ? 'requested changes in' : payload.review.state;

	return {
		title: `${state} PR **#${payload.pull_request.number}**: ${escape(payload.pull_request.title)}`,
		description: shortDescription(payload.review.body),
		url: payload.review.html_url,
		color: actionColor(state),
		author: formatAuthor(payload.sender),
	};
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#pull_request_review_comment
 */
function formatPullRequestReviewComment(payload: PullRequestReviewCommentEvent): DiscordEmbed {
	assertAction('pull_request_review_comment', payload.action, ['created']);

	return {
		title: `reviewed PR **#${payload.pull_request.number}**: ${escape(payload.pull_request.title)}`,
		description: shortDescription(payload.comment.body),
		url: payload.comment.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#discussion
 */
function formatDiscussion(payload: DiscussionEvent): DiscordEmbed {
	const action = payload.action === 'category_changed' ? 'changed category' : payload.action;

	assertAction(
		'discussion',
		action,
		['created', 'deleted', 'pinned', 'unpinned', 'locked', 'unlocked', 'transferred', 'changed category'],
		['edited', 'labeled', 'unlabeled', 'answered', 'unanswered'],
	);

	const embed: DiscordEmbed = {
		title: `${payload.discussion.category.emoji} Discussion **#${payload.discussion.number}** ${action}: ${escape(payload.discussion.title)}`,
		url: payload.discussion.html_url,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};

	if (action === 'created') {
		embed.description = shortDescription(payload.discussion.body);
	}

	return embed;
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#repository_vulnerability_alert
 */
function formatVulnerabilityAlert(payload: VulnerabilityAlertEvent): DiscordEmbed {
	if (payload.action === 'create') {
		return {
			title: `⚠ New vulnerability for **${escape(payload.alert.affected_package_name)}**`,
			url: payload.alert.external_reference ?? undefined,
			color: actionColor(payload.action),
			author: formatAuthor(payload.sender),
			fields: [
				{ name: 'Affected range', value: escape(payload.alert.affected_range) },
				{ name: 'Fixed in', value: escape(payload.alert.fixed_in ?? '') },
				{ name: 'Identifier', value: escape(payload.alert.external_identifier) },
			],
		};
	}

	assertAction('repository_vulnerability_alert', payload.action, ['resolve', 'dismiss']);

	const action = payload.action === 'resolve' ? 'resolved' : 'dismissed';

	return {
		title: `Vulnerability for **${escape(payload.alert.affected_package_name)}** ${action}`,
		url: payload.alert.external_reference ?? undefined,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#member
 */
function formatMember(payload: MemberEvent): DiscordEmbed {
	assertAction('member', payload.action, ['added', 'removed']);

	return {
		title: `${payload.action} **${escape(payload.member?.login ?? '')}** as a collaborator`,
		url: payload.repository.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#gollum
 */
function formatGollum(payload: GollumEvent): DiscordEmbed {
	const lines = payload.pages.map((page) => {
		// Append compare url since GitHub doesn't provide one
		const url = page.action === 'edited' ? `${page.html_url}/_compare/${page.sha}` : page.html_url;
		const summary = page.summary ? `: ${shortMessage(page.summary)}` : '';

		return `[${page.action} ${escape(page.title)}](${url})${summary}`;
	});

	return {
		title: 'updated wiki',
		description: lines.join('\n'),
		color: DEFAULT_COLOR,
		author: formatAuthor(payload.sender),
	};
}

/**
 * Without a doubt: the best GitHub event.
 *
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#public
 */
function formatPublic(payload: PublicEvent): DiscordEmbed {
	return {
		title: `${escape(payload.repository.name)} is now open source and available to everyone!`,
		url: payload.repository.html_url,
		color: DEFAULT_COLOR,
		author: formatAuthor(payload.sender),
	};
}

/**
 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads#repository
 */
function formatRepository(payload: RepositoryEvent): DiscordEmbed {
	assertAction(
		'repository',
		payload.action,
		['created', 'deleted', 'archived', 'unarchived', 'transferred', 'renamed', 'publicized', 'privatized'],
		['edited'],
	);

	let title = `${payload.action} **${escape(payload.repository.name)}**`;

	if (payload.action === 'renamed') {
		title += ` (from *${escape(payload.changes.repository.name.from)}*)`;
	} else if (payload.action === 'transferred') {
		const from = payload.changes.owner.from;

		if (from.user) {
			title += ` (from *${escape(from.user.login)}*)`;
		} else if (from.organization) {
			title += ` (from *${escape(from.organization.login)}*)`;
		}
	}

	return {
		title,
		url: payload.repository.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}
