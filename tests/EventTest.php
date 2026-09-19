<?php
declare(strict_types=1);

use GitHubWebHook\DiscordConverter;
use GitHubWebHook\IrcConverter;
use PHPUnit\Framework\Attributes\DataProvider;

class EventTest extends \PHPUnit\Framework\TestCase
{
	use Fixtures;

	#[DataProvider('eventProvider')]
	public function testEvent( string $Path, string $EventType, string $ExpectedMessage, string $Payload, string $ExpectedDiscord ) : void
	{
		// Process incoming event
		$Hook = self::ProcessPayload( $EventType, $Payload );

		self::assertEquals( $EventType, $Hook->GetEventType() );

		$Original = unserialize( serialize( $Hook->GetPayload() ) );

		// Convert processed event into an irc string
		$Parser = new IrcConverter( $Hook->GetEventType(), $Hook->GetPayload() );
		$Message = $Parser->GetMessage();

		if( self::ShouldUpdateFixtures() )
		{
			file_put_contents( $Path . '/expected.bin', $Message . "\n" );
			$ExpectedMessage = $Message;
		}

		self::assertEquals( $ExpectedMessage, $Message, $Path );

		$ExpectedDiscordArray = json_decode( $ExpectedDiscord, true );

		$Parser = new DiscordConverter( $Hook->GetEventType(), $Hook->GetPayload() );
		$Discord = $Parser->GetEmbed();

		if( self::ShouldUpdateFixtures() )
		{
			file_put_contents( $Path . '/discord.json', json_encode( $Discord, JSON_PRETTY_PRINT ) . "\n" );
			$ExpectedDiscordArray = $Discord;
		}

		self::assertEquals( $ExpectedDiscordArray, $Discord, $Path );

		// The converters must not modify the payload they were handed
		self::assertEquals( $Original, $Hook->GetPayload(), $Path );
	}

	/**
	 * Run the tests with UPDATE_FIXTURES=1 to write the current output as the expected one,
	 * then review what changed with git.
	 */
	private static function ShouldUpdateFixtures() : bool
	{
		return getenv( 'UPDATE_FIXTURES' ) === '1';
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
