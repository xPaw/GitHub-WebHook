<?php
class IgnoredEventTest extends PHPUnit\Framework\TestCase
{
	/**
	 * @dataProvider      ignoredEventProvider
     * @expectedException GitHubIgnoredEventException
     */
	public function testForkEvent( $Event )
	{
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
