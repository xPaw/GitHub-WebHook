import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const EVENTS_DIR = join(import.meta.dirname, '..', '..', 'tests', 'events');

/** Names of every event fixture. */
export const fixtures = readdirSync(EVENTS_DIR, { withFileTypes: true })
	.filter((entry) => entry.isDirectory())
	.map((entry) => entry.name);

/** Reads a file of an event fixture, the payload by default. */
export function readFixture(name: string, file = 'payload.json'): string {
	return readFileSync(join(EVENTS_DIR, name, file), 'utf8');
}
