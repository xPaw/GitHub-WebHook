import type { operations } from '@octokit/openapi-webhooks-types';
import type {
	APIContainerComponent,
	APIMessageTopLevelComponent,
	APITextDisplayComponent,
	ComponentType,
	MessageFlags,
} from 'discord-api-types/v10';
import { BadRequestError, IgnoredEventError, NotImplementedError } from '../errors.js';
import { escape, escapeCode, formatBody, limitLength, shortMessage } from './text.js';

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
type ProjectEvent = Payload<'project'>;
type ProjectV2Event = Payload<'projects-v2'>;
type ProjectStatusUpdateEvent = Payload<'projects-v2-status-update'>;
type BranchProtectionConfigurationEvent = Payload<'branch-protection-configuration'>;
type BranchProtectionRuleEvent = Payload<'branch-protection-rule'>;
type RepositoryRulesetEvent = Payload<'repository-ruleset'>;
type DeployKeyEvent = Payload<'deploy-key'>;
type MetaEvent = Payload<'meta'>;
type OrganizationEvent = Payload<'organization'>;
type OrgBlockEvent = Payload<'org-block'>;
type MembershipEvent = Payload<'membership'>;
type TeamEvent = Payload<'team'>;
type SponsorshipEvent = Payload<'sponsorship'>;
type WorkflowRunEvent = Payload<'workflow-run'>;

/** What every event is formatted into, before it is laid out as components. */
export interface DiscordEmbed {
	/**
	 * The start of the sentence that the sender opens, which links to the event. Discord renders
	 * markdown in the text of a link but can not escape any of it there, so this only ever holds
	 * our own words, numbers, logins and hashes, none of which have anything in them to escape.
	 */
	title: string;
	/** The rest of the sentence, after the link: whatever somebody else wrote, escaped. */
	titleEnd?: string;
	description?: string;
	url?: string;
	color?: number;
	author: { name: string; icon_url: string };
	footer?: { text: string };
}

export interface DiscordMessage {
	flags: MessageFlags.IsComponentsV2;
	/** Who the message is shown as being from, left out when Discord will not take the name. */
	username?: string;
	avatar_url?: string;
	components: APIMessageTopLevelComponent[];
}

/**
 * Discord turns down a webhook username holding either of these, in any case and anywhere in it,
 * so that nothing can pass itself off as Discord or as its own bot. Neither is documented,
 * and a GitHub login is allowed to contain both.
 */
const BLOCKED_USERNAME = /discord|clyde/i;

// The enums of discord-api-types are values, and importing one would put the whole of it in the
// bundle. Every number here is checked against the member it names, which is all they are wanted for.
const TEXT_DISPLAY: ComponentType.TextDisplay = 10;
const CONTAINER: ComponentType.Container = 17;
/** Turns the message into components, which take the place of `content` and `embeds`. */
const IS_COMPONENTS_V2: MessageFlags.IsComponentsV2 = 32768;

/** Discord turns a message down when the text of all of its components adds up to more than this. */
const MAX_MESSAGE_LENGTH = 4000;

const MAX_WIKI_PAGES = 5;
const MAX_PUSH_COMMITS = 15;

/**
 * Converts a GitHub webhook payload into a Discord webhook message.
 *
 * @throws {IgnoredEventError} for actions we deliberately skip.
 * @throws {NotImplementedError} for events (or actions) we do not format.
 */
