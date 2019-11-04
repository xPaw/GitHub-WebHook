<?php
class IgnoredEventTest extends PHPUnit\Framework\TestCase
{
	/**
	 * @dataProvider ignoredEventProvider
     */
	public function testForkEvent( $Event )
	{
		$this->expectException( GitHubIgnoredEventException::class );
		
		$Parser = new GitHub_IRC( $Event, null );
		$Parser->GetMessage();
	}
	
	public function ignoredEventProvider( )
	{
		return [
			[ 'fork' ],
			[ 'watch' ],
			[ 'status' ],
		];
	}
}
