// The "browser" export of this package uses WebCrypto, which is what wrangler resolves
// for the workers runtime; under node (vitest) it resolves to the node:crypto build.
import { verify } from '@octokit/webhooks-methods';
import { BadRequestError } from './errors.js';

const EVENT_NAME = /^[a-z_]+$/;

export interface WebhookRequest {
	eventType: string;
	repositoryName: string;
	payload: unknown;
}

interface RawPayload {
	repository?: { full_name?: string; name: string; owner: { name?: string | null; login: string } };
	organization?: { login: string };
}

/** Verifies the X-Hub-Signature-256 header against the raw request body. */
export async function verifySignature(secret: string, body: string, signature: string): Promise<boolean> {
	try {
		return await verify(secret, body, signature);
	} catch {
		// Thrown for malformed signatures
		return false;
	}
}

/** Pulls the event type, repository name and payload out of a GitHub webhook request. */
export function parseRequest(request: Request, body: string): WebhookRequest {
	const eventType = request.headers.get('X-GitHub-Event');

	if (!eventType) {
		throw new BadRequestError('Missing event header.');
	}

	if (!EVENT_NAME.test(eventType)) {
		throw new BadRequestError('Invalid event header.');
	}

	const payload = parsePayload(request.headers.get('Content-Type'), body);

	return { eventType, repositoryName: repositoryName(payload), payload };
}

function repositoryName({ repository, organization }: RawPayload): string {
	if (repository) {
		return repository.full_name ?? `${repository.owner.name}/${repository.name}`;
	}

	if (organization) {
		// Events of an organization have no repository, they are matched as "<org>/repositories"
		// because patterns are written in the "<owner>/<repo>" format.
		return `${organization.login}/repositories`;
	}

	throw new BadRequestError('Missing repository information.');
}

function parsePayload(contentType: string | null, body: string): RawPayload {
	// GitHub sends "application/json", proxies may append "; charset=utf-8"
	const type = (contentType ?? '').split(';', 1)[0].trim().toLowerCase();
	let raw: string;

	if (type === 'application/json') {
		raw = body;
	} else if (type === 'application/x-www-form-urlencoded') {
		const field = new URLSearchParams(body).get('payload');

		if (field === null) {
			throw new BadRequestError('Missing payload.');
		}

		raw = field;
	} else {
		throw new BadRequestError('Unknown content type.');
	}

	let decoded: unknown;

	try {
		decoded = JSON.parse(raw);
	} catch (error) {
		throw new BadRequestError(`Failed to decode JSON: ${(error as Error).message}`);
	}

	if (typeof decoded !== 'object' || decoded === null || Array.isArray(decoded)) {
		throw new BadRequestError('Failed to decode JSON: payload is not an object.');
	}

	return decoded as RawPayload;
}