export function getEmbed(eventType: string, payload: unknown): DiscordMessage {
	const embed = format(eventType, payload);
	const scope = formatScope(payload as ScopePayload);

	// The sender opens the sentence the title finishes, and the start of it links to the event.
	// Who did it is in the card itself, so a message that can not be sent as them still says so.
	// A login is only letters, digits and hyphens, and the "[bot]" of an app is a pair of brackets
	// that the text of a link may hold, so the sender needs no escaping in there.
	const end = embed.titleEnd ?? '';
	const heading =
		embed.url === undefined
			? `### ${escape(embed.author.name)} ${embed.title}${end}`
			: `### [${embed.author.name} ${embed.title}](${embed.url})${end}`;

	// Several repositories usually share a webhook, so the card says where the event happened
	const parts = [scope === null ? heading : `-# ${escape(scope)}\n${heading}`];

	if (embed.footer) {
		// Everything the layout did not compose itself is somebody else's text in a markdown line
		parts.push(`-# ${escape(embed.footer.text)}`);
	}

	if (embed.description) {
		parts.push(embed.description);
	}

	// Each part in turn takes what the ones before it left, so the body gives way to the heading
	// rather than the message being turned down for the two of them together
	let room = MAX_MESSAGE_LENGTH;
	const components: APITextDisplayComponent[] = [];

	for (const part of parts) {
		// An ellipsis of its own would say nothing, and would still be over the limit
		if (room < 1) {
			break;
		}

		const cut = limitLength(part, room);

		room -= [...cut].length;
		components.push({ type: TEXT_DISPLAY, content: cut });
	}

	const container: APIContainerComponent = { type: CONTAINER, accent_color: embed.color, components };
	const message: DiscordMessage = { flags: IS_COMPONENTS_V2, components: [container] };

	if (!BLOCKED_USERNAME.test(embed.author.name)) {
		// Saying where the name is from keeps it from reading as a Discord account of the same name,
		// and it is what stops a sender called "everyone" or "here" being turned down as well
		message.username = `${embed.author.name} on GitHub`;
		message.avatar_url = embed.author.icon_url;
	}

	return message;
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
			return formatComment(eventType, payload as DiscussionCommentEvent);
		case 'public':
			return formatPublic(payload as PublicEvent);
		case 'issues':
			return formatIssues(payload as IssuesEvent);
		case 'member':
			return formatMember(payload as MemberEvent);
		case 'gollum':
			return formatGollum(payload as GollumEvent);
		case 'package':
			return formatPackage(eventType, payload as PackageEvent);
		case 'registry_package':
			return formatPackage(eventType, payload as RegistryPackageEvent);
		case 'release':
			return formatRelease(payload as ReleaseEvent);
		case 'milestone':
			return formatMilestone(payload as MilestoneEvent);
		case 'repository':
			return formatRepository(payload as RepositoryEvent);
		case 'pull_request':
			return formatPullRequest(payload as PullRequestEvent);
		case 'issue_comment':
			return formatComment(eventType, payload as IssueCommentEvent);
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
		case 'project':
			return formatProject(payload as ProjectEvent);
		case 'projects_v2':
			return formatProjectV2(payload as ProjectV2Event);
		case 'projects_v2_status_update':
			return formatProjectStatusUpdate(payload as ProjectStatusUpdateEvent);
		case 'branch_protection_configuration':
			return formatBranchProtectionConfiguration(payload as BranchProtectionConfigurationEvent);
		case 'branch_protection_rule':
			return formatBranchProtectionRule(payload as BranchProtectionRuleEvent);
		case 'repository_ruleset':
			return formatRepositoryRuleset(payload as RepositoryRulesetEvent);
		case 'deploy_key':
			return formatDeployKey(payload as DeployKeyEvent);
		case 'meta':
			return formatMeta(payload as MetaEvent);
		case 'organization':
			return formatOrganization(payload as OrganizationEvent);
		case 'org_block':
			return formatOrgBlock(payload as OrgBlockEvent);
		case 'membership':
			return formatMembership(payload as MembershipEvent);
		case 'team':
			return formatTeam(payload as TeamEvent);
		case 'sponsorship':
			return formatSponsorship(payload as SponsorshipEvent);
		case 'workflow_run':
			return formatWorkflowRun(payload as WorkflowRunEvent);
		default:
			throw new NotImplementedError(eventType);
	}
}

/**
 * Most, but not all, payload schemas mark `sender` as optional, and some of them describe a user
 * with urls that are all optional; GitHub always sends the user and their urls.
 */
type Sender = { login: string; html_url?: string; avatar_url?: string } | null | undefined;

function formatAuthor(sender: Sender): DiscordEmbed['author'] {
	if (!sender) {
		throw new BadRequestError('Payload is missing the sender.');
	}

	return {
		name: sender.login,
		// GitHub always sends an avatar, and its own url for one stands in if it ever does not
		icon_url: sender.avatar_url ?? `https://github.com/${sender.login}.png`,
	};
}

interface ScopePayload {
	repository?: { name: string };
	organization?: { login: string };
	sponsorship?: { sponsorable?: { login: string } | null };
	hook?: { type?: string };
	sender?: { login: string } | null;
}

/**
 * Names the repository of an event, or with an `@` the account of an event that has no repository:
 * the organization, or the sponsored account of a sponsors listing, whose ping only knows who set the webhook up.
 */
function formatScope({ repository, organization, sponsorship, hook, sender }: ScopePayload): string | null {
	if (repository) {
		return repository.name;
	}

	const account = organization ?? sponsorship?.sponsorable ?? (hook?.type === 'SponsorsListing' ? sender : null);

	return account ? `@${account.login}` : null;
}

/** Something new, or something that went well. */
const COLOR_DEFAULT = 5025616;
/** Something changed, which is neither good nor bad. */
const COLOR_NEUTRAL = 3113463;
/** Something needs attention. */
const COLOR_ATTENTION = 16750592;
/** Something was removed or rejected. */
const COLOR_BAD = 16007990;
/** Something was finished. */
const COLOR_CLOSED = 8540383;
/** Something was set aside. */
const COLOR_SET_ASIDE = 7239297;

/**
 * Looks a key that came from a payload up in a plain object. Reading the key directly would find
 * an inherited name such as `constructor`, which is a function rather than a value we know.
 */
