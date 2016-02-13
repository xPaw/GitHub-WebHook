<?php
class UnknownEventTest extends PHPUnit_Framework_TestCase
{
	/**
     * @expectedException        GitHubNotImplementedException
     * @expectedExceptionMessage Unsupported event type 
     */
	public function testForkEvent( )
	{
		$Parser = new GitHub_IRC( 'surely_this_event_does_not_exist', null );
		$Parser->GetMessage();
	}
}
