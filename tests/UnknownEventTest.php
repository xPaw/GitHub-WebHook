<?php
class UnknownEventTest extends PHPUnit\Framework\TestCase
{
	public function testForkEvent( )
	{
		$this->expectException( GitHubNotImplementedException::class );
		$this->expectExceptionMessage( 'Unsupported event type' );

		$Parser = new GitHub_IRC( 'surely_this_event_does_not_exist', null );
		$Parser->GetMessage();
	}
}
