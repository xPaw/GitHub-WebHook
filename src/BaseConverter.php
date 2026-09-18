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
