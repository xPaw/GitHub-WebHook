<?php
	Header( 'Content-Type: text/plain; charset=utf-8' );
	
	// Don't do this in production!
	ini_set( 'error_reporting', -1 );
	ini_set( 'display_errors', 1 );
	
	http_response_code( 500 );
	
	/* types */
	define("GITHUB_T", 1 << 0);

	/* you ain't gon' configure this, foo' */
	define("IRKER_HOST", "127.0.0.1");
	define("IRKER_PORT", 6659);

	/* load the config */
	require __DIR__ . '/gitmek-rcv_config.php';
	
	require __DIR__ . '/GitHub_WebHook.php';
	require __DIR__ . '/GitHub_IRC.php';
	
	$Socket = false;
	$Hook = new GitHub_WebHook( );
	
	try
	{
		$Hook->ProcessRequest( );
		
		// This check is optional, you can implement some secret GET param for example
		if( !$Hook->ValidateIPAddress() )
		{
			http_response_code( 401 );
			
			exit;
		}
		
		$RepositoryName = $Hook->GetFullRepositoryName();
		
		// Check if we have a send target
		if( !isset( $sendto[ GITHUB_T ][ $RepositoryName ] ) )
		{
			throw new Exception( 'No send target for this repository.' );
		}
		
		echo 'Received ' . $Hook->GetEventType() . ' in repository ' . $RepositoryName . PHP_EOL;
		//print_r( $Hook->GetPayload() );
		
		// Format IRC message
		$IRC = new GitHub_IRC( $Hook->GetEventType(), $Hook->GetPayload(), 'shorten_url' );
		$Message = $IRC->GetMessage();
		
		// Format irker payload
		$IrkerPayload = '';
		
		foreach( $sendto[ GITHUB_T ][ $RepositoryName ] as $Target )
		{
			$IrkerPayload .= json_encode( Array(
				'to'      => $Target,
				'privmsg' => $Message
			) ) . "\n";
		}
		
		// Send to irker
		$Socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
		
		if( $Socket === false
		|| socket_connect( $Socket, IRKER_HOST, IRKER_PORT ) === false
		|| socket_write( $Socket, $IrkerPayload ) === false )
		{
			throw new Exception( 'Socket error: ' . socket_strerror( socket_last_error() ) );
		}
		
		echo 'Payload sent to irker' . PHP_EOL . $IrkerPayload . PHP_EOL;
		
		http_response_code( 202 );
	}
	catch( Exception $e )
	{
		echo 'Exception: ' . $e->getMessage() . PHP_EOL;
	}
	
	// Cleanup
	if( $Socket !== false )
	{
		socket_close( $Socket );
	}
	
	// Taken from @meklu's gitmek-rcv
	function shorten_url($url) {
		$opts = array(
			"http" => array(
				"header" => "Content-type: application/x-www-form-urlencoded\r\n",
				"method" => "POST",
				"content" => http_build_query(array("url" => $url)),
			),
		);
		$ctx = stream_context_create($opts);
		$stream = @fopen("http://git.io", "r", false, $ctx);
		if ($stream === false) {
			/* damn it */
			return $url;
		}
		$md = stream_get_meta_data($stream);
		fclose($stream);
		$headers = $md["wrapper_data"];
		foreach($headers as $header) {
			$key = "Location: ";
			if (strpos($header, $key) === 0) {
				return substr($header, strlen($key));
			}
		}
		/* when all else fails, be stupid */
		return $url;
	}
