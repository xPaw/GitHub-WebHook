import type { operations } from '@octokit/openapi-webhooks-types';
import { BadRequestError, IgnoredEventError, NotImplementedError } from '../errors.js';
import { escape, escapeCode, limitLength, shortDescription, shortMessage } from './text.js';

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
type RegistryPackageEvent = Payload<'registry-package'>;
type ReleaseEvent = Payload<'release'>;
type RepositoryEvent = Payload<'repository'>;
type RepositoryAdvisoryEvent = Payload<'repository-advisory'>;
type DependabotAlertEvent = Payload<'dependabot-alert'>;
type CodeScanningAlertEvent = Payload<'code-scanning-alert'>;
type SecretScanningAlertEvent = Payload<'secret-scanning-alert'>;

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
	/** Overrides the name of the Discord webhook for this message. */
	username?: string;
	/** Overrides the avatar of the Discord webhook for this message. */
	avatar_url?: string;
	embeds: DiscordEmbed[];
}

const MAX_TITLE_LENGTH = 256;
const MAX_DESCRIPTION_LENGTH = 4096;

/** Events we deliberately never forward, new branches and tags are formatted from `push` which has the commits. */
const IGNORED_EVENTS = new Set(['create', 'fork', 'watch', 'star', 'status']);

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

	// Discord rejects the whole message when an embed is over its limits
	embed.title = limitLength(embed.title, MAX_TITLE_LENGTH);

	if (embed.description) {
		embed.description = limitLength(embed.description, MAX_DESCRIPTION_LENGTH);
	} else {
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
			return formatPackage(eventType, (payload as PackageEvent).package, payload as PackageEvent);
		case 'registry_package':
			return formatPackage(eventType, (payload as RegistryPackageEvent).registry_package, payload as RegistryPackageEvent);
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
		case 'repository_advisory':
			return formatRepositoryAdvisory(payload as RepositoryAdvisoryEvent);
		case 'dependabot_alert':
			return formatDependabotAlert(payload as DependabotAlertEvent);
		case 'code_scanning_alert':
			return formatCodeScanningAlert(payload as CodeScanningAlertEvent);
		case 'secret_scanning_alert':
			return formatSecretScanningAlert(payload as SecretScanningAlertEvent);
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
		case 'reintroduced':
			return 16750592;

		case 'locked':
		case 'deleted':
		case 'dismissed':
		case 'auto-dismissed':
		case 'publicly leaked':
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

function formatPing(payload: PingEvent): DiscordEmbed {
	return {
		title: `Hook ${payload.hook?.id} worked!`,
		description: escape(payload.zen ?? ''),
		color: DEFAULT_COLOR,
		author: formatAuthor(payload.sender),
	};
}

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

function formatIssues(payload: IssuesEvent): DiscordEmbed {
	assertAction(
		'issues',
		payload.action,
		['opened', 'closed', 'reopened', 'deleted', 'pinned', 'locked', 'unlocked', 'transferred'],
		['edited', 'unpinned', 'milestoned', 'demilestoned', 'labeled', 'unlabeled', 'assigned', 'unassigned', 'typed', 'untyped'],
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

/** Both `package` and `registry_package` have the same payload under a different name. */
function formatPackage(
	event: string,
	pkg: PackageEvent['package'] | RegistryPackageEvent['registry_package'],
	payload: PackageEvent | RegistryPackageEvent,
): DiscordEmbed {
	assertAction(event, payload.action, ['published', 'updated']);

	const body = pkg.package_version?.body;

	return {
		title: `${payload.action} ${pkg.package_type} package: **${escape(pkg.name)}** ${escape(pkg.package_version?.version ?? 'unknown')}`,
		// Container packages have an empty object as their body
		description: shortDescription(typeof body === 'string' ? body : null),
		url: pkg.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

function formatRelease(payload: ReleaseEvent): DiscordEmbed {
	assertAction('release', payload.action, ['published', 'unpublished', 'deleted'], ['created', 'edited', 'released', 'prereleased']);

	let name = payload.release.tag_name;

	if (payload.release.name && payload.release.name !== payload.release.tag_name) {
		name += ` (${payload.release.name})`;
	}

	const kind = `${payload.release.draft ? 'draft ' : ''}${payload.release.prerelease ? 'pre-' : ''}release`;

	return {
		title: `${payload.action} a ${kind}: ${escape(name)}`,
		// Release notes are only worth showing when the release appears
		description: payload.action === 'published' ? shortDescription(payload.release.body) : '',
		url: payload.release.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

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

/** Comments on issues, pull requests and discussions. */
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

function formatPullRequestReview(payload: PullRequestReviewEvent): DiscordEmbed {
	assertAction('pull_request_review', payload.action, ['submitted', 'dismissed'], ['edited']);

	let state: string = payload.review.state;

	if (payload.action === 'dismissed') {
		state = 'dismissed';
	} else if (state === 'commented') {
		throw new IgnoredEventError(`pull_request_review - ${state}`);
	} else if (state === 'changes_requested') {
		state = 'requested changes in';
	}

	return {
		title: `${state}${state === 'dismissed' ? ' a review on' : ''} PR **#${payload.pull_request.number}**: ${escape(payload.pull_request.title)}`,
		// The body of a dismissed review is what the reviewer wrote, not why it was dismissed
		description: state === 'dismissed' ? '' : shortDescription(payload.review.body),
		url: payload.review.html_url,
		color: actionColor(state),
		author: formatAuthor(payload.sender),
	};
}

function formatPullRequestReviewComment(payload: PullRequestReviewCommentEvent): DiscordEmbed {
	assertAction('pull_request_review_comment', payload.action, ['created'], ['edited', 'deleted']);

	return {
		title: `reviewed PR **#${payload.pull_request.number}**: ${escape(payload.pull_request.title)}`,
		description: shortDescription(payload.comment.body),
		url: payload.comment.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

function formatDiscussion(payload: DiscussionEvent): DiscordEmbed {
	const action = payload.action === 'category_changed' ? 'changed category' : payload.action;

	assertAction(
		'discussion',
		action,
		['created', 'deleted', 'pinned', 'unpinned', 'locked', 'unlocked', 'transferred', 'answered', 'closed', 'reopened', 'changed category'],
		['edited', 'labeled', 'unlabeled', 'unanswered'],
	);

	const embed: DiscordEmbed = {
		title: `${payload.discussion.category.emoji} Discussion **#${payload.discussion.number}** ${action}: ${escape(payload.discussion.title)}`,
		url: payload.action === 'answered' ? payload.answer.html_url : payload.discussion.html_url,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};

	if (action === 'created') {
		embed.description = shortDescription(payload.discussion.body);
	}

	return embed;
}

function formatDependabotAlert(payload: DependabotAlertEvent): DiscordEmbed {
	let action: string = payload.action;

	if (payload.action === 'auto_dismissed') {
		action = 'auto-dismissed';
	} else if (payload.action === 'auto_reopened') {
		action = 'reopened';
	}

	assertAction('dependabot_alert', action, ['created', 'fixed', 'dismissed', 'auto-dismissed', 'reopened', 'reintroduced']);

	const advisory = payload.alert.security_advisory;
	const vulnerability = payload.alert.security_vulnerability;

	const embed: DiscordEmbed = {
		title: `Dependabot alert **#${payload.alert.number}** ${action} for **${escape(vulnerability.package.name)}**: ${escape(advisory.summary)}`,
		url: payload.alert.html_url,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};

	if (action === 'created') {
		embed.title = `⚠ ${embed.title}`;
		embed.fields = [
			{ name: 'Severity', value: escape(advisory.severity) },
			{ name: 'Affected range', value: escape(vulnerability.vulnerable_version_range) },
		];

		if (vulnerability.first_patched_version) {
			embed.fields.push({ name: 'Fixed in', value: escape(vulnerability.first_patched_version.identifier) });
		}

		embed.fields.push({ name: 'Identifier', value: escape(advisory.cve_id ?? advisory.ghsa_id) });
	}

	return embed;
}

function formatCodeScanningAlert(payload: CodeScanningAlertEvent): DiscordEmbed {
	let action: string = payload.action;

	if (payload.action === 'closed_by_user') {
		action = 'dismissed';
	} else if (payload.action === 'reopened_by_user') {
		action = 'reopened';
	}

	assertAction('code_scanning_alert', action, ['created', 'fixed', 'dismissed', 'reopened'], ['appeared_in_branch']);

	const embed: DiscordEmbed = {
		title: `Code scanning alert **#${payload.alert.number}** ${action}: ${escape(payload.alert.rule.description)}`,
		url: payload.alert.html_url,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};

	if (action === 'created') {
		embed.title = `⚠ ${embed.title}`;
		embed.description = shortDescription(payload.alert.most_recent_instance?.message?.text);
		embed.fields = [
			{ name: 'Severity', value: escape(payload.alert.rule.severity ?? 'none') },
			{ name: 'Tool', value: escape(payload.alert.tool?.name ?? 'unknown') },
		];
	}

	return embed;
}

function formatSecretScanningAlert(payload: SecretScanningAlertEvent): DiscordEmbed {
	const action = payload.action === 'publicly_leaked' ? 'publicly leaked' : payload.action;

	assertAction(
		'secret_scanning_alert',
		action,
		['created', 'resolved', 'reopened', 'publicly leaked'],
		['assigned', 'unassigned', 'validated'],
	);

	const { alert } = payload;
	const secretType = alert.secret_type_display_name ?? alert.secret_type ?? 'unknown';

	const embed: DiscordEmbed = {
		title: `Secret scanning alert **#${alert.number}** ${action}: ${escape(secretType)}`,
		url: alert.html_url,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};

	if (action === 'created' || action === 'publicly leaked') {
		embed.title = `⚠ ${embed.title}`;
	}

	if (action === 'created' && alert.push_protection_bypassed_by) {
		embed.description = `Push protection bypassed by **${escape(alert.push_protection_bypassed_by.login)}**`;
	} else if (action === 'resolved' && alert.resolution) {
		embed.description = `Resolved as ${escape(alert.resolution.replaceAll('_', ' '))}`;
	}

	return embed;
}

function formatRepositoryAdvisory(payload: RepositoryAdvisoryEvent): DiscordEmbed {
	assertAction('repository_advisory', payload.action, ['published', 'reported']);

	const advisory = payload.repository_advisory;

	if (payload.action === 'reported') {
		// Reported advisories are private, so do not reveal what they are about
		return {
			title: `⚠ privately reported a vulnerability: **${escape(advisory.ghsa_id)}**`,
			url: advisory.html_url,
			color: actionColor('created'),
			author: formatAuthor(payload.sender),
		};
	}

	return {
		title: `published a security advisory: ${escape(advisory.summary)}`,
		description: shortDescription(advisory.description),
		url: advisory.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
		fields: [
			{ name: 'Severity', value: escape(advisory.severity ?? 'unknown') },
			{ name: 'Identifier', value: escape(advisory.cve_id ?? advisory.ghsa_id) },
		],
	};
}

function formatMember(payload: MemberEvent): DiscordEmbed {
	assertAction('member', payload.action, ['added', 'removed'], ['edited']);

	return {
		title: `${payload.action} **${escape(payload.member?.login ?? '')}** as a collaborator`,
		url: payload.repository.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

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

function formatPublic(payload: PublicEvent): DiscordEmbed {
	return {
		title: `${escape(payload.repository.name)} is now open source and available to everyone!`,
		url: payload.repository.html_url,
		color: DEFAULT_COLOR,
		author: formatAuthor(payload.sender),
	};
}

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
