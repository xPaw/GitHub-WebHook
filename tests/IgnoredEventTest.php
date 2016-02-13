<?php
class IgnoredEventTest extends PHPUnit_Framework_TestCase
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
