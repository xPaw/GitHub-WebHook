<?php
declare(strict_types=1);

use GitHubWebHook\DiscordConverter;
use GitHubWebHook\IrcConverter;
use GitHubWebHook\NotImplementedException;
use PHPUnit\Framework\Attributes\DataProvider;

class UnknownActionTest extends \PHPUnit\Framework\TestCase
{
	use Fixtures;

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
		$ProvidedData = [];

		foreach( self::ActionFixtures( ) as $Event => $Fixture )
		{
			$Payload = self::LoadPayload( $Fixture );
			$Payload->action = 'surely_this_action_does_not_exist';

			$ProvidedData[ $Event ] = [ $Event, $Payload ];
		}

		return $ProvidedData;
	}
}
