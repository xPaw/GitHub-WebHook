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
	use Fixtures;

	/**
	 * @param callable(stdClass): void $Change
	 */
	#[DataProvider('payloadProvider')]
	public function testConverters( string $Event, string $Fixture, callable $Change, string $Key, ?string $Expected ) : void
	{
		$Payload = self::LoadPayload( $Fixture );
		$Change( $Payload );
		$Payload = self::ProcessPayload( $Event, json_encode( $Payload, JSON_THROW_ON_ERROR ) )->GetPayload();

		$Card = self::CardOf( ( new DiscordConverter( $Event, $Payload ) )->GetEmbed() );

		self::assertSame( $Expected, $Card[ $Key ] );

		// The irc message has no single part to compare, it only has to come out whole
		$Message = ( new IrcConverter( $Event, $Payload ) )->GetMessage();

		self::assertNotSame( '', $Message );
		self::assertStringNotContainsString( '  ', $Message );
	}

	/**
	 * Converts one fixture, with a change applied to its payload the way GitHub would not.
	 *
	 * @param callable(stdClass): void $Change
	 *
	 * @return array{
	 *     flags: int,
	 *     username?: string,
	 *     avatar_url?: string,
	 *     components: list<array{
	 *         type: int,
	 *         accent_color: int,
	 *         components: list<array{type: int, content: string}>,
	 *     }>,
	 * }
	 */
	private static function MessageFor( string $Event, string $Fixture, callable $Change ) : array
	{
		$Payload = self::LoadPayload( $Fixture );
		$Change( $Payload );
		$Payload = self::ProcessPayload( $Event, json_encode( $Payload, JSON_THROW_ON_ERROR ) )->GetPayload();

		return ( new DiscordConverter( $Event, $Payload ) )->GetEmbed();
	}

	/**
	 * A body keeps the markdown it was written with, except where it would clash with the card.
	 */
	#[DataProvider('bodyProvider')]
	public function testBodyMarkdown( string $Body, string $Expected ) : void
	{
		$Message = self::MessageFor( 'issues', 'issue_opened', static function( stdClass $Payload ) use ( $Body ) : void
		{
			$Payload->issue->body = $Body;
		} );

		self::assertSame( $Expected, self::CardOf( $Message )[ 'description' ] );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function bodyProvider( ) : array
	{
		$Fenced = "```sh\n# a comment, not a heading\n-# not subtext either\n```";
		$Lines = [];

		for( $i = 0; $i < 12; $i++ )
		{
			$Lines[] = "line {$i}";
		}

		return [
			'every level of heading is flattened to bold' => [
				"# One\n### Three\n###### Six",
				"**One**\n**Three**\n**Six**",
			],
			'bold a heading already had is not nested' => [
				'## A **bold** heading',
				'**A bold heading**',
			],
			'a hash that starts no heading is left alone' => [
				"#123 is the issue\n#!/bin/sh",
				"#123 is the issue\n#!/bin/sh",
			],
			'subtext is stripped, a card sets its own scope in it' => [
				"-# a note\nthe body",
				"a note\nthe body",
			],
			'headings and subtext inside fenced code are left alone' => [
				$Fenced,
				$Fenced,
			],
			'a fence is demoted around but not inside' => [
				"## Before\n```\n# inside\n```\n## After",
				"**Before**\n```\n# inside\n```\n**After**",
			],
			'whole lines are kept when the room runs out' => [
				implode( "\n", $Lines ),
				implode( "\n", array_slice( $Lines, 0, 8 ) ) . '…',
			],
			// Eight lines of seventy characters, one of which is given up to the ellipsis
			'a long line counts as the several it wraps into' => [
				str_repeat( 'x', 1000 ),
				str_repeat( 'x', 559 ) . '…',
			],
			'a body that fits is left alone' => [
				'short and **bold**',
				'short and **bold**',
			],
			'backticks in the middle of a line are text, not a fence' => [
				"use ``` in a sentence\n# Heading after it",
				"use ``` in a sentence\n**Heading after it**",
			],
			'a block that was never opened is not closed' => [
				'a line with ``` in it',
				'a line with ``` in it',
			],
			'an indented fence opens a block' => [
				"intro\n  ```\n# not a heading\n  ```\n# a heading",
				"intro\n  ```\n# not a heading\n  ```\n**a heading**",
			],
		];
	}

	/**
	 * Every backslash is escaped into two, so a cut must not land between the halves of a pair.
	 * The two titles differ by one character, which puts the cut on either side of one.
	 */
	#[DataProvider('escapeProvider')]
	public function testCutKeepsAnEscapeWhole( string $Title ) : void
	{
		$Message = self::MessageFor( 'issues', 'issue_opened', static function( stdClass $Payload ) use ( $Title ) : void
		{
			$Payload->issue->title = $Title;
		} );

		$Heading = $Message[ 'components' ][ 0 ][ 'components' ][ 0 ][ 'content' ];

		self::assertStringEndsWith( '…', $Heading );
		self::assertSame( 1, preg_match( '/(\\\\*)…$/', $Heading, $Match ) );
		self::assertSame( 0, strlen( $Match[ 1 ] ) % 2, 'half of an escaped backslash was left behind' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function escapeProvider( ) : array
	{
		return [
			'a cut through a pair' => [ str_repeat( '\\', 2500 ) ],
			'a cut between two pairs' => [ 'a' . str_repeat( '\\', 2500 ) ],
		];
	}

	/**
	 * Discord will not take a username holding "discord" or "clyde", and a message can not then
	 * be sent as that sender. The card names them either way, so nothing is lost when it is not.
	 */
	#[DataProvider('senderProvider')]
	public function testSenderIsNamedWhetherOrNotDiscordTakesTheName( string $Login, ?string $Username ) : void
	{
		$Message = self::MessageFor( 'issues', 'issue_opened', static function( stdClass $Payload ) use ( $Login ) : void
		{
			$Payload->sender->login = $Login;
		} );

		self::assertSame( $Username, $Message[ 'username' ] ?? null );
		self::assertSame(
			$Login . " opened issue **#508**: Something isn't rendering correctly",
			self::CardOf( $Message )[ 'title' ]
		);
	}

	/**
	 * @return array<string, array{string, ?string}>
	 */
	public static function senderProvider( ) : array
	{
		return [
			'a name Discord takes' => [ 'monalisa', 'monalisa on GitHub' ],
			'a name holding discord' => [ 'discordapp', null ],
			'a name holding clyde in another case' => [ 'ClydeBot', null ],
			// "cannot be everyone" is an exact match, which the suffix is enough to get past
			'a name Discord reserves on its own' => [ 'everyone', 'everyone on GitHub' ],
		];
	}

	/**
	 * GitHub always sends an avatar, and its own url for one stands in if it ever does not.
	 */
	public function testSenderWithoutAnAvatar( ) : void
	{
		$Message = self::MessageFor( 'issues', 'issue_opened', static function( stdClass $Payload ) : void
		{
			unset( $Payload->sender->avatar_url );
		} );

		self::assertSame( 'https://github.com/monalisa.png', $Message[ 'avatar_url' ] ?? null );
	}

	/**
	 * A cut inside a fenced block has to close it again, or it swallows the rest of the card.
	 */
	public function testCutFenceIsClosed( ) : void
	{
		$Message = self::MessageFor( 'issues', 'issue_opened', static function( stdClass $Payload ) : void
		{
			$Payload->issue->body = "```\n" . str_repeat( "a line of code\n", 30 ) . '```';
		} );

		$Body = self::CardOf( $Message )[ 'description' ] ?? '';

		self::assertStringStartsWith( "```\n", $Body );
		self::assertStringEndsWith( "\n```", $Body );

		// The one it opens with, the one it was cut before, and the one added back
		self::assertCount( 3, explode( '```', $Body ) );
	}

	/**
	 * Takes a card apart again, so that a test can assert on one piece of it.
	 *
	 * @param array{components: list<array{components: list<array{content: string}>}>} $Message
	 *
	 * @return array{title: string, url: ?string, description: ?string}
	 */
	private static function CardOf( array $Message ) : array
	{
		$Contents = array_column( $Message[ 'components' ][ 0 ][ 'components' ], 'content' );
		$Lines = explode( "\n", array_shift( $Contents ) ?? '' );

		$Card = [
			// The scope is on a line of its own above the title, when the event has one
			'title' => substr( array_pop( $Lines ), strlen( '### ' ) ),
			'url' => null,
			'description' => null,
		];

		if( preg_match( '~^\[(.*)]\((.*)\)$~s', $Card[ 'title' ], $Match ) === 1 )
		{
			$Card[ 'title' ] = $Match[ 1 ];
			$Card[ 'url' ] = $Match[ 2 ];
		}

		// The labels come before the body, and are the only one of the two set as subtext
		if( $Contents !== [] && str_starts_with( $Contents[ 0 ], '-# ' ) )
		{
			array_shift( $Contents );
		}

		if( $Contents !== [] )
		{
			$Card[ 'description' ] = $Contents[ 0 ];
		}

		return $Card;
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
				'title', 'monalisa added **ghost** as a collaborator',
			],
			'deleted comment from a deleted user' => [
				'issue_comment', 'issue_comment_delete',
				static function( stdClass $Payload ) : void { $Payload->comment->user = null; },
				'title', 'monalisa deleted comment in PR **#502** from **ghost**',
			],
			'ping without zen' => [
				'ping', 'ping',
				static function( stdClass $Payload ) : void { unset( $Payload->zen ); },
				'description', null,
			],
			'ping without the hook object' => [
				'ping', 'ping',
				static function( stdClass $Payload ) : void { unset( $Payload->hook ); },
				'title', 'monalisa set up hook **7292732** — it works!',
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
				'title', 'github created Secret scanning alert **#3**: unknown',
			],
			'push protection bypassed by a deleted user' => [
				'secret_scanning_alert', 'secret_scanning_alert_created_bypassed',
				static function( stdClass $Payload ) : void { $Payload->alert->push_protection_bypassed_by = new stdClass; },
				'description', 'Push protection bypassed by **ghost**',
			],
			'branch with a backtick in its name' => [
				'delete', 'delete_branch',
				static function( stdClass $Payload ) : void { $Payload->ref = 'weird`branch'; },
				'title', 'Codertocat deleted branch `` weird`branch ``',
			],
			'branch with two backticks in a row in its name' => [
				'delete', 'delete_branch',
				static function( stdClass $Payload ) : void { $Payload->ref = 'weird``branch'; },
				'title', 'Codertocat deleted branch ``` weird``branch ```',
			],
			'package without a version' => [
				'registry_package', 'registry_package',
				static function( stdClass $Payload ) : void { $Payload->registry_package->package_version->version = ''; },
				'title', 'Codertocat published npm package: **hello-world-npm**',
			],
			'ruleset of an organization has no page of its own' => [
				'repository_ruleset', 'repository_ruleset_created',
				static function( stdClass $Payload ) : void { $Payload->repository_ruleset->_links->html = null; },
				'url', null,
			],
			'membership event without a member' => [
				'membership', 'membership_added',
				static function( stdClass $Payload ) : void { $Payload->member = null; },
				'title', 'Codertocat added **ghost** to team **github**',
			],
			'blocked user that was deleted' => [
				'org_block', 'org_block_blocked',
				static function( stdClass $Payload ) : void { $Payload->blocked_user = null; },
				'title', 'Codertocat blocked user **ghost**',
			],
			'organization member that was deleted' => [
				'organization', 'organization_member_added',
				static function( stdClass $Payload ) : void { $Payload->membership->user = null; },
				'title', 'Codertocat added **ghost** (member) to the organization',
			],
			'organization invitation by email does not reveal the address' => [
				'organization', 'organization_member_invited',
				static function( stdClass $Payload ) : void
				{
					unset( $Payload->user );
					$Payload->invitation->login = null;
					$Payload->invitation->email = 'hacktocat@example.com';
				},
				'title', 'Codertocat invited someone by email (member) to the organization',
			],
			'organization invitation that reinstates someone has no role to name' => [
				'organization', 'organization_member_invited',
				static function( stdClass $Payload ) : void { $Payload->invitation->role = 'reinstate'; },
				'title', 'Codertocat invited **hacktocat** to the organization',
			],
			'team without a privacy' => [
				'team', 'team_edited_privacy',
				static function( stdClass $Payload ) : void { unset( $Payload->team->privacy ); },
				'title', 'Codertocat changed the privacy of team **github** to **unknown**',
			],
			'answered discussion without the body of the answer' => [
				'discussion', 'discussion_answered',
				static function( stdClass $Payload ) : void { $Payload->answer->body = null; },
				'description', null,
			],
			'sponsorship without a sponsor is announced as a private one' => [
				'sponsorship', 'sponsorship_created',
				static function( stdClass $Payload ) : void { $Payload->sponsorship->sponsor = null; },
				'title', 'octocat got a new private sponsor',
			],
			'workflow run escapes the message of its commit once' => [
				'workflow_run', 'workflow_run_failed',
				static function( stdClass $Payload ) : void { $Payload->workflow_run->head_commit->message = 'fix_bug'; },
				'description', 'fix\_bug',
			],
			'workflow run without a name is named after its workflow' => [
				'workflow_run', 'workflow_run_failed',
				static function( stdClass $Payload ) : void { $Payload->workflow_run->name = ''; $Payload->workflow->name = 'Tests'; },
				'title', 'Codertocat broke `master` — workflow **Tests** failed',
			],
			'workflow run without any name' => [
				'workflow_run', 'workflow_run_failed',
				static function( stdClass $Payload ) : void { $Payload->workflow_run->name = null; $Payload->workflow = null; },
				'title', 'Codertocat broke `master` — workflow **unknown** failed',
			],
			'project without a body' => [
				'project', 'project',
				static function( stdClass $Payload ) : void { $Payload->project->body = null; },
				'description', null,
			],
		];
	}
}
