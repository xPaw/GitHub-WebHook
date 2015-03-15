<?php
	class GitHub_WebHook
	{
		/**
		 * GitHub's IP mask
		 *
		 * Get it from https://api.github.com/meta
		 */
		const GITHUB_IP_BASE = '192.30.252.0';
		//const GITHUB_IP_BITS = 22;
		const GITHUB_IP_MASK = -1024; // ( pow( 2, self :: GITHUB_IP_BITS ) - 1 ) << ( 32 - self :: GITHUB_IP_BITS )
		
		private $EventType;
		private $Payload;
		private $RawPayload;
		
		/**
		 * Validates and processes current request
		 *
		 */
		public function ProcessRequest( )
		{
			if( !array_key_exists( 'HTTP_X_GITHUB_EVENT', $_SERVER ) )
			{
				throw new Exception( 'Missing X-GitHub-Event header.' );
			}
			
			if( !array_key_exists( 'REQUEST_METHOD', $_SERVER ) || $_SERVER[ 'REQUEST_METHOD' ] !== 'POST' )
			{
				throw new Exception( 'Invalid request method.' );
			}
			
			if( !array_key_exists( 'CONTENT_TYPE', $_SERVER ) )
			{
				throw new Exception( 'Missing content type.' );
			}
			
			$this->EventType = filter_input( INPUT_SERVER, 'HTTP_X_GITHUB_EVENT', FILTER_SANITIZE_STRING );
			
			$ContentType = $_SERVER[ 'CONTENT_TYPE' ];
			
			if( $ContentType === 'application/x-www-form-urlencoded' )
			{
				if( !array_key_exists( 'payload', $_POST ) )
				{
					throw new Exception( 'Missing payload.' );
				}
				
				$this->RawPayload = filter_input( INPUT_POST, 'payload' );
			}
			else if( $ContentType === 'application/json' )
			{
				$this->RawPayload = file_get_contents( 'php://input' );
			}
			else
			{
				throw new Exception( 'Unknown content type.' );
			}
			
			$this->Payload = json_decode( $this->RawPayload );
			
			if( $this->Payload === null )
			{
				throw new Exception( 'Failed to decode JSON: ' .
					function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : json_last_error()
				);
			}
			
			if( !isset( $this->Payload->repository ) && $this->EventType !== 'ping' ) // Ping event only has 'hook' info
			{
				throw new Exception( 'Missing repository information.' );
			}
			
			return true;
		}
		
		/**
		 * Optional function to check if request came from GitHub's IP range.
		 *
		 * @return bool
		 */
		public function ValidateIPAddress( )
		{
			if( !array_key_exists( 'REMOTE_ADDR', $_SERVER ) )
			{
				throw new Exception( 'Missing remote address.' );
			}
			
			$Remote = ip2long( $_SERVER[ 'REMOTE_ADDR' ] );
			$Base   = ip2long( self :: GITHUB_IP_BASE );
			
			return ( $Base & self :: GITHUB_IP_MASK ) === ( $Remote & self :: GITHUB_IP_MASK );
		}
		
		/**
		 * Optional function to check if HMAC hex digest of the payload matches GitHub's.
		 *
		 * @return bool
		 */
		public function ValidateHubSignature( $SecretKey )
		{
			if( !array_key_exists( 'HTTP_X_HUB_SIGNATURE', $_SERVER ) )
			{
				throw new Exception( 'Missing X-Hub-Signature header. Did you configure secret token in hook settings?' );
			}
			
			return 'sha1=' . hash_hmac( 'sha1', $this->RawPayload, $SecretKey, false ) === $_SERVER[ 'HTTP_X_HUB_SIGNATURE' ];
		}
		
		/**
		 * Returns event type
		 * See https://developer.github.com/webhooks/#events
		 *
		 * @return string
		 */
		public function GetEventType( )
		{
			return $this->EventType;
		}
		
		/**
		 * Returns decoded payload
		 *
		 * @return array
		 */
		public function GetPayload( )
		{
			return $this->Payload;
		}
		
		/**
		 * Returns full name of the repository
		 *
		 * @return string
		 */
		public function GetFullRepositoryName( )
		{
			if( isset( $this->Payload->repository->full_name ) )
			{
				return $this->Payload->repository->full_name;
			}
			
			return sprintf( '%s/%s', $this->Payload->repository->owner->name, $this->Payload->repository->name );
		}
	}
