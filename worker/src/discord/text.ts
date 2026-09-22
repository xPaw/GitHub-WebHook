const MARKDOWN_SPECIAL = /[\\*|`[\]()<>_~]/g;
const BACKTICK_RUNS = /`+/g;
// An unclosed comment hides the rest of the text, which is also how GitHub renders it
const HTML_COMMENT = /<!--[\s\S]*?(?:-->|$)/g;
// Requires a tag name, so that a lone "<" in text such as "a < b" is left alone.
// A tag never contains another "<", which keeps text full of unclosed tags cheap to scan.
const HTML_TAG = /<\/?[a-z](?:[^<>"']|"[^"<]*"|'[^'<]*')*>/gi;

/** Maximum number of newlines kept by {@link shortDescription}; the rest become spaces. */
const MAX_NEWLINES = 10;
/** How much of a body {@link shortDescription} keeps. */
const MAX_SHORT_DESCRIPTION = 250;
/** How much of the first line {@link shortMessage} keeps. */
const MAX_SHORT_MESSAGE = 100;
/** Backslashes at the very end of a string, which may be half of an escaped character. */
const TRAILING_BACKSLASHES = /\\+$/;

/** Escapes characters that Discord would otherwise interpret as markdown. */
export function escape(message: string): string {
	return message.replace(MARKDOWN_SPECIAL, (character) => `\\${character}`);
}

/** Wraps a string in an inline code span. */
export function escapeCode(message: string): string {
	// A run of backticks can only be inside of a code span that is delimited by a longer run
	const runs = message.match(BACKTICK_RUNS);

	if (runs === null) {
		return `\`${message}\``;
	}

	const delimiter = '`'.repeat(Math.max(...runs.map((run) => run.length)) + 1);

	// The spaces keep a backtick at either end of the message apart from the delimiter
	return `${delimiter} ${message} ${delimiter}`;
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

/** Cuts text that is over a limit imposed by Discord, the ellipsis counts towards the limit. */
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

/**
 * Formats a body of text (issue, release, comment…) for use as an embed description:
 * html stripped, blank lines collapsed, newlines and length limited.
 */
export function shortDescription(message: string | null | undefined): string {
	let text = (message ?? '').replace(HTML_COMMENT, '').replace(HTML_TAG, '');
	text = text.replaceAll('\r', '').replaceAll('\n\n', '\n').trim();

	const lines = text.split('\n');

	if (lines.length > MAX_NEWLINES + 1) {
		text = `${lines.slice(0, MAX_NEWLINES + 1).join('\n')} ${lines.slice(MAX_NEWLINES + 1).join(' ')}`;
	}

	const truncated = truncate(text, MAX_SHORT_DESCRIPTION);

	return truncated === text ? text : `${truncated}…`;
}
