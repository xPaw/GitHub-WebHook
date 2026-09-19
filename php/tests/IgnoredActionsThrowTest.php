<?php
declare(strict_types=1);

use GitHubWebHook\DiscordConverter;
use GitHubWebHook\IgnoredEventException;
use GitHubWebHook\IrcConverter;
use PHPUnit\Framework\Attributes\DataProvider;

class IgnoredActionsThrowTest extends \PHPUnit\Framework\TestCase
{
	use Fixtures;

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
			'issues' => [ 'edited', 'unpinned', 'milestoned', 'demilestoned', 'labeled', 'unlabeled', 'assigned', 'unassigned', 'typed', 'untyped', 'field_added', 'field_removed' ],
			'pull_request' => [ 'edited', 'synchronize', 'labeled', 'unlabeled', 'assigned', 'unassigned', 'review_requested', 'review_request_removed', 'milestoned', 'demilestoned', 'enqueued', 'dequeued', 'auto_merge_disabled', 'stacked' ],
			'pull_request_review' => [ 'edited' ],
			'pull_request_review_comment' => [ 'edited', 'deleted' ],
			'milestone' => [ 'edited' ],
			'release' => [ 'created', 'edited', 'released', 'prereleased' ],
			'member' => [ 'edited' ],
			'issue_comment' => [ 'edited', 'pinned', 'unpinned' ],
			'discussion' => [ 'edited', 'labeled', 'unlabeled', 'unanswered' ],
			'discussion_comment' => [ 'edited' ],
			'repository' => [ 'edited' ],
			'dependabot_alert' => [ 'assignees_changed' ],
			'code_scanning_alert' => [ 'appeared_in_branch', 'updated_assignment' ],
			'secret_scanning_alert' => [ 'assigned', 'unassigned', 'validated', 'metadata_created', 'metadata_removed' ],
			'project' => [ 'edited' ],
			'workflow_run' => [ 'requested', 'in_progress' ],
			'sponsorship' => [ 'cancelled', 'edited', 'tier_changed', 'pending_cancellation', 'pending_tier_change' ],
			'branch_protection_rule' => [ 'edited' ],
			'projects_v2' => [ 'edited' ],
			'projects_v2_status_update' => [ 'edited', 'deleted' ],
		];

		$Fixtures = self::ActionFixtures( );
		$ProvidedData = [];

		foreach( $Events as $Event => $Actions )
		{
			foreach( $Actions as $Action )
			{
				$Payload = self::LoadPayload( $Fixtures[ $Event ] );
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
}
