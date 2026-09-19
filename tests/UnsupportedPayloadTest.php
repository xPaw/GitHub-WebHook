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

	private static function LoadPayload( string $Fixture ) : stdClass
	{
		$Path = __DIR__ . DIRECTORY_SEPARATOR . 'events' . DIRECTORY_SEPARATOR . $Fixture . DIRECTORY_SEPARATOR . 'payload.json';

		$Payload = json_decode( (string)file_get_contents( $Path ), flags: JSON_THROW_ON_ERROR );

		assert( $Payload instanceof stdClass );

		return $Payload;
	}
}
