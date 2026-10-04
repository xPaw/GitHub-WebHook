import type { DiscordEmbed } from './discord/converter.js';
import { IgnoredEventError } from './errors.js';
import type { WebhookRequest } from './github.js';

const DEPENDABOT_ID = 49699333;
const GITHUB_ACTIONS = 'github-actions[bot]';

interface Payload {
	/** Always there for a push and a delete, which are the only events it is read for. */
	ref: string;
	ref_type?: string;
	action?: string;
	pull_request?: { merged?: boolean | null };
	sender?: { id?: number } | null;
	head_commit?: { message?: string; committer?: { username?: string } } | null;
	/** Always there for a push, which is the only event it is read for. */
	commits: { author: { username?: string } }[];
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
	{ embed: linkSteamTrackingDiffs },
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

/** These repositories are mostly huge generated diffs, which DiffsHub shows far better than GitHub does. */
function linkSteamTrackingDiffs({ eventType, repositoryName }: WebhookRequest, embed: DiscordEmbed): void {
	if (eventType !== 'push' || !/^SteamTracking\/(SteamTracking|GameTracking-.+)$/i.test(repositoryName)) {
		return;
	}

	// The link of a push is to its commit, or to a comparison when it has several,
	// and DiffsHub takes either at the same path
	if (embed.url?.startsWith('https://github.com/')) {
		const url = `https://diffshub.com/${embed.url.slice('https://github.com/'.length)}`;

		embed.links = [{ label: 'View on DiffsHub', url }];
	}
}

/** The branch that was pushed to or deleted, null for tags and for every other event. */
function branchName(eventType: string, { ref, ref_type: refType }: Payload): string | null {
	if (eventType === 'push') {
		return ref.startsWith('refs/heads/') ? ref.slice('refs/heads/'.length) : null;
	}

	return eventType === 'delete' && refType === 'branch' ? ref : null;
}
