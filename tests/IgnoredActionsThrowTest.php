<?php
class IgnoredActionsThrowTest extends PHPUnit_Framework_TestCase
{
	/**
	 * @dataProvider      ignoredActionProvider
     * @expectedException GitHubIgnoredEventException
     */
	public function testIssueThrow( $Action )
	{
		$Parser = new GitHub_IRC( 'issues', (object)[ 'action' => $Action ] );
		$Parser->GetMessage();
	}
	
	/**
	 * @dataProvider      ignoredActionProvider
     * @expectedException GitHubIgnoredEventException
     */
	public function testPullRequestThrow( $Action )
	{
		$Parser = new GitHub_IRC( 'pull_request', (object)[ 'action' => $Action ] );
		$Parser->GetMessage();
	}
	
	public function ignoredActionProvider( )
	{
		return [
			[ 'labeled' ],
			[ 'unlabeled' ],
			[ 'assigned' ],
			[ 'unassigned' ],
		];
	}
}
