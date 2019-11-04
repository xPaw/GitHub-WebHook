<?php
class IgnoredActionsThrowTest extends PHPUnit\Framework\TestCase
{
	/**
	 * @dataProvider ignoredIssueActionProvider
	 */
	public function testIssueThrow( $Action )
	{
		$this->expectException( GitHubIgnoredEventException::class );

		$Parser = new GitHub_IRC( 'issues', (object)[ 'action' => $Action ] );
		$Parser->GetMessage();
	}
	
	/**
	 * @dataProvider ignoredPullRequestActionProvider
	 */
	public function testPullRequestThrow( $Action )
	{
		$this->expectException( GitHubIgnoredEventException::class );

		$Parser = new GitHub_IRC( 'pull_request', (object)[ 'action' => $Action ] );
		$Parser->GetMessage();
	}
	
	/**
	 * @dataProvider ignoredPullRequestReviewActionProvider
	 */
	public function testPullRequestReviewThrow( $Action )
	{
		$this->expectException( GitHubIgnoredEventException::class );

		$Parser = new GitHub_IRC( 'pull_request_review', (object)[ 'action' => 'submitted', 'review' => (object)[ 'state' => $Action ] ] );
		$Parser->GetMessage();
	}
	
	/**
	 * @dataProvider ignoredMilestoneActionProvider
	 */
	public function testMilestoneThrow( $Action )
	{
		$this->expectException( GitHubIgnoredEventException::class );

		$Parser = new GitHub_IRC( 'milestone', (object)[ 'action' => $Action ] );
		$Parser->GetMessage();
	}
	
	public function ignoredIssueActionProvider( )
	{
		return [
			[ 'labeled' ],
			[ 'unlabeled' ],
			[ 'assigned' ],
			[ 'unassigned' ],
		];
	}
	
	public function ignoredPullRequestActionProvider( )
	{
		return [
			[ 'synchronize' ],
			[ 'labeled' ],
			[ 'unlabeled' ],
			[ 'assigned' ],
			[ 'unassigned' ],
			[ 'review_requested' ],
			[ 'review_request_removed' ],
		];
	}
	
	public function ignoredPullRequestReviewActionProvider( )
	{
		return [
			[ 'commented' ],
		];
	}
	
	public function ignoredMilestoneActionProvider( )
	{
		return [
			[ 'edited' ],
		];
	}
}
