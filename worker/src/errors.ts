/** An error that is reported to GitHub with its own status code and message. */
export abstract class WebhookError extends Error {
	abstract readonly status: number;
}

/** Thrown for events that are deliberately not forwarded to Discord. */
export class IgnoredEventError extends WebhookError {
	readonly status = 200;

	constructor(eventName: string) {
		super(`Ignored GitHub event: ${eventName}`);
	}
}

/** Thrown for events (or event actions) that have no formatter yet. */
export class NotImplementedError extends WebhookError {
	readonly status = 501;

	constructor(
		public readonly eventName: string,
		detail?: string,
	) {
		super(`Unsupported GitHub event: ${eventName}${detail ? ` - ${detail}` : ''}`);
	}
}

/** Thrown for requests that GitHub should not have sent us in this shape. */
export class BadRequestError extends WebhookError {
	readonly status = 400;
}
