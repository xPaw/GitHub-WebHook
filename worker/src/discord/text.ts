const MARKDOWN_SPECIAL = /[\\*|`[\]()<>_]/g;
// An unclosed comment hides the rest of the text, which is also how GitHub renders it
const HTML_COMMENT = /<!--[\s\S]*?(?:-->|$)/g;
// Requires a tag name, so that a lone "<" in text such as "a < b" is left alone.
// A tag never contains another "<", which keeps text full of unclosed tags cheap to scan.
const HTML_TAG = /<\/?[a-z](?:[^<>"']|"[^"<]*"|'[^'<]*')*>/gi;

/** Maximum number of newlines kept by {@link shortDescription}; the rest become spaces. */
const MAX_NEWLINES = 10;

/** Escapes characters that Discord would otherwise interpret as markdown. */
export function escape(message: string): string {
	return message.replace(MARKDOWN_SPECIAL, (character) => `\\${character}`);
}

/** Wraps a string in an inline code span, escaping any backticks it contains. */
export function escapeCode(message: string): string {
	return `\`${message.replaceAll('`', '``')}\``;
}

/** Truncates to a number of code points, so that emoji and other astral characters stay intact. */
function truncate(text: string, limit: number): string {
	const characters = [...text];
	return characters.length > limit ? characters.slice(0, limit).join('') : text;
}

/** Cuts text that is over a limit imposed by Discord, the ellipsis counts towards the limit. */
export function limitLength(text: string, limit: number): string {
	const truncated = truncate(text, limit - 1);

	return [...text].length > limit ? `${truncated}…` : text;
}

/**
 * Formats the first line of a commit or wiki message, truncated and markdown escaped.
 */
export function shortMessage(message: string, limit = 100): string {
	const full = message.trim();
	let short = truncate(full.split('\n', 1)[0], limit);

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
export function shortDescription(message: string | null | undefined, limit = 250): string {
	let text = (message ?? '').replace(HTML_COMMENT, '').replace(HTML_TAG, '');
	text = text.replaceAll('\r', '').replaceAll('\n\n', '\n');

	const lines = text.split('\n');

	if (lines.length > MAX_NEWLINES + 1) {
		text = `${lines.slice(0, MAX_NEWLINES + 1).join('\n')} ${lines.slice(MAX_NEWLINES + 1).join(' ')}`;
	}

	const truncated = truncate(text, limit);

	return truncated === text ? text : `${truncated}…`;
}