function lookup<T>(map: Record<string, T>, key: string): T | undefined {
	return Object.hasOwn(map, key) ? map[key] : undefined;
}

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
		case 'reopened':
		case 'reintroduced':
		case 'at risk':
			return COLOR_ATTENTION;

		case 'deleted':
		case 'removed':
		case 'blocked':
		case 'disabled':
		case 'off track':
		case 'publicly leaked':
		case 'unpublished':
		case 'failed':
		case 'timed out':
		case 'failed to start':
		case 'requested changes in':
		case 'closed without merging':
			return COLOR_BAD;

		case 'dismissed':
		case 'auto-dismissed':
		case 'converted to draft':
		case 'archived':
		case 'inactive':
		case 'closed as not planned':
			return COLOR_SET_ASIDE;

		case 'pinned':
		case 'unpinned':
		case 'locked':
		case 'unlocked':
		case 'transferred':
		case 'renamed':
		case 'edited':
		case 'unblocked':
		case 'changed category':
		case 'publicized':
		case 'privatized':
		case 'unarchived':
		case 'enabled auto-merge':
		case 'updated':
			return COLOR_NEUTRAL;

		case 'closed':
		case 'merged':
		case 'complete':
			return COLOR_CLOSED;

		default:
			return COLOR_DEFAULT;
	}
}

/**
 * Splits an action into the verb that goes before the thing it happened to, and what goes after it,
 * so that a title reads "closed issue #5 as not planned" rather than "closed as not planned issue #5".
 */
function actionPhrase(action: string): [verb: string, suffix: string] {
	switch (action) {
		case 'closed as not planned':
			return ['closed', ' as not planned'];
		case 'closed without merging':
			return ['closed', ' without merging'];
		case 'readied':
			return ['marked', ' as ready for review'];
		case 'enabled auto-merge':
			return ['enabled auto-merge on', ''];
		case 'converted to draft':
			return ['converted', ' to draft'];
		case 'changed category':
			return ['changed category of', ''];
		default:
			return [action, ''];
	}
}

/**
 * The parts every alert of a security feature has in common. A new alert always needs attention,
 * what happened to it afterwards is coloured like any other action.
 */
function alertEmbed(title: string, titleEnd: string, action: string, url: DiscordEmbed['url'], sender: Sender): DiscordEmbed {
	return {
		title,
		titleEnd,
		url,
		color: action === 'created' ? COLOR_ATTENTION : actionColor(action),
		author: formatAuthor(sender),
	};
}

/** The layout escapes what it is given, so the names are passed on as they are. */
function labelsFooter(labels: { name: string }[] | null | undefined): DiscordEmbed['footer'] {
	return labels && labels.length > 0 ? { text: labels.map((label) => label.name).join(' · ') } : undefined;
}

/** Names what something used to be called, after a title that says what it is called now. */
function fromSuffix(name: string): string {
	return ` (from **${escape(name)}**)`;
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
		title: `set up hook **${payload.hook_id}** — it works!`,
		description: escape(payload.zen ?? ''),
		color: COLOR_NEUTRAL,
		author: formatAuthor(payload.sender),
	};
}

