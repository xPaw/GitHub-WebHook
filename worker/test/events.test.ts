import type { APIContainerComponent, APITextDisplayComponent } from 'discord-api-types/v10';
import { describe, expect, it } from 'vitest';
import { getEmbed } from '../src/discord/converter.js';
import { fixtures, readFixture } from './fixtures.js';

describe('event fixtures', () => {
	it('found the fixtures', () => {
		expect(fixtures.length).toBeGreaterThan(0);
	});

	it.each(fixtures)('%s', (name) => {
		const eventType = readFixture(name, 'type.txt').trim();
		const raw = readFixture(name);
		const payload = JSON.parse(raw);

		expect(getEmbed(eventType, payload)).toEqual(JSON.parse(readFixture(name, 'discord.json')));

		// The converter must not modify the payload it was handed
		expect(payload).toEqual(JSON.parse(raw));
	});

	// Discord shows a backslash in the text of a link rather than escaping anything with it.
	// An escaped bracket starts no link, and brackets in the text of one come in pairs.
	const LINK = /(?<!\\)\[((?:[^[\]]|\[[^\]]*])*)]\(/g;

	it.each(fixtures)('%s escapes nothing in the text of a link', (name) => {
		const message = getEmbed(readFixture(name, 'type.txt').trim(), JSON.parse(readFixture(name)));
		const container = message.components[0] as APIContainerComponent;

		for (const component of container.components as APITextDisplayComponent[]) {
			for (const [, text] of component.content.matchAll(LINK)) {
				expect(text).not.toContain('\\');
			}
		}
	});
});
