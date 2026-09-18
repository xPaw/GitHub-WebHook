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
});
