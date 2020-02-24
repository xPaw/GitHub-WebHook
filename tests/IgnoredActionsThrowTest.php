<?php
class IgnoredActionsThrowTest extends \PHPUnit\Framework\TestCase
{
	/**
	 * @dataProvider ignoredIssueActionProvider
	 */
	public function testIssueThrow( string $Action ) : void
	{
		$this->expectException( GitHubIgnoredEventException::class );

		$Parser = new GitHub_IRC( 'issues', (object)[ 'action' => $Action ] );
		$Parser->GetMessage();
	}
	
	/**
	 * @dataProvider ignoredPullRequestActionProvider
	 */
	public function testPullRequestThrow( string $Action ) : void
	{
		$this->expectException( GitHubIgnoredEventException::class );

		$Parser = new GitHub_IRC( 'pull_request', (object)[ 'action' => $Action ] );
		$Parser->GetMessage();
	}
	
	/**
	 * @dataProvider ignoredPullRequestReviewActionProvider
	 */
	public function testPullRequestReviewThrow( string $Action ) : void
	{
		$this->expectException( GitHubIgnoredEventException::class );

		$Parser = new GitHub_IRC( 'pull_request_review', (object)[ 'action' => 'submitted', 'review' => (object)[ 'state' => $Action ] ] );
		$Parser->GetMessage();
	}
	
	/**
	 * @dataProvider ignoredMilestoneActionProvider
	 */
	public function testMilestoneThrow( string $Action ) : void
	{
		$this->expectException( GitHubIgnoredEventException::class );

		$Parser = new GitHub_IRC( 'milestone', (object)[ 'action' => $Action ] );
		$Parser->GetMessage();
	}
	
	public function ignoredIssueActionProvider( ) : array
	{
		return [
			[ 'labeled' ],
			[ 'unlabeled' ],
			[ 'assigned' ],
			[ 'unassigned' ],
		];
	}
	
	public function ignoredPullRequestActionProvider( ) : array
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
	
	public function ignoredPullRequestReviewActionProvider( ) : array
	{
		return [
			[ 'commented' ],
		];
	}
	
	public function ignoredMilestoneActionProvider( ) : array
	{
		return [
			[ 'edited' ],
		];
	}
}
