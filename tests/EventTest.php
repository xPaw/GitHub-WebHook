<?php
class EventTest extends \PHPUnit\Framework\TestCase
{
	/**
	 * @dataProvider eventProvider
	 */
	public function testEvent( string $EventType, string $ExpectedMessage, string $Payload ) : void
	{
		// Setup env for processor
		$_SERVER[ 'HTTP_X_GITHUB_EVENT' ] = $EventType;
		$_SERVER[ 'REQUEST_METHOD' ] = 'POST';
		$_SERVER[ 'CONTENT_TYPE' ] = 'application/x-www-form-urlencoded';
		$_POST[ 'payload' ] = $Payload;
		
		// Process incoming event
		$Hook = new GitHub_WebHook( );
		$Hook->ProcessRequest( );
		
		$this->assertEquals( $EventType, $Hook->GetEventType() );
		
		// Convert processed event into an irc string
		$Parser = new GitHub_IRC( $Hook->GetEventType(), $Hook->GetPayload() );
		$Message = $Parser->GetMessage();
		
		$this->assertEquals( $ExpectedMessage, $Message );
	}
	
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
				trim( file_get_contents( $Path . DIRECTORY_SEPARATOR . 'type.txt' ) ),
				trim( file_get_contents( $Path . DIRECTORY_SEPARATOR . 'expected.bin' ) ),
				file_get_contents( $Path . DIRECTORY_SEPARATOR . 'payload.json' ),
			];
		}

		return $ProvidedData;
	}
}
