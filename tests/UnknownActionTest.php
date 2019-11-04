<?php
class UnknownActionTest extends PHPUnit\Framework\TestCase
{
	/**
	 * @dataProvider eventProvider
	 */
	public function testUnknownAction( $Event )
	{
		$this->expectException( GitHubNotImplementedException::class );
		$this->expectExceptionMessage( 'Unsupported action type "surely_this_action_does_not_exist"' );

		$Parser = new GitHub_IRC( $Event, (object)[ 'action' => 'surely_this_action_does_not_exist' ] );
		$Parser->GetMessage();
	}
	
	public function eventProvider( )
	{
		return [
			//[ 'ping' ], // no action
			//[ 'push' ], // no action
			//[ 'public' ], // no action
			[ 'issues' ],
			[ 'member' ],
			//[ 'gollum' ], // no action
			[ 'release' ],
			[ 'repository' ],
			[ 'pull_request' ],
			[ 'issue_comment' ],
			[ 'commit_comment' ],
			[ 'pull_request_review_comment' ],
		];
	}
}
