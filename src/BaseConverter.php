<?php
declare(strict_types=1);

class BaseConverter
{
	protected string $EventType;
	protected object $Payload;

	public function __construct( string $EventType, object $Payload )
	{
		$this->EventType = $EventType;
		$this->Payload = $Payload;

		// ref_name is not always available, apparently, we make sure it is
		if( !isset( $this->Payload->ref_name ) && isset( $this->Payload->ref ) )
		{
			$Ref = explode( '/', $this->Payload->ref, 3 );

			if( isset( $Ref[ 2 ] ) )
			{
				$this->Payload->ref_name = $Ref[ 2 ];
			}
		}

		if( !isset( $this->Payload->base_ref_name ) && isset( $this->Payload->base_ref ) )
		{
			$Ref = explode( '/', $this->Payload->base_ref, 3 );

			if( isset( $Ref[ 2 ] ) )
			{
				$this->Payload->base_ref_name = $Ref[ 2 ];
			}
		}
	}

	/**
	 * Returns distinct commits which have non-empty commit messages
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

			if( !empty( $Commit->message ) )
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
