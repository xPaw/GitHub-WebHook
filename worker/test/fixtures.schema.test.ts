import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dereference, validate, type OutputUnit, type Schema } from '@cfworker/json-schema';
import { describe, expect, it } from 'vitest';
import { fixtures, readFixture } from './fixtures.js';

type Node = Record<string, any>;

// The package exports every version of the spec at once, this only needs the one of github.com
const specPath = createRequire(import.meta.url).resolve('@octokit/openapi-webhooks/generated/api.github.com.json');
const spec = JSON.parse(readFileSync(specPath, 'utf8')) as Node;

spec.$id = 'https://spec/';
removeExamples(spec);

const lookup = dereference(spec as Schema);

/**
 * An advisory declares its author, its publisher and its private fork as `type: ["null"]` and an `allOf` of
 * an object at once, which no value can satisfy, and the property then also trips `additionalProperties`.
 */
const ADVISORY = ['author', 'publisher', 'private_fork'].flatMap((property) => [
	`#/repository_advisory/${property} Instance type "object" is invalid. Expected "null".`,
	`#/repository_advisory/${property} Instance type "null" is invalid. Expected "object".`,
	`#/repository_advisory/${property} False boolean schema.`,
	`#/repository_advisory Property "${property}" does not match additional properties schema.`,
]);

/** Where the spec is wrong about what GitHub sends, keyed by fixture. */
const SPEC_MISTAKES: Record<string, string[]> = {
	// A fixed alert has the time it was fixed at, the spec only allows null
	code_scanning_alert_fixed: ['#/alert/fixed_at Instance type "string" is invalid. Expected "null".'],
	repository_advisory_published: ADVISORY,
	repository_advisory_published_no_cve: ADVISORY,
	repository_advisory_reported: ADVISORY,
};

/** Keywords that only say that something inside of them failed. */
const WRAPPERS = ['$ref', 'properties', 'items', 'allOf', 'anyOf', 'oneOf', 'if'];

/** Examples are payloads, and an `id` in them would be taken for the id of a schema. */
function removeExamples(node: unknown, isProperties = false): void {
	if (Array.isArray(node)) {
		for (const item of node) {
			removeExamples(item);
		}
	} else if (node && typeof node === 'object') {
		const object = node as Node;

		// Unless these are the names of properties
		if (!isProperties) {
			delete object.example;
			delete object.examples;
		}

		for (const [key, child] of Object.entries(object)) {
			removeExamples(child, key === 'properties' && !isProperties);
		}
	}
}

/** The webhook of an event is keyed as `event` or `event-action`, with dashes. */
function findSchema(event: string, action: unknown): Node | undefined {
	const name = event.replaceAll('_', '-');
	const webhook = spec.webhooks[typeof action === 'string' ? `${name}-${action.replaceAll('_', '-')}` : name];

	return webhook?.post.requestBody.content['application/json'].schema;
}

function resolve(schema: Node | undefined): Node | undefined {
	while (schema?.$ref) {
		schema = (schema.$ref as string)
			.slice(2)
			.split('/')
			.reduce((node, key) => node[key], spec);
	}

	return schema;
}

/** A schema together with everything it is composed of. */
function branches(schema: Node | undefined, found: Node[] = []): Node[] {
	const resolved = resolve(schema);

	if (resolved) {
		found.push(resolved);

		for (const key of ['allOf', 'oneOf', 'anyOf']) {
			for (const branch of resolved[key] ?? []) {
				branches(branch, found);
			}
		}
	}

	return found;
}

/** Properties that no branch of the schema knows about, the spec rarely forbids them itself. */
function unknownProperties(value: unknown, schemas: Node[], path: string, found: string[] = []): string[] {
	if (Array.isArray(value)) {
		const items = schemas.flatMap((schema) => branches(schema.items));

		for (const item of value) {
			unknownProperties(item, items, `${path}/0`, found);
		}
	} else if (value && typeof value === 'object') {
		// An object that lists no properties takes any of them, its type can be `["object", "null"]` too
		const open = schemas.some(
			(schema) =>
				schema.additionalProperties ||
				([schema.type].flat().includes('object') && !schema.properties && !schema.allOf && !schema.oneOf && !schema.anyOf),
		);

		for (const [key, child] of Object.entries(value)) {
			const known = schemas.flatMap((schema) => branches(schema.properties?.[key]));

			if (known.length > 0) {
				unknownProperties(child, known, `${path}/${key}`, found);
			} else if (!open && schemas.length > 0 && !found.includes(`${path}/${key}`)) {
				found.push(`${path}/${key}`);
			}
		}
	}

	return found;
}

function describeError(error: OutputUnit): string {
	return `${error.instanceLocation} ${error.error}`;
}

describe('fixtures match the webhook schemas of GitHub', () => {
	it.each(fixtures)('%s', (name) => {
		const event = readFixture(name, 'type.txt').trim();
		const payload = JSON.parse(readFixture(name));
		const schema = findSchema(event, payload.action);

		expect(schema, `${event} has no schema for the action ${payload.action}`).toBeDefined();

		const errors = validate(payload, schema as Schema, '2020-12', lookup, false)
			.errors.filter((error) => !WRAPPERS.includes(error.keyword))
			.map(describeError)
			.filter((error) => !SPEC_MISTAKES[name]?.includes(error));

		expect([...new Set(errors)]).toEqual([]);
		expect(unknownProperties(payload, branches(schema), '')).toEqual([]);
	});
});