function formatPush(payload: PushEvent): DiscordEmbed {
	const commits = payload.commits.filter((commit) => commit.distinct !== false && commit.message);
	const ref = escapeCode(refName(payload.ref));
	const baseRef = payload.base_ref ? escapeCode(refName(payload.base_ref)) : null;
	const newCommits = `${commits.length} new commit${commits.length === 1 ? '' : 's'}`;

	// A ref is named by whoever made it, so it goes after the link with anything else from the payload
	const embed: DiscordEmbed = {
		title: '',
		titleEnd: '',
		url: payload.compare,
		color: COLOR_DEFAULT,
		author: formatAuthor(payload.sender),
	};

	if (payload.created) {
		if (payload.ref.startsWith('refs/tags/')) {
			embed.title = 'tagged';
			embed.titleEnd = ` ${ref} at ${baseRef ?? escapeCode(shortSha(payload.after))}`;
		} else {
			embed.title = 'created';
			embed.titleEnd = ` ${ref}`;

			if (baseRef) {
				embed.titleEnd += ` from ${baseRef}`;
			} else if (commits.length > 0) {
				embed.titleEnd += ` at ${escapeCode(shortSha(payload.after))}`;
			}

			if (commits.length > 0) {
				embed.titleEnd += ` (+${newCommits})`;
			}
		}
	} else if (payload.deleted) {
		throw new NotImplementedError('push', 'deleted (use DeleteEvent if needed)');
	} else if (payload.forced) {
		embed.title = 'force-pushed';
		embed.titleEnd = ` ${ref} from ${escapeCode(shortSha(payload.before))} to ${escapeCode(shortSha(payload.after))}`;
		embed.color = COLOR_BAD;
	} else if (commits.length === 0 && payload.commits.length > 0) {
		if (baseRef) {
			embed.title = 'merged';
			embed.titleEnd = ` ${baseRef} into ${ref}`;
			embed.color = COLOR_CLOSED;
		} else {
			embed.title = 'fast-forwarded';
			embed.titleEnd = ` ${ref} from ${escapeCode(shortSha(payload.before))} to ${escapeCode(shortSha(payload.after))}`;
			embed.color = COLOR_NEUTRAL;
		}
	} else {
		// Most pushes go to the default branch, so only other branches are worth naming
		const isDefaultBranch = payload.ref === `refs/heads/${payload.repository.default_branch}`;

		embed.title = `pushed ${newCommits}`;
		embed.titleEnd = isDefaultBranch ? '' : ` to ${ref}`;
	}

	if (payload.forced) {
		// GitHub supports displaying proper diffs for force pushes, but it only appears to work
		// if the diff url has full hashes, so we construct the url ourselves instead of using
		// the one in the payload. ".." instead of "..." makes GitHub display the changes between
		// the commits rather than the entire diff of the force push.
		embed.url = `${payload.repository.html_url}/compare/${payload.before}..${payload.after}`;
	} else if (commits.length === 1) {
		// If there's only one distinct commit, link to it directly
		embed.url = commits[0].url;
	}

	if (commits.length > 0) {
		// A push of a single commit has the room to say what the commit itself says, everything past
		// the summary that the line above it already carries
		const message = commits.length === 1 ? commits[0].message.trim() : '';
		const newline = message.indexOf('\n');
		const body = newline === -1 ? '' : formatBody(message.slice(newline + 1));

		// Newest commits first, and never more than a handful of them
		embed.description = commits
			.slice(-MAX_PUSH_COMMITS)
			.reverse()
			.map((commit) => {
				// Where the body follows, the summary no longer has to trail off into it
				const summary = body === '' ? commit.message : message.slice(0, newline);
				let line = `[${escapeCode(shortSha(commit.id))}](${commit.url}) ${shortMessage(summary)}`;

				if (commit.author.username) {
					if (commit.author.username !== payload.sender?.login) {
						line += ` - ${escape(commit.author.username)}`;
					}
				} else {
					line += ` - *${escape(commit.author.name ?? 'unknown')}*`;
				}

				return line;
			})
			.join('\n');

		if (body !== '') {
			embed.description += `\n\n${body}`;
		}
	}

	return embed;
}

function formatDelete(payload: DeleteEvent): DiscordEmbed {
	if (payload.ref_type !== 'tag' && payload.ref_type !== 'branch') {
		throw new NotImplementedError('delete', payload.ref_type);
	}

	return {
		title: `deleted ${payload.ref_type}`,
		titleEnd: ` ${escapeCode(payload.ref)}`,
		url: payload.repository.html_url,
		color: COLOR_BAD,
		author: formatAuthor(payload.sender),
	};
}

function formatIssues(payload: IssuesEvent): DiscordEmbed {
	assertAction(
		'issues',
		payload.action,
		['opened', 'closed', 'reopened', 'deleted', 'pinned', 'locked', 'unlocked', 'transferred'],
		['edited', 'unpinned', 'milestoned', 'demilestoned', 'labeled', 'unlabeled', 'assigned', 'unassigned', 'typed', 'untyped', 'field_added', 'field_removed'],
	);

	const action =
		payload.action === 'closed' && payload.issue.state_reason === 'not_planned' ? 'closed as not planned' : payload.action;

	const [verb, suffix] = actionPhrase(action);

	const embed: DiscordEmbed = {
		title: `${verb} issue **#${payload.issue.number}**${suffix}`,
		titleEnd: `: ${escape(payload.issue.title)}`,
		url: payload.issue.html_url,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};

	if (payload.action === 'opened') {
		embed.description = formatBody(payload.issue.body);

		embed.footer = labelsFooter(payload.issue.labels);
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
			'stacked',
		],
	);

	const [verb, suffix] = actionPhrase(action);
	const draft = payload.pull_request.draft && action !== 'converted to draft' ? 'draft ' : '';

	const embed: DiscordEmbed = {
		title: `${verb} ${draft}PR **#${payload.pull_request.number}**${suffix}`,
		titleEnd: `: ${escape(payload.pull_request.title)}`,
		url: payload.pull_request.html_url,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};

	if (action === 'opened') {
		embed.description = formatBody(payload.pull_request.body);
		embed.footer = labelsFooter(payload.pull_request.labels);
	} else if (action === 'merged') {
		embed.description = `Merged from **${escape(payload.pull_request.user?.login ?? 'ghost')}** to ${escapeCode(payload.pull_request.base.ref)}`;
	}

	return embed;
}

function formatMilestone(payload: MilestoneEvent): DiscordEmbed {
	assertAction('milestone', payload.action, ['opened', 'closed', 'created', 'deleted'], ['edited']);

	// A new milestone is "created", GitHub calls reopening a closed one "opened"
	const action = payload.action === 'opened' ? 'reopened' : payload.action;

	return {
		title: `${action} milestone **#${payload.milestone.number}**`,
		titleEnd: `: ${escape(payload.milestone.title)}`,
		description: action === 'created' ? formatBody(payload.milestone.description) : '',
		url: payload.milestone.html_url,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};
}

