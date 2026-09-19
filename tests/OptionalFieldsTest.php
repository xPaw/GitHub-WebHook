<?php
declare(strict_types=1);

use GitHubWebHook\DiscordConverter;
use GitHubWebHook\GitHubWebHook;
use GitHubWebHook\IrcConverter;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Payloads with fields that GitHub leaves out or sets to null, such as users that were deleted.
 */
class OptionalFieldsTest extends \PHPUnit\Framework\TestCase
{
	/**
	 * @param callable(stdClass): void $Change
	 */
	#[DataProvider('payloadProvider')]
	public function testConverters( string $Event, string $Fixture, callable $Change, string $Key, ?string $Expected ) : void
	{
		$Payload = self::LoadPayload( $Fixture );
		$Change( $Payload );
		$Payload = self::ProcessPayload( $Event, $Payload );

		$Embed = ( new DiscordConverter( $Event, $Payload ) )->GetEmbed();

		self::assertIsArray( $Embed[ 'embeds' ] );
		self::assertIsArray( $Embed[ 'embeds' ][ 0 ] );
		self::assertSame( $Expected, $Embed[ 'embeds' ][ 0 ][ $Key ] ?? null );

		// The irc message has no single part to compare, it only has to come out whole
		$Message = ( new IrcConverter( $Event, $Payload ) )->GetMessage();

		self::assertNotSame( '', $Message );
		self::assertStringNotContainsString( '  ', $Message );
	}

