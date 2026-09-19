<?php
declare(strict_types=1);

use GitHubWebHook\GitHubWebHook;
use PHPUnit\Framework\Attributes\DataProvider;

class EventHeaderTest extends \PHPUnit\Framework\TestCase
{
	protected function setUp( ) : void
	{
		$_SERVER[ 'REQUEST_METHOD' ] = 'POST';
		$_SERVER[ 'CONTENT_TYPE' ] = 'application/x-www-form-urlencoded';
		$_POST[ 'payload' ] = '{"repository":{"full_name":"monalisa/Hello-World"}}';
	}

	#[DataProvider('validEventProvider')]
	public function testValidEvent( string $Event ) : void
	{
		$_SERVER[ 'HTTP_X_GITHUB_EVENT' ] = $Event;

		$Hook = new GitHubWebHook( );
		$Hook->ProcessRequest( );

		self::assertSame( $Event, $Hook->GetEventType() );
		self::assertSame( 'monalisa/Hello-World', $Hook->GetFullRepositoryName() );
	}

	#[DataProvider('invalidEventProvider')]
	public function testInvalidEvent( string $Event ) : void
	{
		$_SERVER[ 'HTTP_X_GITHUB_EVENT' ] = $Event;

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Invalid event header.' );

		$Hook = new GitHubWebHook( );
		$Hook->ProcessRequest( );
	}

	/**
	 * @return array<array<string>>
	 */
	public static function validEventProvider( ) : array
	{
		return [
			[ 'push' ],
			[ 'pull_request_review_comment' ],
			[ 'projects_v2_item' ],
		];
	}

	/**
	 * @return array<array<string>>
	 */
	public static function invalidEventProvider( ) : array
	{
		return [
			[ '' ],
			[ 'Push' ],
			[ 'push-event' ],
			[ '../push' ],
		];
	}
}
