<?php
declare(strict_types=1);

use GitHubWebHook\DiscordConverter;
use GitHubWebHook\IrcConverter;
use GitHubWebHook\NotImplementedException;

/**
 * Payloads of supported events that can not be formatted.
 */
class UnsupportedPayloadTest extends \PHPUnit\Framework\TestCase
{
	use Fixtures;

	public function testIrcUnknownEvent( ) : void
	{
		$this->expectException( NotImplementedException::class );
		$this->expectExceptionMessage( 'Unsupported event type' );

		$Parser = new IrcConverter( 'surely_this_event_does_not_exist', (object)[] );
		$Parser->GetMessage();
	}

	public function testDiscordUnknownEvent( ) : void
	{
		$this->expectException( NotImplementedException::class );
		$this->expectExceptionMessage( 'Unsupported event type' );

		$Parser = new DiscordConverter( 'surely_this_event_does_not_exist', (object)[] );
		$Parser->GetEmbed();
	}

	public function testIrcPushThatDeletesARef( ) : void
	{
		$Payload = self::LoadPayload( 'push' );
		$Payload->deleted = true;

		$this->expectException( NotImplementedException::class );

		$Parser = new IrcConverter( 'push', $Payload );
		$Parser->GetMessage();
	}

	public function testDiscordPushThatDeletesARef( ) : void
	{
		$Payload = self::LoadPayload( 'push' );
		$Payload->deleted = true;

		$this->expectException( NotImplementedException::class );

		$Parser = new DiscordConverter( 'push', $Payload );
		$Parser->GetEmbed();
	}

	public function testIrcDeleteOfAnUnknownRefType( ) : void
	{
		$Payload = self::LoadPayload( 'delete' );
		$Payload->ref_type = 'repository';

		$this->expectException( NotImplementedException::class );
		$this->expectExceptionMessage( 'Unsupported action type "repository"' );

		$Parser = new IrcConverter( 'delete', $Payload );
		$Parser->GetMessage();
	}

	public function testDiscordDeleteOfAnUnknownRefType( ) : void
	{
		$Payload = self::LoadPayload( 'delete' );
		$Payload->ref_type = 'repository';

		$this->expectException( NotImplementedException::class );
		$this->expectExceptionMessage( 'Unsupported action type "repository"' );

		$Parser = new DiscordConverter( 'delete', $Payload );
		$Parser->GetEmbed();
	}
}
