<?php
declare(strict_types=1);

use GitHubWebHook\DiscordConverter;
use GitHubWebHook\IgnoredEventException;
use GitHubWebHook\IrcConverter;
use PHPUnit\Framework\Attributes\DataProvider;

class IgnoredEventTest extends \PHPUnit\Framework\TestCase
{
	#[DataProvider('ignoredEventProvider')]
	public function testIrcThrow( string $Event ) : void
	{
		$this->expectException( IgnoredEventException::class );

		$Parser = new IrcConverter( $Event, (object)[] );
		$Parser->GetMessage();
	}

	#[DataProvider('ignoredEventProvider')]
	public function testDiscordThrow( string $Event ) : void
	{
		$this->expectException( IgnoredEventException::class );

		$Parser = new DiscordConverter( $Event, (object)[] );
		$Parser->GetEmbed();
	}

	/**
	 * @return array<array<string>>
	 */
	public static function ignoredEventProvider( ) : array
	{
		return [
			[ 'create' ],
			[ 'fork' ],
			[ 'watch' ],
			[ 'star' ],
			[ 'status' ],
		];
	}
}
