<?php
declare(strict_types=1);

use GitHubWebHook\GitHubWebHook;

/**
 * The event fixtures, for the tests that build their payloads on them.
 */
trait Fixtures
{
	private static function LoadPayload( string $Fixture ) : stdClass
	{
		$Path = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . $Fixture . DIRECTORY_SEPARATOR . 'payload.json';

		$Payload = json_decode( (string)file_get_contents( $Path ), flags: JSON_THROW_ON_ERROR );

		assert( $Payload instanceof stdClass );

		return $Payload;
	}

	/**
	 * Hands a payload to the hook the way a request from GitHub would.
	 * Events of an organization have no repository until the request is processed.
	 */
	private static function ProcessPayload( string $Event, string $Payload ) : GitHubWebHook
	{
		$_SERVER[ 'HTTP_X_GITHUB_EVENT' ] = $Event;
		$_SERVER[ 'REQUEST_METHOD' ] = 'POST';
		$_SERVER[ 'CONTENT_TYPE' ] = 'application/x-www-form-urlencoded';
		$_POST[ 'payload' ] = $Payload;

		$Hook = new GitHubWebHook( );
		$Hook->ProcessRequest( );

		return $Hook;
	}

	/**
	 * Every event whose payload has an action, mapped to a fixture of it. The fixtures of an event
	 * either all have an action or none do (ping, push, public, delete and gollum have none), and
	 * the action is checked before anything else in the payload is read, so any fixture will do.
	 *
	 * @return array<string, string>
	 */
	private static function ActionFixtures( ) : array
	{
		$Fixtures = [];

		foreach( new DirectoryIterator( dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'fixtures' ) as $File )
		{
			if( $File->isDot() || !$File->isDir() )
			{
				continue;
			}

			$Fixture = $File->getFilename();

			if( !isset( self::LoadPayload( $Fixture )->action ) )
			{
				continue;
			}

			$Event = trim( (string)file_get_contents( $File->getPathname() . DIRECTORY_SEPARATOR . 'type.txt' ) );

			$Fixtures[ $Event ] = $Fixture;
		}

		return $Fixtures;
	}
}
