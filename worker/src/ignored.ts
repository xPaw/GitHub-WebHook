import { IgnoredEventError } from './errors.js';

const DEPENDABOT_ID = 49699333;

interface Payload {
	/** Always there for a push and a delete, which are the only events it is read for. */
	ref: string;
	ref_type?: string;
	action?: string;
	pull_request?: { merged?: boolean | null };
	sender?: { id?: number } | null;
	head_commit?: { message?: string; committer?: { username?: string } } | null;
}

/**
 * Throws for events that would only be noise, even though they could be formatted.
 *
 * @throws {IgnoredEventError}
 */
export function assertNotNoise(eventType: string, payload: unknown): void {
	const { action, pull_request: pullRequest, sender, head_commit: headCommit } = payload as Payload;

	// Its alerts, and the pull requests it merges itself when asked to, are all it sends that is worth reading
	const isMergedPullRequest = eventType === 'pull_request' && action === 'closed' && pullRequest?.merged === true;

	if (sender?.id === DEPENDABOT_ID && eventType !== 'dependabot_alert' && !isMergedPullRequest) {
		throw new IgnoredEventError(`${eventType} - dependabot sender`);
	}

	const branch = branchName(eventType, payload as Payload);

	if (branch === null) {
		return;
	}

	const bot = /^(renovate|dependabot)\//.exec(branch);

	if (bot) {
		throw new IgnoredEventError(`${eventType} - dependency update in a ${bot[1]} branch`);
	}

	// Temporary branches that GitHub creates and deletes for every entry of a merge queue
	if (branch.startsWith('gh-readonly-queue/')) {
		throw new IgnoredEventError(`${eventType} - merge queue branch`);
	}

	// The merged pull request is announced already, a deletion has no head commit
	if (headCommit?.committer?.username === 'web-flow' && headCommit.message?.startsWith('Merge pull request #')) {
		throw new IgnoredEventError(`${eventType} - web-flow pull request merge`);
	}
}

/** The branch that was pushed to or deleted, null for tags and for every other event. */
function branchName(eventType: string, { ref, ref_type: refType }: Payload): string | null {
	if (eventType === 'push') {
		return ref.startsWith('refs/heads/') ? ref.slice('refs/heads/'.length) : null;
	}

	return eventType === 'delete' && refType === 'branch' ? ref : null;
}
