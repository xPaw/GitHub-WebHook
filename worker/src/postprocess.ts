import { type DiscordEmbed, formatAuthor } from './discord/converter.js';
import { formatBody, linkText } from './discord/text.js';
import { IgnoredEventError } from './errors.js';
import type { WebhookRequest } from './github.js';

const DEPENDABOT_ID = 49699333;
const GITHUB_ACTIONS = 'github-actions[bot]';

// GitHub matches the names of owners and repositories in any case
const GAME_TRACKING = /^SteamTracking\/GameTracking-.+$/i;
/** Each of these repositories describes itself as "📥 Game Tracker: <game>". */
const GAME_NAME = /Game Tracker: (.+)$/;
/** The workflow ends every summary with a notice under a rule, which the channel has no need to read every time. */
const AI_NOTICE = /\r?\n-{3,}\r?\n<sub>[\s\S]*<\/sub>\s*$/;
/**
 * The summary is the whole of the card, so it is let grow far taller than the body of another one.
 * A line at most 70 characters wide, as `formatBody` measures them, keeps it inside what a message holds.
 */
const MAX_SUMMARY_LINES = 45;

interface Payload {
	/** Always there for a push and a delete, which are the only events it is read for. */
	ref: string;
	ref_type?: string;
	action?: string;
	pull_request?: { merged?: boolean | null };
	sender?: { id?: number; login?: string } | null;
	head_commit?: { message?: string; committer?: { username?: string } } | null;
	/** Always there for a push, which is the only event it is read for. */
	commits: { author: { username?: string } }[];
}

/** The parts of a commit comment that a summary of a build is laid out from. */
interface CommitCommentPayload {
	comment: { body: string; commit_id: string };
	repository: { name: string; description: string | null; homepage: string | null; owner: { login: string; avatar_url: string } };
}

/**
 * Something done to every event before it is sent. Either stage may be left out, and the rules
 * run in the order they are listed.
 */
interface Rule {
	/** Throws {@link IgnoredEventError} for an event that would only be noise, or changes the payload before it is formatted. */
	payload?(request: WebhookRequest): void;
	/** Changes what the event was formatted into, before it is laid out as components. */
	embed?(request: WebhookRequest, embed: DiscordEmbed): void;
}

const RULES: Rule[] = [
	{ payload: ignoreDependabot },
	{ payload: ignoreDependencyBranches },
	{ payload: ignoreMergeQueueBranches },
	{ payload: ignoreWebFlowMerges },
	{ payload: ignoreSchemaExplorerUpdates },
	{ payload: ignoreGameTrackingComments, embed: formatGameTrackingSummaries },
];

/**
 * Runs every rule on the payload, before it is formatted.
 *
 * @throws {IgnoredEventError}
 */
export function processPayload(request: WebhookRequest): void {
	for (const rule of RULES) {
		rule.payload?.(request);
	}
}

/** Runs every rule on what the event was formatted into. */
export function processEmbed(request: WebhookRequest, embed: DiscordEmbed): void {
	for (const rule of RULES) {
		rule.embed?.(request, embed);
	}
}

/** Its alerts, and the pull requests it merges itself when asked to, are all it sends that is worth reading. */
function ignoreDependabot({ eventType, payload }: WebhookRequest): void {
	const { action, pull_request: pullRequest, sender } = payload as Payload;
	const isMergedPullRequest = eventType === 'pull_request' && action === 'closed' && pullRequest?.merged === true;

	if (sender?.id === DEPENDABOT_ID && eventType !== 'dependabot_alert' && !isMergedPullRequest) {
		throw new IgnoredEventError(`${eventType} - dependabot sender`);
	}
}

function ignoreDependencyBranches({ eventType, payload }: WebhookRequest): void {
	const bot = /^(renovate|dependabot)\//.exec(branchName(eventType, payload as Payload) ?? '');

	if (bot) {
		throw new IgnoredEventError(`${eventType} - dependency update in a ${bot[1]} branch`);
	}
}

