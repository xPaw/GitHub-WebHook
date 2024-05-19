<?php
declare(strict_types=1);

use GitHubWebHook\IgnoredEventException;
use GitHubWebHook\IrcConverter;

class IgnoredActionsThrowTest extends \PHPUnit\Framework\TestCase
{
	/**
	 * @dataProvider ignoredIssueActionProvider
	 */
	public function testIssueThrow( string $Action ) : void
	{
		$this->expectException( IgnoredEventException::class );

		$Parser = new IrcConverter( 'issues', (object)[ 'action' => $Action ] );
		$Parser->GetMessage();
	}

	/**
	 * @dataProvider ignoredPullRequestActionProvider
	 */
	public function testPullRequestThrow( string $Action ) : void
	{
		$this->expectException( IgnoredEventException::class );

		$Parser = new IrcConverter( 'pull_request', (object)[ 'action' => $Action ] );
		$Parser->GetMessage();
	}

	/**
	 * @dataProvider ignoredPullRequestReviewActionProvider
	 */
	public function testPullRequestReviewThrow( string $Action ) : void
	{
		$this->expectException( IgnoredEventException::class );

		$Parser = new IrcConverter( 'pull_request_review', (object)[ 'action' => 'submitted', 'review' => (object)[ 'state' => $Action ] ] );
		$Parser->GetMessage();
	}

	/**
	 * @dataProvider ignoredMilestoneActionProvider
	 */
	public function testMilestoneThrow( string $Action ) : void
	{
		$this->expectException( IgnoredEventException::class );

		$Parser = new IrcConverter( 'milestone', (object)[ 'action' => $Action ] );
		$Parser->GetMessage();
	}

	/**
	 * @return array<array<string>>
	 */
	public static function ignoredIssueActionProvider( ) : array
	{
		return [
			[ 'labeled' ],
			[ 'unlabeled' ],
			[ 'assigned' ],
			[ 'unassigned' ],
		];
	}

	/**
	 * @return array<array<string>>
	 */
	public static function ignoredPullRequestActionProvider( ) : array
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

	/**
	 * @return array<array<string>>
	 */
	public static function ignoredPullRequestReviewActionProvider( ) : array
	{
		return [
			[ 'commented' ],
		];
	}

	/**
	 * @return array<array<string>>
	 */
	public static function ignoredMilestoneActionProvider( ) : array
	{
		return [
			[ 'edited' ],
		];
	}
}
