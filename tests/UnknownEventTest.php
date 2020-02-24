<?php
class UnknownEventTest extends \PHPUnit\Framework\TestCase
{
	public function testForkEvent( ) : void
	{
		$this->expectException( GitHubNotImplementedException::class );
		$this->expectExceptionMessage( 'Unsupported event type' );

		$Parser = new GitHub_IRC( 'surely_this_event_does_not_exist', (object)[] );
		$Parser->GetMessage();
	}
}
