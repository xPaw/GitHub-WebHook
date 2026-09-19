import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const EVENTS_DIR = join(import.meta.dirname, '..', '..', 'fixtures');

/** Names of every event fixture. */
export const fixtures = readdirSync(EVENTS_DIR, { withFileTypes: true })
	.filter((entry) => entry.isDirectory())
	.map((entry) => entry.name);

export type Payload = Record<string, any>;

/** Loads the payload of a fixture and applies changes to it. */
export function loadPayload(name: string, change: (payload: Payload) => void = () => {}): Payload {
	const payload = JSON.parse(readFixture(name)) as Payload;

	change(payload);

	return payload;
}

/** Reads a file of an event fixture, the payload by default. */
export function readFixture(name: string, file = 'payload.json'): string {
	return readFileSync(join(EVENTS_DIR, name, file), 'utf8');
}

/** Loads the payload of a fixture with another action in it. */
export function withAction(fixture: string, action: string): Payload {
	return loadPayload(fixture, (payload) => {
		payload.action = action;
	});
}

/**
 * Every event whose payload has an action, with a fixture of it. The fixtures of an event either
 * all have an action or none do (ping, push, public, delete and gollum have none), and the action
 * is checked before anything else in the payload is read, so any fixture of an event will do.
 */
export const actionFixtures: [event: string, fixture: string][] = [
	...new Map(
		fixtures
			.filter((name) => loadPayload(name).action !== undefined)
			.map((name): [string, string] => [readFixture(name, 'type.txt').trim(), name]),
	),
];
