/** Variables that are set on the deployed Worker rather than in wrangler.jsonc. */
interface Env {
	/** JSON mapping repository patterns to their secret and Discord webhook urls, as text or as a JSON variable. */
	REPOSITORIES?: unknown;
}