/** Both `package` and `registry_package` have the same payload under a different name. */
function formatPackage(event: string, payload: PackageEvent | RegistryPackageEvent): DiscordEmbed {
	assertAction(event, payload.action, ['published', 'updated']);

	const pkg = 'registry_package' in payload ? payload.registry_package : payload.package;
	const body = pkg.package_version?.body;
	const version = pkg.package_version?.version;

	return {
		// The type is one of a handful that GitHub names, such as npm or maven
		title: `${payload.action} ${pkg.package_type.toLowerCase()} package`,
		titleEnd: `: **${escape(pkg.name)}**${version ? ` ${escape(version)}` : ''}`,
		// Container packages have an empty object as their body
		description: payload.action === 'published' && typeof body === 'string' ? formatBody(body) : '',
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
		title: `${payload.action} a ${kind}`,
		titleEnd: `: ${escape(name)}`,
		// Release notes are only worth showing when the release appears
		description: payload.action === 'published' ? formatBody(payload.release.body) : '',
		url: payload.release.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

function formatCommitComment(payload: CommitCommentEvent): DiscordEmbed {
	assertAction('commit_comment', payload.action, ['created']);

	return {
		title: `commented on commit ${escapeCode(shortSha(payload.comment.commit_id))}`,
		description: formatBody(payload.comment.body),
		url: payload.comment.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

/** Comments on issues, pull requests and discussions. */
function formatComment(event: string, payload: IssueCommentEvent | DiscussionCommentEvent): DiscordEmbed {
	assertAction(event, payload.action, ['created', 'deleted'], event === 'issue_comment' ? ['edited', 'pinned', 'unpinned'] : ['edited']);

	const subject = 'discussion' in payload ? payload.discussion : payload.issue;
	const kind = 'discussion' in payload ? 'discussion' : payload.issue.pull_request ? 'PR' : 'issue';
	const deleted = payload.action === 'deleted';

	return {
		title: deleted
			? `deleted comment in ${kind} **#${subject.number}** from **${payload.comment.user?.login ?? 'ghost'}**`
			: `commented on ${kind} **#${subject.number}**`,
		titleEnd: deleted ? '' : `: ${escape(subject.title)}`,
		description: deleted ? '' : formatBody(payload.comment.body),
		url: payload.comment.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
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
		title: `${state}${state === 'dismissed' ? ' a review on' : ''} PR **#${payload.pull_request.number}**`,
		titleEnd: `: ${escape(payload.pull_request.title)}`,
		// The body of a dismissed review is what the reviewer wrote, not why it was dismissed
		description: state === 'dismissed' ? '' : formatBody(payload.review.body),
		url: payload.review.html_url,
		color: state === 'dismissed' ? COLOR_BAD : actionColor(state),
		author: formatAuthor(payload.sender),
	};
}

function formatPullRequestReviewComment(payload: PullRequestReviewCommentEvent): DiscordEmbed {
	assertAction('pull_request_review_comment', payload.action, ['created'], ['edited', 'deleted']);

	return {
		title: `commented on the code of PR **#${payload.pull_request.number}**`,
		titleEnd: `: ${escape(payload.pull_request.title)}`,
		description: formatBody(payload.comment.body),
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

	const [verb, suffix] = actionPhrase(action);

	const embed: DiscordEmbed = {
		title: `${verb} discussion **#${payload.discussion.number}**${suffix}`,
		// The emoji of a category is a shortcode, which would keep Discord from making a link of the title
		titleEnd: `: ${payload.discussion.category.emoji} ${escape(payload.discussion.title)}`,
		url: payload.action === 'answered' ? (payload.answer?.html_url ?? payload.discussion.html_url) : payload.discussion.html_url,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};

	if (action === 'created') {
		embed.description = formatBody(payload.discussion.body);
		embed.footer = labelsFooter(payload.discussion.labels);
	} else if (payload.action === 'answered') {
		embed.description = formatBody(payload.answer?.body);
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

	assertAction('dependabot_alert', action, ['created', 'fixed', 'dismissed', 'auto-dismissed', 'reopened', 'reintroduced'], ['assignees_changed']);

	const advisory = payload.alert.security_advisory;
	const vulnerability = payload.alert.security_vulnerability;

	const embed = alertEmbed(
		`${action} Dependabot alert **#${payload.alert.number}**`,
		` for **${escape(vulnerability.package.name)}**: ${escape(advisory.summary)}`,
		action,
		payload.alert.html_url,
		payload.sender,
	);

	if (action === 'created') {
		embed.description = formatBody(advisory.description);
		embed.footer = { text: `${advisory.severity} · ${advisory.cve_id ?? advisory.ghsa_id}` };
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

	assertAction('code_scanning_alert', action, ['created', 'fixed', 'dismissed', 'reopened'], ['appeared_in_branch', 'updated_assignment']);

	const embed = alertEmbed(
		`${action} Code scanning alert **#${payload.alert.number}**`,
		`: ${escape(payload.alert.rule.description)}`,
		action,
		payload.alert.html_url,
		payload.sender,
	);

	if (action === 'created') {
		embed.description = formatBody(payload.alert.most_recent_instance?.message?.text);
		embed.footer = { text: `${payload.alert.rule.severity ?? 'none'} · ${payload.alert.rule.id}` };
	}

	return embed;
}

function formatSecretScanningAlert(payload: SecretScanningAlertEvent): DiscordEmbed {
	const action = payload.action === 'publicly_leaked' ? 'publicly leaked' : payload.action;

	assertAction(
		'secret_scanning_alert',
		action,
		['created', 'resolved', 'reopened', 'publicly leaked'],
		['assigned', 'unassigned', 'validated', 'metadata_created', 'metadata_removed'],
	);

	const { alert } = payload;
	const secretType = alert.secret_type_display_name ?? alert.secret_type ?? 'unknown';

	const embed = alertEmbed(
		`${action} Secret scanning alert **#${alert.number}**`,
		`: ${escape(secretType)}`,
		action,
		alert.html_url,
		payload.sender,
	);

	if (action === 'created' && alert.push_protection_bypassed_by) {
		embed.description = `Push protection bypassed by **${escape(alert.push_protection_bypassed_by.login ?? 'ghost')}**`;
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
			title: 'privately reported a vulnerability',
			titleEnd: `: **${escape(advisory.ghsa_id)}**`,
			url: advisory.html_url,
			color: COLOR_ATTENTION,
			author: formatAuthor(payload.sender),
		};
	}

	return {
		title: 'published a security advisory',
		titleEnd: `: ${escape(advisory.summary)}`,
		description: formatBody(advisory.description),
		url: advisory.html_url,
		color: COLOR_ATTENTION,
		author: formatAuthor(payload.sender),
		// Not every advisory has a severity
		footer: { text: [advisory.severity, advisory.cve_id ?? advisory.ghsa_id].filter(Boolean).join(' · ') },
	};
}

function formatMember(payload: MemberEvent): DiscordEmbed {
	assertAction('member', payload.action, ['added', 'removed'], ['edited']);

	return {
		title: `${payload.action} **${payload.member?.login ?? 'ghost'}** as a collaborator`,
		url: payload.repository.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

function formatGollum(payload: GollumEvent): DiscordEmbed {
	// Never more than a handful of pages
	const lines = payload.pages.slice(0, MAX_WIKI_PAGES).map((page) => {
		// A page title with parentheses ends up in the url, where they would end the markdown link
		const pageUrl = page.html_url.replaceAll('(', '%28').replaceAll(')', '%29');
		// Append compare url since GitHub doesn't provide one
		const url = page.action === 'edited' ? `${pageUrl}/_compare/${page.sha}` : pageUrl;
		const summary = page.summary ? `: ${shortMessage(page.summary)}` : '';

		// Nothing can be escaped in the text of a link, so the title of the page goes after it
		return `[${page.action}](${url}) ${escape(page.title)}${summary}`;
	});

	const remaining = payload.pages.length - MAX_WIKI_PAGES;

	if (remaining > 0) {
		lines.push(`and ${remaining} more page${remaining === 1 ? '' : 's'}`);
	}

	return {
		title: 'updated wiki',
		description: lines.join('\n'),
		url: `${payload.repository.html_url}/wiki`,
		color: COLOR_NEUTRAL,
		author: formatAuthor(payload.sender),
	};
}

function formatPublic(payload: PublicEvent): DiscordEmbed {
	return {
		title: 'open sourced',
		titleEnd: ` **${escape(payload.repository.name)}** — now available to everyone!`,
		url: payload.repository.html_url,
		color: COLOR_DEFAULT,
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

	let titleEnd = ` **${escape(payload.repository.name)}**`;

	if (payload.action === 'renamed') {
		titleEnd += fromSuffix(payload.changes.repository.name.from);
	} else if (payload.action === 'transferred') {
		const from = payload.changes.owner.from;

		const owner = from.user ?? from.organization;

		if (owner) {
			titleEnd += fromSuffix(owner.login);
		}
	}

	return {
		title: payload.action,
		titleEnd,
		url: payload.repository.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

/** Projects of either kind, which only differ in where their fields are. */
function projectEmbed(
	event: string,
	action: string,
	sender: Sender,
	project: { number: number; title: string; body: string | null | undefined; url: string },
): DiscordEmbed {
	assertAction(event, action, ['created', 'closed', 'reopened', 'deleted'], ['edited']);

	return {
		title: `${action} project **#${project.number}**`,
		titleEnd: `: ${escape(project.title)}`,
		description: action === 'created' ? formatBody(project.body) : '',
		url: project.url,
		color: actionColor(action),
		author: formatAuthor(sender),
	};
}

function formatProject(payload: ProjectEvent): DiscordEmbed {
	return projectEmbed('project', payload.action, payload.sender, {
		number: payload.project.number,
		title: payload.project.name,
		body: payload.project.body,
		url: payload.project.html_url,
	});
}

function formatProjectV2(payload: ProjectV2Event): DiscordEmbed {
	const project = payload.projects_v2;

	return projectEmbed('projects_v2', payload.action, payload.sender, {
		number: project.number,
		title: project.title,
		body: project.short_description,
		// Projects have no url of their own in the payload, they live under the organization that owns them
		url: `https://github.com/orgs/${payload.organization.login}/projects/${project.number}`,
	});
}

/** GitHub sends the status of a project as an enum such as `OFF_TRACK`, and it can be unset. */
function projectStatus(status: string | null | undefined): string | null {
	return status ? status.toLowerCase().replaceAll('_', ' ') : null;
}

function formatProjectStatusUpdate(payload: ProjectStatusUpdateEvent): DiscordEmbed {
	assertAction('projects_v2_status_update', payload.action, ['created'], ['edited', 'deleted']);

	const update = payload.projects_v2_status_update;
	const status = projectStatus(update.status);

	return {
		title: `posted a project status update${status === null ? '' : ` (${status})`}`,
		description: formatBody(update.body),
		// The payload only has the node id of the project, so there is nothing to link but the list of them
		url: `https://github.com/orgs/${payload.organization.login}/projects`,
		color: status === null ? COLOR_DEFAULT : actionColor(status),
		author: formatAuthor(payload.sender),
	};
}

function formatBranchProtectionConfiguration(payload: BranchProtectionConfigurationEvent): DiscordEmbed {
	assertAction('branch_protection_configuration', payload.action, ['enabled', 'disabled']);

	return {
		title: `${payload.action} branch protection for all branches`,
		url: `${payload.repository.html_url}/settings/branches`,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

function formatBranchProtectionRule(payload: BranchProtectionRuleEvent): DiscordEmbed {
	// An edit changes a dozen settings at a time, which is too much to put in a title
	assertAction('branch_protection_rule', payload.action, ['created', 'deleted'], ['edited']);

	return {
		title: `${payload.action} branch protection rule`,
		titleEnd: ` ${escapeCode(payload.rule.name)}`,
		url: `${payload.repository.html_url}/settings/branches`,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

function formatRepositoryRuleset(payload: RepositoryRulesetEvent): DiscordEmbed {
	assertAction('repository_ruleset', payload.action, ['created', 'edited', 'deleted']);

	const ruleset = payload.repository_ruleset;

	return {
		title: `${payload.action} ruleset`,
		titleEnd: `: **${escape(ruleset.name)}** (${escape(ruleset.enforcement)})`,
		// Rulesets of an organization have no page of their own
		url: ruleset._links?.html?.href,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

function formatDeployKey(payload: DeployKeyEvent): DiscordEmbed {
	assertAction('deploy_key', payload.action, ['created', 'deleted']);

	// A key that can write to the repository is worth telling apart from one that can not
	const access = payload.key.read_only ? 'read-only' : 'read-write';

	return {
		title: `${payload.action} deploy key`,
		titleEnd: `: **${escape(payload.key.title)}** (${access})`,
		url: `${payload.repository.html_url}/settings/keys`,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

/** Says that this very webhook was deleted. */
function formatMeta(payload: MetaEvent): DiscordEmbed {
	assertAction('meta', payload.action, ['deleted']);

	return {
		title: `deleted hook ${payload.hook_id}`,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

/**
 * The user an organization member event is about. An invitation by email has no account yet,
 * and the address is not something to announce.
 */
function organizationMember(payload: OrganizationEvent): string | null {
	if (payload.action === 'member_invited') {
		return payload.user?.login ?? payload.invitation.login ?? null;
	}

	return ('membership' in payload ? payload.membership?.user?.login : null) ?? 'ghost';
}

/**
 * The role of the member an organization event is about. An invitation calls a plain member
 * a direct member, and reinstating someone gives back the role they had, which is not named.
 */
function organizationRole(payload: OrganizationEvent): string | null {
	const role = ('membership' in payload ? payload.membership?.role : null) ?? ('invitation' in payload ? payload.invitation.role : null);

	if (!role || role === 'reinstate') {
		return null;
	}

	return role === 'direct_member' ? 'member' : role.replaceAll('_', ' ');
}

function formatOrganization(payload: OrganizationEvent): DiscordEmbed {
	const action = lookup(
		{
			deleted: 'deleted',
			renamed: 'renamed',
			member_added: 'added',
			member_removed: 'removed',
			member_invited: 'invited',
		},
		payload.action,
	);

	if (action === undefined) {
		throw new NotImplementedError('organization', payload.action);
	}

	let title: string;

	if (action === 'deleted' || action === 'renamed') {
		const from = payload.action === 'renamed' ? payload.changes?.login?.from : null;

		title = `${action} the organization **${escape(payload.organization.login)}**`;

		if (from) {
			title += fromSuffix(from);
		}
	} else {
		const member = organizationMember(payload);
		const role = organizationRole(payload);

		title = `${action} ${member === null ? 'someone by email' : `**${escape(member)}**`}`;

		if (role) {
			title += ` (${escape(role)})`;
		}

		title += `${action === 'removed' ? ' from' : ' to'} the organization`;
	}

	return {
		title,
		color: actionColor(action),
		author: formatAuthor(payload.sender),
	};
}

function formatOrgBlock(payload: OrgBlockEvent): DiscordEmbed {
	assertAction('org_block', payload.action, ['blocked', 'unblocked']);

	return {
		title: `${payload.action} user **${escape(payload.blocked_user?.login ?? 'ghost')}**`,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

/** Only says that there is a new sponsor, what they pay is between them and the sponsored account. */
function formatSponsorship(payload: SponsorshipEvent): DiscordEmbed {
	assertAction('sponsorship', payload.action, ['created'], ['cancelled', 'edited', 'tier_changed', 'pending_cancellation', 'pending_tier_change']);

	const { sponsor, sponsorable, privacy_level: privacy } = payload.sponsorship;
	const sponsored = sponsorable?.login ?? 'ghost';
	const url = `https://github.com/sponsors/${sponsored}`;

	// The sender is the sponsor, who asked not to be named
	if (privacy !== 'public' || !sponsor) {
		return {
			title: 'got a new private sponsor',
			url,
			color: COLOR_DEFAULT,
			author: formatAuthor(sponsorable),
		};
	}

	return {
		title: `is now sponsoring **${sponsored}**`,
		url,
		color: COLOR_DEFAULT,
		author: formatAuthor(sponsor),
	};
}

/** Only a run that broke the default branch is worth telling, the rest would be noise. */
function formatWorkflowRun(payload: WorkflowRunEvent): DiscordEmbed {
	assertAction('workflow_run', payload.action, ['completed'], ['requested', 'in_progress']);

	const run = payload.workflow_run;
	const outcome = lookup({ failure: 'failed', timed_out: 'timed out', startup_failure: 'failed to start' }, run.conclusion as string);

	if (outcome === undefined) {
		throw new IgnoredEventError(`workflow_run - ${run.conclusion}`);
	}

	if (run.head_branch !== payload.repository.default_branch) {
		throw new IgnoredEventError('workflow_run - not the default branch');
	}

	return {
		title: 'broke',
		titleEnd: ` ${escapeCode(payload.repository.default_branch)} — workflow **${escape(run.name || payload.workflow?.name || 'unknown')}** ${outcome}`,
		description: shortMessage(run.head_commit.message),
		url: run.html_url,
		color: actionColor(outcome),
		author: formatAuthor(payload.sender),
	};
}

function formatMembership(payload: MembershipEvent): DiscordEmbed {
	assertAction('membership', payload.action, ['added', 'removed']);

	const where = payload.action === 'added' ? 'to' : 'from';

	return {
		title: `${payload.action} **${payload.member?.login ?? 'ghost'}** ${where} team`,
		titleEnd: ` **${escape(payload.team.name)}**`,
		url: payload.team.html_url,
		color: actionColor(payload.action),
		author: formatAuthor(payload.sender),
	};
}

function formatTeam(payload: TeamEvent): DiscordEmbed {
	const [action, where] = lookup(
		{
			created: ['created', ''],
			deleted: ['deleted', ''],
			edited: ['edited', ''],
			added_to_repository: ['added', ' to this repository'],
			removed_from_repository: ['removed', ' from this repository'],
		},
		payload.action,
	) ?? [];

	if (action === undefined) {
		throw new NotImplementedError('team', payload.action);
	}

	const from = payload.action === 'edited' ? payload.changes.name?.from : null;
	const verb = from ? 'renamed' : action;

	let title = `${verb} team`;
	let titleEnd = ` **${escape(payload.team.name)}**${where}`;

	// An edit is worth telling when it renames a team or changes who can see it
	if (payload.action === 'edited' && !from) {
		if (!payload.changes.privacy) {
			throw new IgnoredEventError(`team - ${payload.action}`);
		}

		title = 'changed the privacy of team';
		titleEnd = ` **${escape(payload.team.name)}** to **${escape(payload.team.privacy ?? 'unknown')}**`;
	}

	if (from) {
		titleEnd += fromSuffix(from);
	}

	return {
		title,
		titleEnd,
		url: payload.team.html_url,
		color: actionColor(verb),
		author: formatAuthor(payload.sender),
	};
}
