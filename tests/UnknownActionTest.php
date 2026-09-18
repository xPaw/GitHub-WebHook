<?php
declare(strict_types=1);

use GitHubWebHook\DiscordConverter;
use GitHubWebHook\IrcConverter;
use GitHubWebHook\NotImplementedException;
use PHPUnit\Framework\Attributes\DataProvider;

class UnknownActionTest extends \PHPUnit\Framework\TestCase
{
	#[DataProvider('eventProvider')]
	public function testIrcUnknownAction( string $Event, object $Payload ) : void
	{
		$this->expectException( NotImplementedException::class );
		$this->expectExceptionMessage( 'Unsupported action type "surely_this_action_does_not_exist"' );

		$Parser = new IrcConverter( $Event, $Payload );
		$Parser->GetMessage();
	}

	#[DataProvider('eventProvider')]
	public function testDiscordUnknownAction( string $Event, object $Payload ) : void
	{
		$this->expectException( NotImplementedException::class );
		$this->expectExceptionMessage( 'Unsupported action type "surely_this_action_does_not_exist"' );

		$Parser = new DiscordConverter( $Event, $Payload );
		$Parser->GetEmbed();
	}

	/**
	 * @return array<string, array{string, object}>
	 */
	public static function eventProvider( ) : array
	{
		// ping, push, public, delete and gollum have no action
		$Events =
		[
			'issues' => 'issue_opened',
			'pull_request' => 'pull_request_merged',
			'milestone' => 'milestone',
			'package' => 'package',
			'release' => 'release',
			'commit_comment' => 'commit_comment',
			'issue_comment' => 'issue_comment',
			'pull_request_review' => 'pull_request_review',
			'pull_request_review_comment' => 'pull_request_review_comment',
			'discussion' => 'discussion_created',
			'discussion_comment' => 'discussion_comment_created',
			'repository_advisory' => 'repository_advisory_published',
			'dependabot_alert' => 'dependabot_alert_created',
			'code_scanning_alert' => 'code_scanning_alert_created',
			'secret_scanning_alert' => 'secret_scanning_alert_created',
			'member' => 'member',
			'repository' => 'repository',
		];

		$ProvidedData = [];

		foreach( $Events as $Event => $Fixture )
		{
			$Path = __DIR__ . DIRECTORY_SEPARATOR . 'events' . DIRECTORY_SEPARATOR . $Fixture . DIRECTORY_SEPARATOR . 'payload.json';

			$Payload = json_decode( (string)file_get_contents( $Path ), flags: JSON_THROW_ON_ERROR );

			assert( $Payload instanceof stdClass );

			$Payload->action = 'surely_this_action_does_not_exist';

			$ProvidedData[ $Event ] = [ $Event, $Payload ];
		}

		return $ProvidedData;
	}
}