/** Temporary branches that GitHub creates and deletes for every entry of a merge queue. */
function ignoreMergeQueueBranches({ eventType, payload }: WebhookRequest): void {
	if (branchName(eventType, payload as Payload)?.startsWith('gh-readonly-queue/')) {
		throw new IgnoredEventError(`${eventType} - merge queue branch`);
	}
}

/** The merged pull request is announced already. */
function ignoreWebFlowMerges({ eventType, payload }: WebhookRequest): void {
	const event = payload as Payload;
	const headCommit = event.head_commit;

	// A deletion has no head commit
	if (
		branchName(eventType, event) !== null &&
		headCommit?.committer?.username === 'web-flow' &&
		headCommit.message?.startsWith('Merge pull request #')
	) {
		throw new IgnoredEventError(`${eventType} - web-flow pull request merge`);
	}
}

/**
 * A workflow commits every schema update it finds, which is nothing anybody reads as it happens.
 * Its commits are taken out of a push, and a push of nothing else is not sent at all.
 */
function ignoreSchemaExplorerUpdates({ eventType, repositoryName, payload }: WebhookRequest): void {
	// GitHub matches the names of owners and repositories in any case
	if (eventType !== 'push' || !/^ValveResourceFormat\/SchemaExplorer$/i.test(repositoryName)) {
		return;
	}

	const event = payload as Payload;
	const others = event.commits.filter((commit) => commit.author.username !== GITHUB_ACTIONS);

	// A push of a tag, or of a branch at a commit that is already there, has no commits of its own
	if (event.commits.length > 0 && others.length === 0) {
		throw new IgnoredEventError(`${eventType} - ${GITHUB_ACTIONS} commits`);
	}

	event.commits = others;
}

/** A workflow comments on every build with a summary of what changed in it, which is the only comment worth sending. */
function ignoreGameTrackingComments(request: WebhookRequest): void {
	if (isGameTrackingComment(request) && (request.payload as Payload).sender?.login !== GITHUB_ACTIONS) {
		throw new IgnoredEventError(`${request.eventType} - not from ${GITHUB_ACTIONS}`);
	}
}

/**
 * A summary is all that is sent of a build, so it is laid out as the news of the game rather than as
 * a comment: headed by the name of the game, with as much of the summary as fits.
 */
function formatGameTrackingSummaries(request: WebhookRequest, embed: DiscordEmbed): void {
	if (!isGameTrackingComment(request)) {
		return;
	}

	const { comment, repository } = request.payload as CommitCommentPayload;
	const game = GAME_NAME.exec(repository.description ?? '')?.[1] ?? repository.name;

	// The heading already names the game, which is all the line above it would say
	embed.title = linkText(game);
	embed.standalone = true;
	delete embed.scope;
	embed.description = formatBody(comment.body.replace(AI_NOTICE, ''), MAX_SUMMARY_LINES);
	embed.author = formatAuthor(repository.owner);
	embed.links = [{ label: 'View on DiffsHub', url: `https://diffshub.com/${request.repositoryName}/commit/${comment.commit_id}` }];

	if (repository.homepage?.startsWith('https://steamdb.info/')) {
		embed.links.push({ label: 'SteamDB', url: repository.homepage });
	}
}

function isGameTrackingComment({ eventType, repositoryName }: WebhookRequest): boolean {
	return eventType === 'commit_comment' && GAME_TRACKING.test(repositoryName);
}

/** The branch that was pushed to or deleted, null for tags and for every other event. */
function branchName(eventType: string, { ref, ref_type: refType }: Payload): string | null {
	if (eventType === 'push') {
		return ref.startsWith('refs/heads/') ? ref.slice('refs/heads/'.length) : null;
	}

	return eventType === 'delete' && refType === 'branch' ? ref : null;
}
