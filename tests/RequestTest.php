<?php
declare(strict_types=1);

use GitHubWebHook\GitHubWebHook;

/**
 * Stands in for `php://input`, which is always empty on the command line.
 */
class RequestBodyStream
{
	/** Fails to open when there is no body. */
	public static ?string $Body = null;

	/** @var ?resource */
	public $context;

	private int $Position = 0;

	public function stream_open( ) : bool
	{
		return self::$Body !== null;
	}

	public function stream_read( int $Count ) : string
	{
		$Chunk = substr( (string)self::$Body, $this->Position, $Count );

		$this->Position += strlen( $Chunk );

		return $Chunk;
	}

	public function stream_eof( ) : bool
	{
		return $this->Position >= strlen( (string)self::$Body );
	}

	/**
	 * @return array<string, int>
	 */
	public function stream_stat( ) : array
	{
		return [];
	}
}

class RequestTest extends \PHPUnit\Framework\TestCase
{
	private const string PAYLOAD = '{"repository":{"full_name":"octo-org/octo-repo"}}';

	protected function setUp( ) : void
	{
		$_SERVER[ 'HTTP_X_GITHUB_EVENT' ] = 'push';
		$_SERVER[ 'REQUEST_METHOD' ] = 'POST';
		$_SERVER[ 'CONTENT_TYPE' ] = 'application/x-www-form-urlencoded';
		$_POST[ 'payload' ] = self::PAYLOAD;

		unset( $_SERVER[ 'HTTP_X_HUB_SIGNATURE_256' ] );
	}

	public function testMissingEventHeader( ) : void
	{
		unset( $_SERVER[ 'HTTP_X_GITHUB_EVENT' ] );

		$this->expectExceptionMessage( 'Missing event header.' );

		( new GitHubWebHook( ) )->ProcessRequest( );
	}

	public function testInvalidRequestMethod( ) : void
	{
		$_SERVER[ 'REQUEST_METHOD' ] = 'GET';

		$this->expectExceptionMessage( 'Invalid request method.' );

		( new GitHubWebHook( ) )->ProcessRequest( );
	}

	public function testMissingRequestMethod( ) : void
	{
		unset( $_SERVER[ 'REQUEST_METHOD' ] );

		$this->expectExceptionMessage( 'Invalid request method.' );

		( new GitHubWebHook( ) )->ProcessRequest( );
	}

	public function testMissingContentType( ) : void
	{
		unset( $_SERVER[ 'CONTENT_TYPE' ] );

		$this->expectExceptionMessage( 'Missing content type.' );

		( new GitHubWebHook( ) )->ProcessRequest( );
	}

	public function testUnknownContentType( ) : void
	{
		$_SERVER[ 'CONTENT_TYPE' ] = 'text/plain';

		$this->expectExceptionMessage( 'Unknown content type.' );

		( new GitHubWebHook( ) )->ProcessRequest( );
	}

	public function testMissingFormPayload( ) : void
	{
		unset( $_POST[ 'payload' ] );

		$this->expectExceptionMessage( 'Missing payload.' );

		( new GitHubWebHook( ) )->ProcessRequest( );
	}

	public function testPayloadThatIsNotAnObject( ) : void
	{
		$_POST[ 'payload' ] = '[]';

		$this->expectExceptionMessage( 'Failed to decode JSON' );

		( new GitHubWebHook( ) )->ProcessRequest( );
	}

	public function testPayloadWithoutRepositoryOrOrganization( ) : void
	{
		$_POST[ 'payload' ] = '{"zen":"Keep it logically awesome."}';

		$this->expectExceptionMessage( 'Missing repository information.' );

		( new GitHubWebHook( ) )->ProcessRequest( );
	}

	public function testJsonContentTypeReadsTheRequestBody( ) : void
	{
		$_SERVER[ 'CONTENT_TYPE' ] = 'application/json';
		unset( $_POST[ 'payload' ] );

		$Hook = new GitHubWebHook( );

		self::WithRequestBody( self::PAYLOAD, static fn( ) : bool => $Hook->ProcessRequest( ) );

		self::assertSame( 'octo-org/octo-repo', $Hook->GetFullRepositoryName() );
	}

	public function testMissingSignature( ) : void
	{
		$this->expectExceptionMessage( 'Missing X-Hub-Signature-256 header.' );

		( new GitHubWebHook( ) )->ValidateHubSignature( 'secret' );
	}

	public function testSignature( ) : void
	{
		$_SERVER[ 'HTTP_X_HUB_SIGNATURE_256' ] = 'sha256=' . hash_hmac( 'sha256', self::PAYLOAD, 'secret' );

		$Hook = new GitHubWebHook( );

		self::assertTrue( self::WithRequestBody( self::PAYLOAD, static fn( ) : bool => $Hook->ValidateHubSignature( 'secret' ) ) );
		self::assertFalse( self::WithRequestBody( self::PAYLOAD, static fn( ) : bool => $Hook->ValidateHubSignature( 'another secret' ) ) );
		self::assertFalse( self::WithRequestBody( self::PAYLOAD . ' ', static fn( ) : bool => $Hook->ValidateHubSignature( 'secret' ) ) );
	}

	public function testSignatureOfRequestBodyThatCanNotBeRead( ) : void
	{
		$_SERVER[ 'HTTP_X_HUB_SIGNATURE_256' ] = 'sha256=' . hash_hmac( 'sha256', self::PAYLOAD, 'secret' );

		$this->expectExceptionMessage( 'Failed to read php://input.' );

		$Hook = new GitHubWebHook( );

		self::WithRequestBody( null, static fn( ) : bool => $Hook->ValidateHubSignature( 'secret' ) );
	}

	/**
	 * Replaces `php://input` while the callback runs, a body of null makes it fail to open.
	 *
	 * @param callable(): bool $Callback
	 */
	private static function WithRequestBody( ?string $Body, callable $Callback ) : bool
	{
		RequestBodyStream::$Body = $Body;

		stream_wrapper_unregister( 'php' );
		stream_wrapper_register( 'php', RequestBodyStream::class );

		// A stream that fails to open warns about it
		set_error_handler( static fn( ) : bool => true );

		try
		{
			return $Callback( );
		}
		finally
		{
			restore_error_handler( );
			stream_wrapper_restore( 'php' );
		}
	}
}
