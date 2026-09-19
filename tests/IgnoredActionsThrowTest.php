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
			[ 'issues', 'issue_opened', [ 'edited', 'unpinned', 'milestoned', 'demilestoned', 'labeled', 'unlabeled', 'assigned', 'unassigned', 'typed', 'untyped', 'field_added', 'field_removed' ] ],
			[ 'pull_request', 'pull_request_closed_merged', [ 'edited', 'synchronize', 'labeled', 'unlabeled', 'assigned', 'unassigned', 'review_requested', 'review_request_removed', 'milestoned', 'demilestoned', 'enqueued', 'dequeued', 'auto_merge_disabled', 'stacked' ] ],
			[ 'pull_request_review', 'pull_request_review', [ 'edited' ] ],
			[ 'pull_request_review_comment', 'pull_request_review_comment', [ 'edited', 'deleted' ] ],
			[ 'milestone', 'milestone', [ 'edited' ] ],
			[ 'release', 'release', [ 'created', 'edited', 'released', 'prereleased' ] ],
			[ 'member', 'member', [ 'edited' ] ],
			[ 'issue_comment', 'issue_comment', [ 'edited', 'pinned', 'unpinned' ] ],
			[ 'discussion', 'discussion_created', [ 'edited', 'labeled', 'unlabeled', 'unanswered' ] ],
			[ 'discussion_comment', 'discussion_comment_created', [ 'edited' ] ],
			[ 'repository', 'repository', [ 'edited' ] ],
			[ 'dependabot_alert', 'dependabot_alert_created', [ 'assignees_changed' ] ],
			[ 'code_scanning_alert', 'code_scanning_alert_created', [ 'appeared_in_branch', 'updated_assignment' ] ],
			[ 'secret_scanning_alert', 'secret_scanning_alert_created', [ 'assigned', 'unassigned', 'validated', 'metadata_created', 'metadata_removed' ] ],
			[ 'project', 'project', [ 'edited' ] ],
			[ 'workflow_run', 'workflow_run_failed', [ 'requested', 'in_progress' ] ],
			[ 'sponsorship', 'sponsorship_created', [ 'cancelled', 'edited', 'tier_changed', 'pending_cancellation', 'pending_tier_change' ] ],
			[ 'branch_protection_rule', 'branch_protection_rule_created', [ 'edited' ] ],
			[ 'projects_v2', 'projects_v2_created', [ 'edited' ] ],
			[ 'projects_v2_status_update', 'projects_v2_status_update', [ 'edited', 'deleted' ] ],
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

		// Only a run that broke the default branch is worth telling
		foreach( [ 'success', 'cancelled', 'skipped', null ] as $Conclusion )
		{
			$Payload = self::LoadPayload( 'workflow_run_failed' );
			$Payload->workflow_run->conclusion = $Conclusion;

			$Message = 'workflow_run - ' . ( $Conclusion ?? 'null' );

			$ProvidedData[ $Message ] = [ 'workflow_run', $Payload, $Message ];
		}

		$Payload = self::LoadPayload( 'workflow_run_failed' );
		$Payload->workflow_run->head_branch = 'feature';

		$ProvidedData[ 'workflow_run - another branch' ] = [ 'workflow_run', $Payload, 'workflow_run - not the default branch' ];

		// Only a new name or a new privacy is worth telling
		$Payload = self::LoadPayload( 'team_edited' );
		$Payload->changes = (object)[ 'description' => (object)[ 'from' => 'An older description' ] ];

		$ProvidedData[ 'team - edited description' ] = [ 'team', $Payload, 'team - edited' ];

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