	/**
	 * @return array<string, array{string, string, callable(stdClass): void, string, ?string}>
	 */
	public static function payloadProvider( ) : array
	{
		return [
			'merged pull request from a deleted user' => [
				'pull_request', 'pull_request_closed_merged',
				static function( stdClass $Payload ) : void { $Payload->pull_request->user = null; },
				'description', 'Merged from **ghost** to `master`',
			],
			'member event without a member' => [
				'member', 'member',
				static function( stdClass $Payload ) : void { $Payload->member = null; },
				'title', 'added **ghost** as a collaborator',
			],
			'deleted comment from a deleted user' => [
				'issue_comment', 'issue_comment_delete',
				static function( stdClass $Payload ) : void { $Payload->comment->user = null; },
				'title', 'deleted comment in PR **#502** from **ghost**',
			],
			'ping without zen' => [
				'ping', 'ping',
				static function( stdClass $Payload ) : void { unset( $Payload->zen ); },
				'description', null,
			],
			'ping without the hook object' => [
				'ping', 'ping',
				static function( stdClass $Payload ) : void { unset( $Payload->hook ); },
				'title', 'Hook 7292732 worked!',
			],
			'answered discussion without an answer' => [
				'discussion', 'discussion_answered',
				static function( stdClass $Payload ) : void { unset( $Payload->answer ); },
				'url', 'https://github.com/octo-org/octo-repo/discussions/90',
			],
			'only an answered discussion links to the answer' => [
				'discussion', 'discussion_answered',
				static function( stdClass $Payload ) : void { $Payload->action = 'locked'; },
				'url', 'https://github.com/octo-org/octo-repo/discussions/90',
			],
			'resolved secret scanning alert with an empty resolution' => [
				'secret_scanning_alert', 'secret_scanning_alert_resolved',
				static function( stdClass $Payload ) : void { $Payload->alert->resolution = ''; },
				'description', null,
			],
			'code scanning alert without a severity' => [
				'code_scanning_alert', 'code_scanning_alert_created',
				static function( stdClass $Payload ) : void { $Payload->alert->rule->severity = null; },
				'description', 'Potential XSS vulnerability in the $.fn.position plugin.',
			],
			'secret scanning alert without a secret type' => [
				'secret_scanning_alert', 'secret_scanning_alert_created',
				static function( stdClass $Payload ) : void { unset( $Payload->alert->secret_type, $Payload->alert->secret_type_display_name ); },
				'title', '⚠ Secret scanning alert **#3** created: unknown',
			],
			'push protection bypassed by a deleted user' => [
				'secret_scanning_alert', 'secret_scanning_alert_created_bypassed',
				static function( stdClass $Payload ) : void { $Payload->alert->push_protection_bypassed_by = new stdClass; },
				'description', 'Push protection bypassed by **ghost**',
			],
			'branch with a backtick in its name' => [
				'delete', 'delete_branch',
				static function( stdClass $Payload ) : void { $Payload->ref = 'weird`branch'; },
				'title', 'deleted branch `` weird`branch ``',
			],
			'package without a version' => [
				'registry_package', 'registry_package',
				static function( stdClass $Payload ) : void { $Payload->registry_package->package_version->version = ''; },
				'title', 'published npm package: **hello-world-npm**',
			],
			'ruleset of an organization has no page of its own' => [
				'repository_ruleset', 'repository_ruleset_created',
				static function( stdClass $Payload ) : void { $Payload->repository_ruleset->_links->html = null; },
				'url', null,
			],
			'membership event without a member' => [
				'membership', 'membership_added',
				static function( stdClass $Payload ) : void { $Payload->member = null; },
				'title', 'added **ghost** to team **github**',
			],
			'blocked user that was deleted' => [
				'org_block', 'org_block_blocked',
				static function( stdClass $Payload ) : void { $Payload->blocked_user = null; },
				'title', 'blocked user **ghost**',
			],
			'organization member that was deleted' => [
				'organization', 'organization_member_added',
				static function( stdClass $Payload ) : void { $Payload->membership->user = null; },
				'title', 'added **ghost** (member) to the organization',
			],
			'organization invitation by email does not reveal the address' => [
				'organization', 'organization_member_invited',
				static function( stdClass $Payload ) : void
				{
					unset( $Payload->user );
					$Payload->invitation->login = null;
					$Payload->invitation->email = 'hacktocat@example.com';
				},
				'title', 'invited someone by email (member) to the organization',
			],
			'organization invitation that reinstates someone has no role to name' => [
				'organization', 'organization_member_invited',
				static function( stdClass $Payload ) : void { $Payload->invitation->role = 'reinstate'; },
				'title', 'invited **hacktocat** to the organization',
			],
			'team without a privacy' => [
				'team', 'team_edited_privacy',
				static function( stdClass $Payload ) : void { unset( $Payload->team->privacy ); },
				'title', 'changed the privacy of team **github** to **unknown**',
			],
			'answered discussion without the body of the answer' => [
				'discussion', 'discussion_answered',
				static function( stdClass $Payload ) : void { $Payload->answer->body = null; },
				'description', null,
			],
			'sponsorship without a sponsor is announced as a private one' => [
				'sponsorship', 'sponsorship_created',
				static function( stdClass $Payload ) : void { $Payload->sponsorship->sponsor = null; },
				'title', 'got a new private sponsor',
			],
			'workflow run escapes the message of its commit once' => [
				'workflow_run', 'workflow_run_failed',
				static function( stdClass $Payload ) : void { $Payload->workflow_run->head_commit->message = 'fix_bug'; },
				'description', 'fix\_bug',
			],
			'workflow run without a name is named after its workflow' => [
				'workflow_run', 'workflow_run_failed',
				static function( stdClass $Payload ) : void { $Payload->workflow_run->name = ''; $Payload->workflow->name = 'Tests'; },
				'title', 'workflow **Tests** failed on `master`',
			],
			'workflow run without any name' => [
				'workflow_run', 'workflow_run_failed',
				static function( stdClass $Payload ) : void { $Payload->workflow_run->name = null; $Payload->workflow = null; },
				'title', 'workflow **unknown** failed on `master`',
			],
			'project without a body' => [
				'project', 'project',
				static function( stdClass $Payload ) : void { $Payload->project->body = null; },
				'description', null,
			],
		];
	}

	/**
	 * Events of an organization have no repository until the request is processed.
	 */
	private static function ProcessPayload( string $Event, stdClass $Payload ) : object
	{
		$_SERVER[ 'HTTP_X_GITHUB_EVENT' ] = $Event;
		$_SERVER[ 'REQUEST_METHOD' ] = 'POST';
		$_SERVER[ 'CONTENT_TYPE' ] = 'application/x-www-form-urlencoded';
		$_POST[ 'payload' ] = json_encode( $Payload, JSON_THROW_ON_ERROR );

		$Hook = new GitHubWebHook( );
		$Hook->ProcessRequest( );

		return $Hook->GetPayload();
	}

	private static function LoadPayload( string $Fixture ) : stdClass
	{
		$Path = __DIR__ . DIRECTORY_SEPARATOR . 'events' . DIRECTORY_SEPARATOR . $Fixture . DIRECTORY_SEPARATOR . 'payload.json';

		$Payload = json_decode( (string)file_get_contents( $Path ), flags: JSON_THROW_ON_ERROR );

		assert( $Payload instanceof stdClass );

		return $Payload;
	}
}
