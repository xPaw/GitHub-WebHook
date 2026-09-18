<?php
declare(strict_types=1);

namespace GitHubWebHook;

class BaseConverter
{
	protected string $EventType;
	protected object $Payload;

	/** Name of the pushed ref without the refs/heads/ or refs/tags/ prefix. */
	protected string $RefName = '';

	/** Name of the ref that the pushed ref was based on, if there was one. */
	protected ?string $BaseRefName = null;

	public function __construct( string $EventType, object $Payload )
	{
		$this->EventType = $EventType;
		$this->Payload = $Payload;

		if( isset( $this->Payload->ref ) )
		{
			$this->RefName = self::GetRefName( $this->Payload->ref );
		}

		if( isset( $this->Payload->base_ref ) )
		{
			$this->BaseRefName = self::GetRefName( $this->Payload->base_ref );
		}
	}

	/** Wiki pages listed for a single update, the same as commits in a push. */
	protected const int MAX_WIKI_PAGES = 5;

	/**
	 * Splits an action into the verb that goes before the thing it happened to, and what goes after it,
	 * so that a sentence reads "closed issue #5 as not planned" rather than "closed as not planned issue #5".
	 *
	 * @return array{string, string}
	 */
	protected static function ActionPhrase( string $Action ) : array
	{
		return match( $Action )
		{
			'closed as not planned' => [ 'closed', ' as not planned' ],
			'closed without merging' => [ 'closed', ' without merging' ],
			'readied' => [ 'marked', ' as ready for review' ],
			'enabled auto-merge' => [ 'enabled auto-merge on', '' ],
			'converted to draft' => [ 'converted', ' to draft' ],
			'changed category' => [ 'changed category of', '' ],
			default => [ $Action, '' ],
		};
	}

	/**
	 * Like trim(), but it also removes unicode spaces such as the no-break space.
	 * The lookbehind only lets the end match at the start of a run of spaces, which keeps long runs cheap.
	 */
	protected static function Trim( string $Message ) : string
	{
		return preg_replace( '/^[\s\p{Z}\x{FEFF}]+|(?<![\s\p{Z}\x{FEFF}])[\s\p{Z}\x{FEFF}]+$/u', '', $Message ) ?? trim( $Message );
	}

	private static function GetRefName( string $Ref ) : string
	{
		return explode( '/', $Ref, 3 )[ 2 ] ?? $Ref;
	}

	/**
	 * Returns distinct commits which have non-empty commit messages.
	 *
	 * @return array<object>
	 */
	protected function GetDistinctCommits( ) : array
	{
		$Commits = [];

		foreach( $this->Payload->commits as $Commit )
		{
			if( isset( $Commit->distinct ) && !$Commit->distinct )
			{
				continue;
			}

			if( ( $Commit->message ?? '' ) !== '' )
			{
				$Commits[ ] = $Commit;
			}
		}

		return $Commits;
	}

	protected function BeforeSHA( ) : string
	{
		return substr( $this->Payload->before, 0, 6 );
	}

	protected function AfterSHA( ) : string
	{
		return substr( $this->Payload->after, 0, 6 );
	}
}
