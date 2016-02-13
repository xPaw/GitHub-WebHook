<?php
require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'GitHub_IRC.php';
require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'GitHub_WebHook.php';

class EventTest extends PHPUnit_Framework_TestCase
{
	/**
	 * @dataProvider eventProvider
	 */
	public function testEvent( $EventType, $Payload, $ExpectedMessage )
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
	
	public function eventProvider()
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
				$File->getFilename(),
				file_get_contents( $Path . DIRECTORY_SEPARATOR . 'payload.json' ),
				trim( file_get_contents( $Path . DIRECTORY_SEPARATOR . 'expected.bin' ) ),
			];
		}
		
		return $ProvidedData;
	}
}
