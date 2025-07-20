<?php
declare(strict_types=1);

use GitHubWebHook\IrcConverter;
use GitHubWebHook\NotImplementedException;
use PHPUnit\Framework\Attributes\DataProvider;

class UnknownActionTest extends \PHPUnit\Framework\TestCase
{
	#[DataProvider('eventProvider')]
	public function testUnknownAction( string $Event ) : void
	{
		$this->expectException( NotImplementedException::class );
		$this->expectExceptionMessage( 'Unsupported action type "surely_this_action_does_not_exist"' );

		$Parser = new IrcConverter( $Event, (object)[ 'action' => 'surely_this_action_does_not_exist' ] );
		$Parser->GetMessage();
	}

	/**
	 * @return array<array<string>>
	 */
	public static function eventProvider( ) : array
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
