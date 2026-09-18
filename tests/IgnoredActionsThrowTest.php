<?php
declare(strict_types=1);

use GitHubWebHook\DiscordConverter;
use GitHubWebHook\IgnoredEventException;
use GitHubWebHook\IrcConverter;
use PHPUnit\Framework\Attributes\DataProvider;

class IgnoredActionsThrowTest extends \PHPUnit\Framework\TestCase
{
	#[DataProvider('ignoredActionProvider')]
	public function testIrcThrow( string $Event, object $Payload, string $Message ) : void
	{
		$this->expectException( IgnoredEventException::class );
		$this->expectExceptionMessage( $Message );

		$Parser = new IrcConverter( $Event, $Payload );
		$Parser->GetMessage();
	}

	#[DataProvider('ignoredActionProvider')]
	public function testDiscordThrow( string $Event, object $Payload, string $Message ) : void
	{
		$this->expectException( IgnoredEventException::class );
		$this->expectExceptionMessage( $Message );

		$Parser = new DiscordConverter( $Event, $Payload );
		$Parser->GetEmbed();
	}

	/**
	 * @return array<string, array{string, object, string}>
	 */
	public static function ignoredActionProvider( ) : array
	{
		$Events =
		[
			[ 'issues', 'issue_opened', [ 'edited', 'unpinned', 'milestoned', 'demilestoned', 'labeled', 'unlabeled', 'assigned', 'unassigned', 'typed', 'untyped' ] ],
			[ 'pull_request', 'pull_request_closed_merged', [ 'edited', 'synchronize', 'labeled', 'unlabeled', 'assigned', 'unassigned', 'review_requested', 'review_request_removed', 'milestoned', 'demilestoned', 'enqueued', 'dequeued', 'auto_merge_disabled' ] ],
			[ 'pull_request_review', 'pull_request_review', [ 'edited' ] ],
			[ 'pull_request_review_comment', 'pull_request_review_comment', [ 'edited', 'deleted' ] ],
			[ 'milestone', 'milestone', [ 'edited' ] ],
			[ 'release', 'release', [ 'created', 'edited', 'released', 'prereleased' ] ],
			[ 'member', 'member', [ 'edited' ] ],
			[ 'issue_comment', 'issue_comment', [ 'edited' ] ],
			[ 'discussion', 'discussion_created', [ 'edited', 'labeled', 'unlabeled', 'unanswered' ] ],
			[ 'discussion_comment', 'discussion_comment_created', [ 'edited' ] ],
			[ 'repository', 'repository', [ 'edited' ] ],
			[ 'code_scanning_alert', 'code_scanning_alert_created', [ 'appeared_in_branch' ] ],
			[ 'secret_scanning_alert', 'secret_scanning_alert_created', [ 'assigned', 'unassigned', 'validated' ] ],
		];

		$ProvidedData = [];

		foreach( $Events as [ $Event, $Fixture, $Actions ] )
		{
			foreach( $Actions as $Action )
			{
				$Payload = self::LoadPayload( $Fixture );
				$Payload->action = $Action;

				$ProvidedData[ "$Event - $Action" ] = [ $Event, $Payload, "$Event - $Action" ];
			}
		}

		$Payload = self::LoadPayload( 'pull_request_review' );
		$Payload->review->state = 'commented';

		$ProvidedData[ 'pull_request_review - commented' ] = [ 'pull_request_review', $Payload, 'pull_request_review - commented' ];

		return $ProvidedData;
	}

	private static function LoadPayload( string $Fixture ) : stdClass
	{
		$Path = __DIR__ . DIRECTORY_SEPARATOR . 'events' . DIRECTORY_SEPARATOR . $Fixture . DIRECTORY_SEPARATOR . 'payload.json';

		$Payload = json_decode( (string)file_get_contents( $Path ), flags: JSON_THROW_ON_ERROR );

		assert( $Payload instanceof stdClass );

		return $Payload;
	}
}
