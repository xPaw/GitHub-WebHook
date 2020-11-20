<?php
class UnknownEventTest extends \PHPUnit\Framework\TestCase
{
	public function testForkEvent( ) : void
	{
		$this->expectException( NotImplementedException::class );
		$this->expectExceptionMessage( 'Unsupported event type' );

		$Parser = new IrcConverter( 'surely_this_event_does_not_exist', (object)[] );
		$Parser->GetMessage();
	}
}
