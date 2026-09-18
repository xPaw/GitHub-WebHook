import type { DiscordMessage } from './discord/converter.js';

const USER_AGENT = 'https://github.com/xPaw/GitHub-WebHook';
const TIMEOUT_MS = 8000;

export interface Route {
	/** Secret token of the GitHub webhooks that deliver events for this pattern. */
	secret: string;
	/** Discord webhooks the events fan out to. */
	webhooks: string[];
}

/** Maps a repository pattern (`Owner/Repo`, `Owner/*`) to its route. */
export type RouteConfig = Record<string, Route>;

export interface SendResult {
	/** HTTP status returned by Discord, or null when the request never completed. */
	status: number | null;
	ok: boolean;
	error?: string;
}

/** Parses and validates the REPOSITORIES secret. */
export function parseRouteConfig(raw: string): RouteConfig {
	const parsed: unknown = JSON.parse(raw);

	if (!isObject(parsed)) {
		throw new Error('REPOSITORIES must be a JSON object.');
	}

	for (const [pattern, route] of Object.entries(parsed)) {
		if (!isObject(route)) {
			throw new Error(`REPOSITORIES["${pattern}"] must be an object.`);
		}

		if (typeof route.secret !== 'string' || route.secret === '') {
			throw new Error(`REPOSITORIES["${pattern}"].secret must be a non-empty string.`);
		}

		if (!Array.isArray(route.webhooks) || route.webhooks.some((url) => typeof url !== 'string')) {
			throw new Error(`REPOSITORIES["${pattern}"].webhooks must be an array of urls.`);
		}
	}

	return parsed as RouteConfig;
}

function isObject(value: unknown): value is Record<string, unknown> {
	return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Matches a repository name against a pattern which may contain `*` wildcards. */
export function wildcard(value: string, pattern: string): boolean {
	if (!pattern.includes('*')) {
		return pattern === value;
	}

	const expression = pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replaceAll('\\*', '.*');

	return new RegExp(`^${expression}$`).test(value);
}

/** Every pattern in the config that matches the repository. Patterns do not shadow each other. */
export function matchPatterns(config: RouteConfig, repositoryName: string): string[] {
	return Object.keys(config).filter((pattern) => wildcard(repositoryName, pattern));
}

/** Posts the message to a Discord webhook, retrying once when rate limited. */
export async function sendToDiscord(url: string, message: DiscordMessage): Promise<SendResult> {
	const body = JSON.stringify(message);
	const deadline = Date.now() + TIMEOUT_MS;

	try {
		let response = await post(url, body, deadline - Date.now());

		if (response.status === 429) {
			const retryAfter = await retryAfterMs(response);
			const remaining = deadline - Date.now();

			if (retryAfter !== null && retryAfter < remaining) {
				await new Promise((resolve) => setTimeout(resolve, retryAfter));
				response = await post(url, body, deadline - Date.now());
			}
		}

		return { status: response.status, ok: response.ok };
	} catch (error) {
		return { status: null, ok: false, error: (error as Error).message };
	}
}

/** Sends the message to every target in parallel. */
export function sendAll(urls: string[], message: DiscordMessage): Promise<SendResult[]> {
	// sendToDiscord never rejects, failures are reported in the result
	return Promise.all(urls.map((url) => sendToDiscord(url, message)));
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

async function retryAfterMs(response: Response): Promise<number | null> {
	try {
		const body = (await response.json()) as { retry_after?: number };

		return typeof body.retry_after === 'number' ? body.retry_after * 1000 : null;
	} catch {
		return null;
	}
}
