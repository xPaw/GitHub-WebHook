const MARKDOWN_SPECIAL = /[\\*|`[\]()<>_~]/g;
// Discord links a url wherever it starts, backslashes and all, and a browser reads those as slashes
const BARE_URL = /(?:https?|steam):\/\/[^\s<]+[^<.,:;"'\]\s]/g;
const BACKTICK_RUNS = /`+/g;
// Discord only drops the space that pads a code span when there is a backtick beside it
const LEADING_BACKTICK = /^ *`/;
const TRAILING_BACKTICK = /` *$/;
// An unclosed comment hides the rest of the text, which is also how GitHub renders it
const HTML_COMMENT = /<!--[\s\S]*?(?:-->|$)/g;
// Requires a tag name, so that a lone "<" in text such as "a < b" is left alone.
// A tag never contains another "<", which keeps text full of unclosed tags cheap to scan.
const HTML_TAG = /<\/?[a-z](?:[^<>"']|"[^"<]*"|'[^'<]*')*>/gi;

// A heading in a body would out-shout the title of the card it is in, so it becomes bold instead.
// Discord takes a heading and subtext however far they are indented, and a tab after the hashes.
const HEADING = /^ *#{1,6}\s+(\S.*)$/;
// Subtext is smaller than body text, which is what the scope and the labels of a card are set in
const SUBTEXT = /^ *-# +/;
// Only a line that opens with a run of three backticks fences a block; anywhere else they are text
const FENCE = /^ {0,3}```/;

/** How many wrapped lines of a body {@link formatBody} keeps. */
const MAX_BODY_LINES = 8;
/**
 * Roughly how many characters fit on one line beside the avatar. Body text is set in a proportional
 * font, so no count of characters really models where it wraps; this is an estimate at a usual width.
 */
const PER_LINE = 70;
/** Fenced code is monospace, and about four fifths the width of prose for it. */
const CODE_PER_LINE = 55;
/** How much of the first line {@link shortMessage} keeps. */
const MAX_SHORT_MESSAGE = 100;
/** Backslashes at the very end of a string, which may be half of an escaped character. */
const TRAILING_BACKSLASHES = /\\+$/;

/**
 * Escapes characters that Discord would otherwise interpret as markdown, everywhere but in a link
 * of its own. Nothing can be escaped in the text of a masked link, so none of this may end up there.
 */
export function escape(message: string): string {
	let escaped = '';
	let from = 0;

	for (const match of message.matchAll(BARE_URL)) {
		const url = trimParenthesis(match[0]);

		escaped += message.slice(from, match.index).replace(MARKDOWN_SPECIAL, (character) => `\\${character}`) + url;
		from = match.index + url.length;
	}

	return escaped + message.slice(from).replace(MARKDOWN_SPECIAL, (character) => `\\${character}`);
}

/** Discord leaves a closing parenthesis out of a url when there is no opening one for it. */
function trimParenthesis(url: string): string {
	let from = 0;

	for (let i = url.length - 1; i >= 0 && url[i] === ')'; i--) {
		const open = url.indexOf('(', from);

		if (open === -1) {
			return url.slice(0, -1);
		}

		from = open + 1;
	}

	return url;
}

/** Wraps a string in an inline code span. */
export function escapeCode(message: string): string {
	// A run of backticks can only be inside of a code span that is delimited by a longer run
	const runs = message.match(BACKTICK_RUNS);

	if (runs === null) {
		return `\`${message}\``;
	}

	const delimiter = '`'.repeat(Math.max(...runs.map((run) => run.length)) + 1);

	// A space keeps a backtick at either end of the message apart from the delimiter
	const start = LEADING_BACKTICK.test(message) ? ' ' : '';
	const end = TRAILING_BACKTICK.test(message) ? ' ' : '';

	return `${delimiter}${start}${message}${end}${delimiter}`;
}

/** Truncates to a number of code points, so that emoji and other astral characters stay intact. */
function truncate(text: string, limit: number): string {
	// There are never more code points than code units
	if (text.length <= limit) {
		return text;
	}

	// Any limit + 1 code points fit in twice as many code units, plus one
	const characters = [...text.slice(0, limit * 2 + 1)];
	return characters.length > limit ? characters.slice(0, limit).join('') : text;
}

/** Cuts text that is over a limit, the ellipsis counts towards it. */
export function limitLength(text: string, limit: number): string {
	if (truncate(text, limit) === text) {
		return text;
	}

	let cut = truncate(text, limit - 1);

	// Do not leave half of an escaped character behind
	if ((cut.length - cut.replace(TRAILING_BACKSLASHES, '').length) % 2 === 1) {
		cut = cut.slice(0, -1);
	}

	return `${cut}…`;
}

/**
 * Formats the first line of a commit or wiki message, truncated and markdown escaped.
 */
export function shortMessage(message: string): string {
	const full = message.trim();
	let short = truncate(full.split('\n', 1)[0], MAX_SHORT_MESSAGE);

	if (short !== full) {
		// Tidy ellipsis
		if (short.endsWith('...')) {
			short = `${short.slice(0, -3)}…`;
		} else if (!short.endsWith('…')) {
			short += '…';
		}
	}

	return escape(short);
}

/** Nothing quoted in a card may out-shout the card itself, so a heading is only made bold. */
function demote(line: string): string {
	return line.replace(HEADING, (_, heading: string) => `**${heading.replaceAll('*', '')}**`).replace(SUBTEXT, '');
}

/**
 * Formats a body of text (issue, release, comment…) for use as the body of a card: html stripped,
 * blank lines collapsed, headings flattened and height limited.
 *
 * How tall a card gets is what there is to limit, so a body is measured in the lines it takes up
 * once each of them has wrapped; a count of characters says very little about that. Which lines are
 * fenced is worked out once here, because it decides all three of how wide they wrap, whether their
 * markdown is left as written, and whether a cut has left a block open.
 */
export function formatBody(message: string | null | undefined): string {
	const text = (message ?? '')
		.replace(HTML_COMMENT, '')
		.replace(HTML_TAG, '')
		.replaceAll('\r', '')
		.replaceAll('\n\n', '\n')
		.trim();

	const kept: string[] = [];
	let used = 0;
	let fenced = false;

	// A line costs at least one of the budget, so no more of them can ever be kept than it allows
	for (const line of text.split('\n', MAX_BODY_LINES + 1)) {
		const fence = FENCE.test(line);
		// Inside a block a leading # is a comment rather than a heading, and is quoted as written
		const shown = fenced || fence ? line : demote(line);
		const perLine = fenced ? CODE_PER_LINE : PER_LINE;
		const characters = [...shown];
		const height = Math.max(1, Math.ceil(characters.length / perLine));

		if (used + height > MAX_BODY_LINES) {
			const room = (MAX_BODY_LINES - used) * perLine - 1;

			// Without room for even one character there is a line already kept to mark instead,
			// because the budget can only be used up by one
			if (room > 0) {
				kept.push(`${characters.slice(0, room).join('')}…`);
			} else {
				kept[kept.length - 1] += '…';
			}

			break;
		}

		used += height;
		kept.push(shown);

		if (fence) {
			fenced = !fenced;
		}
	}

	// A cut inside a block would leave it open, and an open block swallows the rest of the card
	return fenced ? `${kept.join('\n')}\n\`\`\`` : kept.join('\n');
}
