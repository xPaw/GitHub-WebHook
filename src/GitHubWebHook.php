<?php
declare(strict_types=1);

namespace GitHubWebHook;

use Exception;

class GitHubWebHook
{
	private string $EventType;
	private object $Payload;

	/**
	 * Validates and processes current request.
	 */
	public function ProcessRequest( ) : bool
	{
		if( !array_key_exists( 'HTTP_X_GITHUB_EVENT', $_SERVER ) )
		{
			throw new Exception( 'Missing event header.' );
		}

		$this->EventType = $_SERVER[ 'HTTP_X_GITHUB_EVENT' ];

		if ( preg_match( '/^[a-z0-9_]+$/', $this->EventType ) !== 1 )
		{
			throw new Exception( 'Invalid event header.' );
		}

		if( !array_key_exists( 'REQUEST_METHOD', $_SERVER ) || $_SERVER[ 'REQUEST_METHOD' ] !== 'POST' )
		{
			throw new Exception( 'Invalid request method.' );
		}

		if( !array_key_exists( 'CONTENT_TYPE', $_SERVER ) )
		{
			throw new Exception( 'Missing content type.' );
		}

		$ContentType = $_SERVER[ 'CONTENT_TYPE' ];

		if( $ContentType === 'application/x-www-form-urlencoded' )
		{
			if( !array_key_exists( 'payload', $_POST ) )
			{
				throw new Exception( 'Missing payload.' );
			}

			$RawPayload = $_POST[ 'payload' ];
		}
		else if( $ContentType === 'application/json' )
		{
			$RawPayload = file_get_contents( 'php://input' );
		}
		else
		{
			throw new Exception( 'Unknown content type.' );
		}

		$Decoded = json_decode( $RawPayload );

		if( !is_object( $Decoded ) )
		{
			throw new Exception( 'Failed to decode JSON: ' . json_last_error_msg() );
		}

		$this->Payload = $Decoded;

		if( !isset( $this->Payload->repository ) )
		{
			if( isset( $this->Payload->organization ) )
			{
				// This is a silly hack to handle org-only events
				// Add "/repositories" because repo matching code would expect a "<org>/<repo>" format
				$Owner = $this->Payload->organization->login;
				$Name = 'repositories';
				$Label = 'org: ';
			}
			else if( isset( $this->Payload->sponsorship->sponsorable->login ) )
			{
				// Events of a sponsors listing have neither, they are reported as "<sponsored account>/sponsors"
				$Owner = $this->Payload->sponsorship->sponsorable->login;
				$Name = 'sponsors';
				$Label = 'sponsors: ';
			}
			else if( ( $this->Payload->hook->type ?? null ) === 'SponsorsListing' && isset( $this->Payload->sender->login ) )
			{
				// The ping of a sponsors listing only knows who set the webhook up
				$Owner = $this->Payload->sender->login;
				$Name = 'sponsors';
				$Label = 'sponsors: ';
			}
			else
			{
				throw new Exception( 'Missing repository information.' );
			}

			$this->Payload->repository = (object)[
				'full_name' => $Owner . '/' . $Name,
				'name' => $Label . $Owner,
				'owner' => (object)[
					'name' => $Owner,
					'login' => $Owner,
				],
			];
		}

		return true;
	}

	/**
	 * Optional function to check if HMAC hex digest of the payload matches GitHub's.
	 */
	public function ValidateHubSignature( string $SecretKey ) : bool
	{
		if( !array_key_exists( 'HTTP_X_HUB_SIGNATURE_256', $_SERVER ) )
		{
			throw new Exception( 'Missing X-Hub-Signature-256 header. Did you configure secret token in hook settings?' );
		}

		$Payload = file_get_contents( 'php://input' );

		if( $Payload === false )
		{
			throw new Exception( 'Failed to read php://input.' );
		}

		$KnownAlgo = 'sha256';
		$CalculatedHash = $KnownAlgo . '=' . hash_hmac( $KnownAlgo, $Payload, $SecretKey, false );

		return hash_equals( $CalculatedHash, $_SERVER[ 'HTTP_X_HUB_SIGNATURE_256' ] );
	}

	/**
	 * Returns event type.
	 *
	 * @see https://docs.github.com/en/webhooks/webhook-events-and-payloads
	 */
	public function GetEventType( ) : string
	{
		return $this->EventType;
	}

	/**
	 * Returns decoded payload.
	 */
	public function GetPayload( ) : object
	{
		return $this->Payload;
	}

	/**
	 * Returns full name of the repository.
	 */
	public function GetFullRepositoryName( ) : string
	{
		return $this->Payload->repository->full_name ?? sprintf( '%s/%s', $this->Payload->repository->owner->name, $this->Payload->repository->name );
	}
}
