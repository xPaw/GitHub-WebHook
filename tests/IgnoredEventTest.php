<?php
declare(strict_types=1);

class IgnoredEventTest extends \PHPUnit\Framework\TestCase
{
	/**
	 * @dataProvider ignoredEventProvider
     */
	public function testForkEvent( string $Event ) : void
	{
		$this->expectException( IgnoredEventException::class );
		
		$Parser = new IrcConverter( $Event, (object)[] );
		$Parser->GetMessage();
	}
	
	/**
	 * @return array<array<string>>
	 */
	public static function ignoredEventProvider( ) : array
	{
		return [
			[ 'fork' ],
			[ 'watch' ],
			[ 'star' ],
			[ 'status' ],
		];
	}
}
