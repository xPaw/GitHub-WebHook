<?php
declare(strict_types=1);

use GitHubWebHook\DiscordConverter;
use GitHubWebHook\GitHubWebHook;
use GitHubWebHook\IrcConverter;
use PHPUnit\Framework\Attributes\DataProvider;

class EventTest extends \PHPUnit\Framework\TestCase
{
	#[DataProvider('eventProvider')]
	public function testEvent( string $Path, string $EventType, string $ExpectedMessage, string $Payload, ?string $ExpectedDiscord ) : void
	{
		// Setup env for processor
		$_SERVER[ 'HTTP_X_GITHUB_EVENT' ] = $EventType;
		$_SERVER[ 'REQUEST_METHOD' ] = 'POST';
		$_SERVER[ 'CONTENT_TYPE' ] = 'application/x-www-form-urlencoded';
		$_POST[ 'payload' ] = $Payload;

		// Process incoming event
		$Hook = new GitHubWebHook( );
		$Hook->ProcessRequest( );

		self::assertEquals( $EventType, $Hook->GetEventType() );

		// Convert processed event into an irc string
		$Parser = new IrcConverter( $Hook->GetEventType(), $Hook->GetPayload() );
		$Message = $Parser->GetMessage();

		//file_put_contents( $Path . '/expected.bin', $Message . "\n" );

		self::assertEquals( $ExpectedMessage, $Message, $Path );

		if( $ExpectedDiscord !== null )
		{
			$ExpectedDiscordArray = json_decode( $ExpectedDiscord, true );

			$Hook->ProcessRequest( ); // parse again because irc formatter can mutate the payload
			$Parser = new DiscordConverter( $Hook->GetEventType(), $Hook->GetPayload() );
			$Discord = $Parser->GetEmbed();

			//file_put_contents( $Path . '/discord.json', json_encode( $Discord, JSON_PRETTY_PRINT ) . "\n" );

			self::assertEquals( $ExpectedDiscordArray, $Discord, $Path );
		}
	}

	/**
	 * @return array<array<string>>
	 */
	public static function eventProvider() : array
	{
		$ProvidedData = [];

		foreach( new DirectoryIterator( __DIR__ . DIRECTORY_SEPARATOR . 'events' ) as $File )
		{
			if( $File->isDot() || !$File->isDir() )
			{
				continue;
			}

			$Path = $File->getPathname();

			$ProvidedData[] =
			[
				$Path,
				trim( (string)file_get_contents( $Path . DIRECTORY_SEPARATOR . 'type.txt' ) ),
				trim( (string)file_get_contents( $Path . DIRECTORY_SEPARATOR . 'expected.bin' ) ),
				(string)file_get_contents( $Path . DIRECTORY_SEPARATOR . 'payload.json' ),
				(string)file_get_contents( $Path . DIRECTORY_SEPARATOR . 'discord.json' ),
			];
		}

		return $ProvidedData;
	}
}
