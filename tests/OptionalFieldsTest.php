<?php
declare(strict_types=1);

use GitHubWebHook\DiscordConverter;
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
			'code scanning alert without a severity or a tool' => [
				'code_scanning_alert', 'code_scanning_alert_created',
				static function( stdClass $Payload ) : void { $Payload->alert->rule->severity = null; $Payload->alert->tool = null; },
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
		];
	}

	private static function LoadPayload( string $Fixture ) : stdClass
	{
		$Path = __DIR__ . DIRECTORY_SEPARATOR . 'events' . DIRECTORY_SEPARATOR . $Fixture . DIRECTORY_SEPARATOR . 'payload.json';

		$Payload = json_decode( (string)file_get_contents( $Path ), flags: JSON_THROW_ON_ERROR );

		assert( $Payload instanceof stdClass );

		return $Payload;
	}
}
