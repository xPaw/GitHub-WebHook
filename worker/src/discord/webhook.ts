import { BadRequestError } from '../errors.js';
import type { DiscordMessage } from './converter.js';

const USER_AGENT = 'https://github.com/xPaw/GitHub-WebHook';
const TIMEOUT_MS = 8000;
const PREFIX = 'discordhook';
/** How much of what Discord said is worth keeping, its errors are a sentence or two. */
const MAX_REASON_LENGTH = 500;

/** The Discord webhook an event is proxied to, taken from the url of the request. */
export interface Target {
	id: string;
	token: string;
	/** Thread of the channel to post in, forum channels can not be posted to without one. */
	threadId?: string;
}

export interface SendResult {
	/** HTTP status returned by Discord, or null when the request never completed. */
	status: number | null;
	ok: boolean;
	/** What Discord said when it turned the message down, or why the request never completed. */
	error?: string;
}

/** Parses `/discordhook/<id>/<token>?thread_id=<id>`, every other query parameter is ignored. */
export function parseTarget(url: URL): Target {
	const segments = url.pathname.split('/');

	// The pathname starts with a slash, so the first segment is always empty
	if (segments.length !== 4 || segments[0] !== '' || segments[1] !== PREFIX) {
		throw new BadRequestError(`The url must be /${PREFIX}/<webhook id>/<webhook token>.`);
	}

	const [, , id, token] = segments;

	if (!isSnowflake(id)) {
		throw new BadRequestError('Invalid webhook id.');
	}

	if (!isToken(token)) {
		throw new BadRequestError('Invalid webhook token.');
	}

	const threadId = url.searchParams.get('thread_id');

	if (threadId === null) {
		return { id, token };
	}

	if (!isSnowflake(threadId)) {
		throw new BadRequestError('Invalid thread id.');
	}

	return { id, token, threadId };
}

/** The url is only ever built from a validated target, nothing of the request is fetched as is. */
export function targetUrl({ id, token, threadId }: Target): string {
	const url = new URL(`https://discord.com/api/webhooks/${id}/${token}`);

	// A webhook that no application owns has its components ignored without this
	url.searchParams.set('with_components', 'true');

	if (threadId !== undefined) {
		url.searchParams.set('thread_id', threadId);
	}

	return url.toString();
}

/** Discord ids are 64-bit integers, which are 17 to 20 digits long. */
function isSnowflake(value: string): boolean {
	return /^[0-9]{17,20}$/.test(value);
}

/** Tokens are url safe base64, which also rules out dots and percent encoded characters. */
function isToken(value: string): boolean {
	return /^[A-Za-z0-9_-]+$/.test(value);
}

/** Posts the message to a Discord webhook, retrying once when rate limited. */
export async function sendToDiscord(target: Target, message: DiscordMessage): Promise<SendResult> {
	const url = targetUrl(target);
	const body = JSON.stringify(message);
	const deadline = Date.now() + TIMEOUT_MS;

	try {
		let response = await post(url, body, deadline - Date.now());
		// Discord says in the body why it turned a message down, which is the only account of it there is
		let reason = response.ok ? '' : await readBody(response);

		if (response.status === 429) {
			const retryAfter = retryAfterMs(reason);
			const remaining = deadline - Date.now();

			if (retryAfter !== null && retryAfter < remaining) {
				await new Promise((resolve) => setTimeout(resolve, retryAfter));
				response = await post(url, body, deadline - Date.now());
				reason = response.ok ? '' : await readBody(response);
			}
		}

		return response.ok
			? { status: response.status, ok: true }
			: { status: response.status, ok: false, error: reason.slice(0, MAX_REASON_LENGTH) };
	} catch (error) {
		return { status: null, ok: false, error: (error as Error).message };
	}
}

function post(url: string, body: string, timeout: number): Promise<Response> {
	return fetch(url, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'User-Agent': USER_AGENT,
		},
		body,
		signal: AbortSignal.timeout(Math.max(timeout, 1)),
	});
}

/**
 * The body can only be read once, so what it says is kept whole for both the retry and the log.
 * Cutting it here would make longer json unparseable, and the delay to retry after is in it.
 */
async function readBody(response: Response): Promise<string> {
	try {
		return await response.text();
	} catch (error) {
		return (error as Error).message;
	}
}

function retryAfterMs(body: string): number | null {
	try {
		const parsed = JSON.parse(body) as { retry_after?: number };

		return typeof parsed.retry_after === 'number' ? parsed.retry_after * 1000 : null;
	} catch {
		return null;
	}
}
