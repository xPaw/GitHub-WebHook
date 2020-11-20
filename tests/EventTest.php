<?php
class EventTest extends \PHPUnit\Framework\TestCase
{
	/**
	 * @dataProvider eventProvider
	 */
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
		
		$this->assertEquals( $EventType, $Hook->GetEventType() );
		
		// Convert processed event into an irc string
		$Parser = new IrcConverter( $Hook->GetEventType(), $Hook->GetPayload() );
		$Message = $Parser->GetMessage();
		
		$this->assertEquals( $ExpectedMessage, $Message, $Path );

		if( $ExpectedDiscord !== null )
		{
			$ExpectedDiscordArray = json_decode( $ExpectedDiscord, true );

			$Hook->ProcessRequest( ); // parse again because irc formatter can mutate the payload
			$Parser = new DiscordConverter( $Hook->GetEventType(), $Hook->GetPayload() );
			$Discord = $Parser->GetEmbed();

			//file_put_contents( $Path . '/discord.json', json_encode( $Discord, JSON_PRETTY_PRINT ) . "\n" );

			$this->assertEquals( $ExpectedDiscordArray, $Discord, $Path );
		}
	}
	
	/**
	 * @return array<array<string>>
	 */
	public function eventProvider() : array
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
