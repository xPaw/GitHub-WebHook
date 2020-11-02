<?php
class IgnoredEventTest extends \PHPUnit\Framework\TestCase
{
	/**
	 * @dataProvider ignoredEventProvider
     */
	public function testForkEvent( string $Event ) : void
	{
		$this->expectException( GitHubIgnoredEventException::class );
		
		$Parser = new GitHub_IRC( $Event, (object)[] );
		$Parser->GetMessage();
	}
	
	/**
	 * @return array<array<string>>
	 */
	public function ignoredEventProvider( ) : array
	{
		return [
			[ 'fork' ],
			[ 'watch' ],
			[ 'status' ],
		];
	}
}
